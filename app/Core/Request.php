<?php
declare(strict_types=1);

namespace App\Core;

/** Accès aux entrées HTTP, toujours sous forme de chaînes nettoyées. */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = base_path();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        return rawurldecode($uri === '' ? '/' : $uri);
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        if (!is_string($v)) {
            return $default;
        }
        // Retire les caractères de contrôle (sauf retours à la ligne)
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
    }

    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function int(string $key, ?int $default = null): ?int
    {
        $v = self::str($key);
        return ($v !== '' && preg_match('/^-?\d+$/', $v)) ? (int) $v : $default;
    }

    public static function ids(string $key): array
    {
        $v = $_POST[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $v), fn ($i) => $i > 0)));
    }

    public static function bool(string $key): bool
    {
        return in_array(self::str($key), ['1', 'on', 'yes', 'true'], true);
    }

    public static function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        return is_array($f) && !is_array($f['name'] ?? null) ? $f : null;
    }

    public static function all(): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $_POST);
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
