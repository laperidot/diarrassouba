<?php
declare(strict_types=1);

namespace App\Core;

/** Numérotation continue (factures, reçus, dossiers, clients) protégée par transaction. */
final class Sequence
{
    public static function next(string $name): int
    {
        $db = Db::get();
        return $db->tx(function (Db $db) use ($name) {
            $row = $db->one('SELECT value FROM sequences WHERE name = ?' . $db->forUpdate(), [$name]);
            if ($row === null) {
                $db->insert('sequences', ['name' => $name, 'value' => 1]);
                return 1;
            }
            $v = (int) $row['value'] + 1;
            $db->update('sequences', ['value' => $v], 'name = ?', [$name]);
            return $v;
        });
    }

    /** Ex. : FAC-2026-00042 (séquence annuelle). */
    public static function yearly(string $prefix, int $pad = 5): string
    {
        $year = gmdate('Y');
        $n = self::next($prefix . '-' . $year);
        return sprintf('%s-%s-%0' . $pad . 'd', $prefix, $year, $n);
    }
}
