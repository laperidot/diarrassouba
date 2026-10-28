<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessions enregistrées dans la base (SESSION_DRIVER=database).
 * Indispensable quand plusieurs instances servent l'application sans disque partagé (Vercel).
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return (string) (Db::get()->value('SELECT payload FROM sessions WHERE id = ? AND expires_at > ?', [$id, time()]) ?? '');
    }

    public function write(string $id, string $data): bool
    {
        $db = Db::get();
        $expires = time() + (int) ini_get('session.gc_maxlifetime');
        $db->tx(function () use ($db, $id, $data, $expires): void {
            $db->run('DELETE FROM sessions WHERE id = ?', [$id]);
            $db->insert('sessions', ['id' => $id, 'payload' => $data, 'expires_at' => $expires]);
        });
        return true;
    }

    public function destroy(string $id): bool
    {
        Db::get()->run('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Db::get()->run('DELETE FROM sessions WHERE expires_at <= ?', [time()])->rowCount();
    }

    /** Mode strict : un identifiant inconnu est refusé (PHP en génère un nouveau). */
    public function validateId(string $id): bool
    {
        return Db::get()->value('SELECT 1 FROM sessions WHERE id = ? AND expires_at > ?', [$id, time()]) !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
