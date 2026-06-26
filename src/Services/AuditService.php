<?php

declare(strict_types=1);

namespace Treasury\Services;

use Treasury\Auth\ApiContext;
use Treasury\Database;

final class AuditService
{
    public static function log(
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?ApiContext $context = null,
        ?int $adminId = null
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_audit_log
             (actor_admin_id, actor_app_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent)
             VALUES
             (:actor_admin_id, :actor_app_id, :action, :entity_type, :entity_id, :before_json, :after_json, :ip_address, :user_agent)'
        );

        $stmt->execute([
            'actor_admin_id' => $adminId,
            'actor_app_id' => $context?->appId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    }
}
