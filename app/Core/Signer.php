<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Liens de partage signés et à durée limitée (factures, reçus) :
 * /partage/{type}/{id}/{expiration}/{signature}
 * Le lien ne donne accès qu'au PDF, en lecture seule, jusqu'à l'expiration.
 */
final class Signer
{
    public static function shareUrl(string $type, int $id, ?int $ttlHours = null): string
    {
        $ttlHours ??= Config::int('SHARE_LINK_TTL_HOURS', 72);
        $exp = time() + $ttlHours * 3600;
        return absolute_url(sprintf('partage/%s/%d/%d/%s', $type, $id, $exp, self::sign($type, $id, $exp)));
    }

    public static function sign(string $type, int $id, int $exp): string
    {
        $raw = hash_hmac('sha256', "$type|$id|$exp", Auth::secret(), true);
        return rtrim(strtr(base64_encode(substr($raw, 0, 18)), '+/', '-_'), '=');
    }

    public static function verify(string $type, int $id, int $exp, string $sig): bool
    {
        return $exp >= time() && hash_equals(self::sign($type, $id, $exp), $sig);
    }
}
