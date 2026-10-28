<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Journal des opérations sensibles. Les détails ne doivent contenir aucune donnée
 * personnelle inutile (pas de téléphone, pas d'adresse) : identifiants et montants seulement.
 */
final class Audit
{
    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $userId = null): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            Db::get()->insert('audit_logs', [
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip_hash' => substr(hash_hmac('sha256', $ip, Config::get('APP_KEY', 'x')), 0, 16),
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            error_log('Audit impossible : ' . $e->getMessage());
        }
    }
}
