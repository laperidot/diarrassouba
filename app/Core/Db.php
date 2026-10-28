<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Accès aux données : requêtes préparées uniquement.
 * Pilotes : mysql (production) et sqlite (tests / développement local).
 */
final class Db
{
    private static ?Db $instance = null;
    public readonly PDO $pdo;
    public readonly string $driver;
    private int $txDepth = 0;

    private function __construct(PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public static function get(): Db
    {
        if (self::$instance === null) {
            self::$instance = self::connectFromConfig();
        }
        return self::$instance;
    }

    public static function reset(?Db $db = null): void
    {
        self::$instance = $db;
    }

    public static function connectFromConfig(): Db
    {
        $driver = Config::get('DB_DRIVER', 'mysql');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($driver === 'sqlite') {
            $path = Config::get('DB_SQLITE_PATH', 'storage/database.sqlite');
            // Chemin absolu : /… (Unix), C:\… ou C:/… (Windows), \\serveur\… (UNC)
            if ($path !== ':memory:' && !preg_match('#^(/|\\\\|[A-Za-z]:[\\\\/])#', $path)) {
                $path = APP_ROOT . '/' . $path;
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return new Db($pdo, 'sqlite');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::int('DB_PORT', 3306),
            Config::get('DB_NAME', 'diarrassouba')
        );
        // MySQL hébergé (TiDB Cloud, Aiven, PlanetScale…) : connexion chiffrée avec le certificat indiqué
        $ca = Config::get('DB_SSL_CA', '');
        if ($ca !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''), $options);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        return new Db($pdo, 'mysql');
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($params) === $params ? $params : [] as $i => $v) {
            $stmt->bindValue($i + 1, $v, self::type($v));
        }
        if (array_values($params) !== $params) {
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim((string) $k, ':'), $v, self::type($v));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    private static function type(mixed $v): int
    {
        return match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(fn ($c) => self::ident($c) . ' = ?', array_keys($data)));
        $stmt = $this->run(
            sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), $sets, $where),
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    /** Transaction imbriquable (seule la plus externe est réelle). */
    public function tx(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->driver === 'sqlite' ? $this->pdo->exec('BEGIN IMMEDIATE') : $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->driver === 'sqlite' ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->driver === 'sqlite' ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Verrou de ligne (MySQL). SQLite verrouille déjà la base avec BEGIN IMMEDIATE. */
    public function forUpdate(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    public static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException('Identifiant SQL invalide');
        }
        return $name;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Motif LIKE échappé ; à utiliser avec « LIKE ? ESCAPE '!' » (compatible MySQL et SQLite). */
    public static function like(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    public static function isUniqueViolation(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return $e instanceof \PDOException
            && (str_contains($msg, 'UNIQUE constraint') || str_contains($msg, 'Duplicate entry') || ($e->errorInfo[1] ?? null) === 1062);
    }
}
