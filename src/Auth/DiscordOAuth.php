<?php

declare(strict_types=1);

namespace Treasury\Auth;

use Treasury\Services\AdminService;
use Treasury\Support\Env;

final class DiscordOAuth
{
    private const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';
    private const TOKEN_URL = 'https://discord.com/api/oauth2/token';
    private const API_BASE = 'https://discord.com/api';

    public static function enabled(): bool
    {
        return Env::bool('DISCORD_OAUTH_ENABLED', false);
    }

    public function authorizationUrl(): string
    {
        $this->assertConfigured();

        $state = bin2hex(random_bytes(32));
        $_SESSION['discord_oauth_state'] = $state;

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => Env::get('DISCORD_CLIENT_ID', ''),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(array $query): array
    {
        $this->assertConfigured();

        if (!empty($query['error'])) {
            $description = trim((string)($query['error_description'] ?? $query['error']));
            throw new \RuntimeException('Discord sign-in was cancelled or denied: ' . $description);
        }

        $expectedState = (string)($_SESSION['discord_oauth_state'] ?? '');
        unset($_SESSION['discord_oauth_state']);

        $providedState = (string)($query['state'] ?? '');
        if ($expectedState === '' || $providedState === '' || !hash_equals($expectedState, $providedState)) {
            throw new \RuntimeException('Discord sign-in state was invalid. Please try again.');
        }

        $code = trim((string)($query['code'] ?? ''));
        if ($code === '') {
            throw new \RuntimeException('Discord did not return an authorisation code.');
        }

        $token = $this->exchangeCodeForToken($code);
        $accessToken = (string)($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new \RuntimeException('Discord did not return an access token.');
        }

        $user = $this->apiGet('/users/@me', $accessToken);
        $member = null;
        $guildId = trim((string)Env::get('DISCORD_GUILD_ID', ''));
        if ($guildId !== '') {
            try {
                $member = $this->apiGet('/users/@me/guilds/' . rawurlencode($guildId) . '/member', $accessToken);
            } catch (\Throwable $e) {
                if (Env::bool('DISCORD_REQUIRE_GUILD_MEMBER', false) || $this->configuredAdminRoleIds() !== []) {
                    throw new \RuntimeException('Your Discord account is not a member of the configured server, or the app lacks guild member access.');
                }
            }
        }

        $authorisation = $this->authoriseUser($user, $member);

        return [
            'user' => $user,
            'member' => $member,
            'authorisation' => $authorisation,
        ];
    }

    private function assertConfigured(): void
    {
        if (!self::enabled()) {
            throw new \RuntimeException('Discord OAuth is not enabled.');
        }

        foreach (['DISCORD_CLIENT_ID', 'DISCORD_CLIENT_SECRET', 'DISCORD_REDIRECT_URI'] as $key) {
            if (trim((string)Env::get($key, '')) === '') {
                throw new \RuntimeException("{$key} is required when Discord OAuth is enabled.");
            }
        }
    }

    private function redirectUri(): string
    {
        return trim((string)Env::get('DISCORD_REDIRECT_URI', ''));
    }

    private function scopes(): array
    {
        $scopes = ['identify'];

        if (trim((string)Env::get('DISCORD_GUILD_ID', '')) !== '') {
            $scopes[] = 'guilds.members.read';
        }

        return array_values(array_unique($scopes));
    }

    private function exchangeCodeForToken(string $code): array
    {
        return $this->httpJson('POST', self::TOKEN_URL, [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => Env::get('DISCORD_CLIENT_ID', ''),
            'client_secret' => Env::get('DISCORD_CLIENT_SECRET', ''),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ], '', '&', PHP_QUERY_RFC3986));
    }

    private function apiGet(string $path, string $accessToken): array
    {
        return $this->httpJson('GET', self::API_BASE . $path, [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    private function httpJson(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $headers[] = 'Accept: application/json';
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
        if (!is_array($decoded)) {
            throw new \RuntimeException('Discord returned an unexpected response.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string)($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? 'Discord request failed.');
            throw new \RuntimeException($message);
        }

        return $decoded;
    }

    private function authoriseUser(array $user, ?array $member): array
    {
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') {
            throw new \RuntimeException('Discord did not return a user ID.');
        }

        if (in_array($userId, $this->configuredUserIds('DISCORD_OWNER_USER_IDS'), true)) {
            return ['allowed' => true, 'method' => 'owner_user_id'];
        }

        $matchedAdmin = (new AdminService())->findByDiscordUserId($userId);
        if ($matchedAdmin && Env::bool('DISCORD_ALLOW_LINKED_TREASURY_ADMINS', true)) {
            return ['allowed' => true, 'method' => 'linked_treasury_admin', 'admin' => $matchedAdmin];
        }

        $requiredRoleIds = $this->configuredAdminRoleIds();
        if ($requiredRoleIds !== []) {
            $memberRoles = $member['roles'] ?? [];
            if (is_array($memberRoles) && array_intersect($requiredRoleIds, array_map('strval', $memberRoles)) !== []) {
                return ['allowed' => true, 'method' => 'discord_role'];
            }
        }

        throw new \RuntimeException('Your Discord account is not authorised to manage the treasury. Link your Discord user ID to a treasury admin, add an allowed owner user ID, or configure an allowed Discord role.');
    }

    private function configuredAdminRoleIds(): array
    {
        return $this->configuredUserIds('DISCORD_ADMIN_ROLE_IDS');
    }

    private function configuredUserIds(string $key): array
    {
        $value = (string)Env::get($key, '');
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            explode(',', $value)
        ), static fn(string $part): bool => $part !== ''));
    }
}
