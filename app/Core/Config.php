<?php
declare(strict_types=1);

namespace App\Core;

/** Lecture du fichier .env (format CLE=valeur) sans dépendance externe. */
final class Config
{
    private static array $values = [];

    public static function load(string $file): void
    {
        self::$values = [];
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                self::$values[trim($k)] = $v;
            }
        }
        // Les variables d'environnement réelles priment.
        foreach (self::$values as $k => $_) {
            $env = getenv($k);
            if ($env !== false) {
                self::$values[$k] = $env;
            }
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = getenv($key);
        return $env === false ? $default : $env;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default): bool
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
