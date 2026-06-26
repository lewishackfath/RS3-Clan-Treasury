<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Auth\ApiContext;
use Treasury\Database;

final class IdempotencyService
{
    public function getExisting(ApiContext $context, string $key, string $requestHash): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT response_code, response_body, request_hash
             FROM treasury_idempotency_keys
             WHERE app_id = :app_id AND idempotency_key = :idempotency_key
             LIMIT 1'
        );
        $stmt->execute([
            'app_id' => $context->appId,
            'idempotency_key' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if (!hash_equals((string)$row['request_hash'], $requestHash)) {
            throw new \RuntimeException('Idempotency key was already used for a different request', 409);
        }

        return [
            'response_code' => (int)$row['response_code'],
            'response_body' => json_decode((string)$row['response_body'], true) ?: [],
        ];
    }

    public function save(ApiContext $context, string $key, string $requestHash, int $responseCode, array $responseBody): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_idempotency_keys
             (app_id, idempotency_key, request_hash, response_code, response_body)
             VALUES
             (:app_id, :idempotency_key, :request_hash, :response_code, :response_body)'
        );
        $stmt->execute([
            'app_id' => $context->appId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'response_code' => $responseCode,
            'response_body' => json_encode($responseBody, JSON_UNESCAPED_SLASHES),
        ]);
    }
}
