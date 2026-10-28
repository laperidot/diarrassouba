<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Applique les fichiers database/migrations/NNN_*.sql non encore appliqués.
 * Ne supprime jamais rien : chaque migration ne fait qu'ajouter.
 */
final class Migrator
{
    public function __construct(private Db $db)
    {
    }

    public function pending(): array
    {
        $this->ensureTable();
        $done = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        return array_values(array_filter($files, fn ($f) => !in_array(basename($f, '.sql'), $done, true)));
    }

    public function migrate(): array
    {
        $applied = [];
        foreach ($this->pending() as $file) {
            $sql = file_get_contents($file);
            if ($this->db->driver === 'sqlite') {
                $sql = self::toSqlite($sql);
            }
            foreach (self::split($sql) as $stmt) {
                $this->db->pdo->exec($stmt);
            }
            $this->db->insert('schema_migrations', ['version' => basename($file, '.sql'), 'applied_at' => Db::now()]);
            $applied[] = basename($file);
        }
        return $applied;
    }

    private function ensureTable(): void
    {
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)');
    }

    /** Découpe un script SQL en instructions (ignore les commentaires « -- »). */
    public static function split(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), fn ($l) => !str_starts_with(ltrim($l), '--'));
        $out = [];
        $buf = '';
        $inStr = false;
        $text = implode("\n", $lines);
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === "'") {
                $inStr = !$inStr;
            }
            if ($c === ';' && !$inStr) {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    /** Adaptation du dialecte MySQL vers SQLite. */
    public static function toSqlite(string $sql): string
    {
        $sql = preg_replace('/(BIG)?INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/\s+UNSIGNED/i', '', $sql);
        $sql = preg_replace('/\)\s*ENGINE=InnoDB[^;]*;/i', ');', $sql);
        $sql = preg_replace('/\s+COLLATE\s+\w+/i', '', $sql);
        return $sql;
    }
}
