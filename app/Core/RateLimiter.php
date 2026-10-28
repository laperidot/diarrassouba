<?php
declare(strict_types=1);

namespace App\Core;

/** Limiteur à fenêtre glissante stocké en base (fonctionne sur hébergement mutualisé). */
final class RateLimiter
{
    /** Retourne true si l'action est autorisée (et l'enregistre). */
    public static function hit(string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        $db = Db::get();
        $hash = hash_hmac('sha256', $key, Config::get('APP_KEY', 'x'));
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int) $db->value('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND key_hash = ? AND created_at >= ?', [$bucket, $hash, $since]);
        if ($count >= $max) {
            return false;
        }
        $db->insert('rate_limits', ['bucket' => $bucket, 'key_hash' => $hash, 'created_at' => Db::now()]);
        // Nettoyage opportuniste (1 fois sur 50)
        if (random_int(1, 50) === 1) {
            $db->run('DELETE FROM rate_limits WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
            $db->run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 30)]);
        }
        return true;
    }
}
