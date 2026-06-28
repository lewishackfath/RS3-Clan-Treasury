<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;
use Treasury\Support\Env;
use Treasury\Support\GP;

final class DiscordBotService
{
    private const API_BASE = 'https://discord.com/api/v10';
    private const SETTING_TRANSACTION_LOG_CHANNEL = 'discord.transaction_log_channel_id';

    public function botConfigured(): bool
    {
        return $this->botToken() !== '';
    }

    public function configuredGuildId(): string
    {
        return trim((string)Env::get('DISCORD_GUILD_ID', ''));
    }

    public function transactionLogChannelId(): string
    {
        $setting = trim((string)(new SettingService())->get(self::SETTING_TRANSACTION_LOG_CHANNEL, ''));
        if ($setting !== '') {
            return $setting;
        }

        return trim((string)Env::get('DISCORD_TREASURY_LOG_CHANNEL_ID', ''));
    }

    public function setTransactionLogChannelId(?string $channelId, int $actorAdminId): void
    {
        $channelId = trim((string)$channelId);
        if ($channelId !== '' && !preg_match('/^\d{5,32}$/', $channelId)) {
            throw new \InvalidArgumentException('Discord channel ID is invalid.');
        }

        (new SettingService())->set(self::SETTING_TRANSACTION_LOG_CHANNEL, $channelId === '' ? null : $channelId, $actorAdminId);
    }

    public function guildSummary(): array
    {
        $guildId = $this->configuredGuildId();
        $roleIds = $this->configuredRoleIds();
        $summary = [
            'bot_configured' => $this->botConfigured(),
            'guild_id' => $guildId,
            'guild_name' => null,
            'role_ids' => $roleIds,
            'roles' => [],
            'error' => null,
        ];

        if ($guildId === '' || !$this->botConfigured()) {
            return $summary;
        }

        try {
            $guild = $this->apiGet('/guilds/' . rawurlencode($guildId));
            $summary['guild_name'] = (string)($guild['name'] ?? '');

            $roles = $this->apiGet('/guilds/' . rawurlencode($guildId) . '/roles');
            $roleNames = [];
            foreach (is_array($roles) ? $roles : [] as $role) {
                $id = (string)($role['id'] ?? '');
                if ($id !== '') {
                    $roleNames[$id] = (string)($role['name'] ?? $id);
                }
            }

            foreach ($roleIds as $roleId) {
                $summary['roles'][] = [
                    'id' => $roleId,
                    'name' => $roleNames[$roleId] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        return $summary;
    }

    public function textChannels(): array
    {
        $guildId = $this->configuredGuildId();
        if ($guildId === '') {
            throw new \RuntimeException('DISCORD_GUILD_ID is not configured.');
        }
        if (!$this->botConfigured()) {
            throw new \RuntimeException('DISCORD_BOT_TOKEN is not configured.');
        }

        $channels = $this->apiGet('/guilds/' . rawurlencode($guildId) . '/channels');
        $rows = [];
        foreach (is_array($channels) ? $channels : [] as $channel) {
            $type = (int)($channel['type'] ?? -1);
            if (!in_array($type, [0, 5], true)) { // 0 = text, 5 = announcement
                continue;
            }
            $rows[] = [
                'id' => (string)($channel['id'] ?? ''),
                'name' => (string)($channel['name'] ?? 'unnamed-channel'),
                'type' => $type,
                'parent_id' => $channel['parent_id'] ?? null,
                'position' => (int)($channel['position'] ?? 0),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['position'], $a['name']] <=> [$b['position'], $b['name']];
        });

        return $rows;
    }

    public function notifyOfficialTreasuryMovement(int $transactionId): void
    {
        if (!Env::bool('DISCORD_TREASURY_LOG_ENABLED', true)) {
            return;
        }

        $channelId = $this->transactionLogChannelId();
        if ($channelId === '' || !$this->botConfigured()) {
            return;
        }

        try {
            $payload = $this->treasuryMovementPayload($transactionId);
            if ($payload === null) {
                return;
            }

            $this->apiPost('/channels/' . rawurlencode($channelId) . '/messages', $payload);
        } catch (\Throwable $e) {
            error_log('Treasury Discord log failed for transaction ' . $transactionId . ': ' . $e->getMessage());
        }
    }

    private function treasuryMovementPayload(int $transactionId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, posted.display_name AS posted_by_display_name, posted.rsn AS posted_by_rsn
             FROM treasury_transactions t
             LEFT JOIN treasury_admins posted ON posted.id = t.posted_by_admin_id
             WHERE t.id = :id AND t.status = "posted"
             LIMIT 1'
        );
        $stmt->execute(['id' => $transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) {
            return null;
        }

        $lineStmt = Database::pdo()->prepare(
            'SELECT le.*, a.code AS account_code, a.name AS account_name, a.normal_balance,
                    admin.display_name AS admin_display_name, admin.rsn AS admin_rsn
             FROM treasury_ledger_entries le
             JOIN treasury_accounts a ON a.id = le.account_id
             LEFT JOIN treasury_admins admin ON admin.id = le.admin_id
             WHERE le.transaction_id = :transaction_id
             ORDER BY le.id ASC'
        );
        $lineStmt->execute(['transaction_id' => $transactionId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        $officialLine = null;
        foreach ($lines as $line) {
            if ((string)$line['account_code'] === '1000') {
                $officialLine = $line;
                break;
            }
        }

        if (!$officialLine) {
            return null;
        }

        $isIn = (string)$officialLine['direction'] === 'debit';
        $amount = (int)$officialLine['amount'];
        $title = $isIn ? 'GP moved into official treasury' : 'GP moved out of official treasury';
        $colour = $isIn ? 0x4f7136 : 0x9e3d24;
        $postedBy = trim((string)($transaction['posted_by_display_name'] ?? '')) ?: (string)($transaction['posted_by_rsn'] ?? '—');
        $counterpart = $this->counterpartSummary($lines, (int)$officialLine['id']);

        $fields = [
            ['name' => 'Amount', 'value' => GP::format($amount), 'inline' => true],
            ['name' => 'Direction', 'value' => $isIn ? 'Into treasury' : 'Out of treasury', 'inline' => true],
            ['name' => 'Type', 'value' => ucfirst(str_replace('_', ' ', (string)$transaction['transaction_type'])), 'inline' => true],
            ['name' => 'Recorded by', 'value' => $postedBy !== '' ? $postedBy : 'API / system', 'inline' => true],
            ['name' => 'Counter account', 'value' => $counterpart, 'inline' => true],
            ['name' => 'Transaction UUID', 'value' => '`' . (string)$transaction['transaction_uuid'] . '`', 'inline' => false],
        ];

        $sourceType = trim((string)($transaction['source_type'] ?? ''));
        $sourceId = trim((string)($transaction['source_id'] ?? ''));
        if ($sourceType !== '' || $sourceId !== '') {
            $fields[] = ['name' => 'Source', 'value' => trim($sourceType . ' / ' . $sourceId, ' /'), 'inline' => false];
        }

        return [
            'content' => '',
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => $title,
                'description' => (string)$transaction['description'],
                'color' => $colour,
                'fields' => $fields,
                'timestamp' => (new \DateTimeImmutable((string)$transaction['occurred_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'footer' => ['text' => 'RS3 GP Treasury'],
            ]],
        ];
    }

    private function counterpartSummary(array $lines, int $officialLineId): string
    {
        foreach ($lines as $line) {
            if ((int)$line['id'] === $officialLineId) {
                continue;
            }
            return (string)$line['account_code'] . ' ' . (string)$line['account_name'];
        }
        return '—';
    }

    /** @return list<string> */
    private function configuredRoleIds(): array
    {
        $value = trim((string)Env::get('DISCORD_ADMIN_ROLE_IDS', ''));
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            explode(',', $value)
        ), static fn(string $part): bool => $part !== ''));
    }

    private function botToken(): string
    {
        return trim((string)(Env::get('DISCORD_BOT_TOKEN', '') ?: Env::get('DISCORD_TOKEN', '')));
    }

    private function apiGet(string $path): array
    {
        return $this->httpJson('GET', self::API_BASE . $path);
    }

    private function apiPost(string $path, array $payload): array
    {
        return $this->httpJson('POST', self::API_BASE . $path, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function httpJson(string $method, string $url, ?string $body = null): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bot ' . $this->botToken(),
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }

        if ($raw === false) {
            throw new \RuntimeException('Unable to contact Discord. Check outbound HTTPS access from the server.');
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? (string)($decoded['message'] ?? $decoded['error'] ?? 'Discord request failed.')
                : 'Discord request failed.';
            throw new \RuntimeException($message . ' HTTP ' . $status);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
