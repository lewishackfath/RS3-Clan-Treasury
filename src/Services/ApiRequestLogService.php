<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;
use Treasury\Support\Env;

final class ApiRequestLogService
{
    public function record(array $data): void
    {
        if (!Env::bool('API_REQUEST_LOG_ENABLED', true)) {
            return;
        }

        $maxBodyBytes = max(0, (int)Env::get('API_REQUEST_LOG_BODY_MAX_BYTES', '20000'));

        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_api_request_logs (
                request_uuid,
                app_id,
                api_key_id,
                method,
                path,
                query_string,
                status_code,
                duration_ms,
                idempotency_key,
                ip_address,
                user_agent,
                request_body,
                response_body,
                error_message,
                created_at
             ) VALUES (
                :request_uuid,
                :app_id,
                :api_key_id,
                :method,
                :path,
                :query_string,
                :status_code,
                :duration_ms,
                :idempotency_key,
                :ip_address,
                :user_agent,
                :request_body,
                :response_body,
                :error_message,
                UTC_TIMESTAMP()
             )'
        );

        $stmt->execute([
            'request_uuid' => $data['request_uuid'] ?? $this->uuid(),
            'app_id' => $data['app_id'] ?? null,
            'api_key_id' => $data['api_key_id'] ?? null,
            'method' => $this->clip((string)($data['method'] ?? ''), 10),
            'path' => $this->clip((string)($data['path'] ?? ''), 255),
            'query_string' => $this->nullableClip((string)($data['query_string'] ?? ''), 500),
            'status_code' => $data['status_code'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'idempotency_key' => $this->nullableClip((string)($data['idempotency_key'] ?? ''), 180),
            'ip_address' => $this->nullableClip((string)($data['ip_address'] ?? ''), 45),
            'user_agent' => $this->nullableClip((string)($data['user_agent'] ?? ''), 255),
            'request_body' => $this->clipBody((string)($data['request_body'] ?? ''), $maxBodyBytes),
            'response_body' => $this->clipBody((string)($data['response_body'] ?? ''), $maxBodyBytes),
            'error_message' => $this->nullableClip((string)($data['error_message'] ?? ''), 255),
        ]);
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        $appId = (int)($filters['app_id'] ?? 0);
        if ($appId > 0) {
            $where[] = 'l.app_id = :app_id';
            $params['app_id'] = $appId;
        }

        $statusFamily = trim((string)($filters['status_family'] ?? ''));
        if ($statusFamily !== '') {
            if ($statusFamily === '2xx') {
                $where[] = 'l.status_code BETWEEN 200 AND 299';
            } elseif ($statusFamily === '4xx') {
                $where[] = 'l.status_code BETWEEN 400 AND 499';
            } elseif ($statusFamily === '5xx') {
                $where[] = 'l.status_code BETWEEN 500 AND 599';
            }
        }

        $method = strtoupper(trim((string)($filters['method'] ?? '')));
        if ($method !== '') {
            $where[] = 'l.method = :method';
            $params['method'] = $method;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.path LIKE :q OR l.error_message LIKE :q OR l.request_uuid LIKE :q OR a.name LIKE :q OR k.key_name LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $sql = 'SELECT l.*, a.name AS app_name, a.slug AS app_slug, k.key_name
                FROM treasury_api_request_logs l
                LEFT JOIN treasury_apps a ON a.id = l.app_id
                LEFT JOIN treasury_api_keys k ON k.id = l.api_key_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT 200';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.*, a.name AS app_name, a.slug AS app_slug, k.key_name
             FROM treasury_api_request_logs l
             LEFT JOIN treasury_apps a ON a.id = l.app_id
             LEFT JOIN treasury_api_keys k ON k.id = l.api_key_id
             WHERE l.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('API log entry not found', 404);
        }
        return $row;
    }

    public function summary(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) AS client_error_count,
                SUM(CASE WHEN status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_count,
                MAX(created_at) AS last_request_at
             FROM treasury_api_request_logs
             WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'success_count' => (int)($row['success_count'] ?? 0),
            'client_error_count' => (int)($row['client_error_count'] ?? 0),
            'server_error_count' => (int)($row['server_error_count'] ?? 0),
            'last_request_at' => $row['last_request_at'] ?? null,
        ];
    }

    private function clipBody(string $body, int $maxBytes): ?string
    {
        if ($body === '') {
            return null;
        }
        if ($maxBytes <= 0) {
            return null;
        }
        if (strlen($body) <= $maxBytes) {
            return $body;
        }
        return substr($body, 0, $maxBytes) . "\n\n[truncated]";
    }

    private function nullableClip(string $value, int $length): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return $this->clip($value, $length);
    }

    private function clip(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        return substr($value, 0, $length);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
