<?php
declare(strict_types=1);

namespace App\Core;

/** Session PHP durcie + messages flash + jeton CSRF. */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('di_sess');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (Config::int('SESSION_IDLE_MINUTES', 120) * 60));
        if (Config::get('SESSION_DRIVER', 'files') === 'database') {
            session_set_save_handler(new DbSessionHandler(), true);
        } else {
            $savePath = storage_path('sessions');
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0770, true);
            }
            if (is_writable($savePath)) {
                session_save_path($savePath);
            }
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path() === '' ? '/' : base_path() . '/',
            'secure' => Config::bool('SESSION_SECURE', $https),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Expiration après inactivité
        $idle = Config::int('SESSION_IDLE_MINUTES', 120) * 60;
        if (isset($_SESSION['_last']) && time() - $_SESSION['_last'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_flash'][] = ['info', 'Votre session a expiré. Reconnectez-vous.'];
        }
        $_SESSION['_last'] = time();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = [$type, $message];
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function setOld(array $input): void
    {
        unset($input['_csrf'], $input['password'], $input['password_confirm'], $input['current_password']);
        $_SESSION['_old'] = $input;
    }

    public static function setErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    /** À appeler après le rendu : les anciennes saisies ne vivent qu'une requête. */
    public static function clearOld(): void
    {
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }

    public static function errors(): array
    {
        return $_SESSION['_errors'] ?? [];
    }
}
