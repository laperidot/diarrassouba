<?php
declare(strict_types=1);

namespace App\Core;

/** Authentification, limitation des tentatives et contrôle des permissions côté serveur. */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if ($id) {
                $u = Db::get()->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
                // Invalide la session si le mot de passe a changé ailleurs.
                if ($u && hash_equals((string) ($_SESSION['pwv'] ?? ''), self::pwVersion($u))) {
                    self::$user = $u;
                } else {
                    unset($_SESSION['uid'], $_SESSION['pwv']);
                }
            }
        }
        return self::$user;
    }

    /** Pour les tests et la CLI. */
    public static function actAs(?array $user): void
    {
        self::$user = $user;
        self::$loaded = true;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function role(): string
    {
        return self::user()['role_code'] ?? '';
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        return $u !== null && Domain::roleCan($u['role_code'], $permission);
    }

    public static function require(string $permission): void
    {
        if (self::user() === null) {
            throw new HttpException(401);
        }
        if (!self::can($permission)) {
            Audit::log('access.denied', null, null, ['permission' => $permission]);
            throw new HttpException(403);
        }
    }

    private static function pwVersion(array $u): string
    {
        return substr(hash('sha256', $u['password_hash'] . '|' . $u['id']), 0, 16);
    }

    public static function hash(string $password): string
    {
        return defined('PASSWORD_ARGON2ID') ? password_hash($password, PASSWORD_ARGON2ID) : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function passwordProblems(string $password): array
    {
        $p = [];
        if (mb_strlen($password) < 10) {
            $p[] = 'au moins 10 caractères';
        }
        if (!preg_match('/\d/', $password) || !preg_match('/\p{L}/u', $password)) {
            $p[] = 'des lettres et au moins un chiffre';
        }
        return $p;
    }

    /**
     * Tentative de connexion. Bloque après N échecs en 15 minutes
     * par identifiant et par adresse IP. Message identique quel que soit le motif.
     */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $db = Db::get();
        $email = mb_strtolower(trim($email));
        $keyHash = hash_hmac('sha256', $email, self::secret());
        $ipHash = hash_hmac('sha256', $ip, self::secret());
        $since = gmdate('Y-m-d H:i:s', time() - 15 * 60);
        $max = Config::int('LOGIN_MAX_ATTEMPTS', 5);

        $failsUser = (int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE key_hash = ? AND success = 0 AND created_at >= ?', [$keyHash, $since]);
        $failsIp = (int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND success = 0 AND created_at >= ?', [$ipHash, $since]);
        if ($failsUser >= $max || $failsIp >= $max * 4) {
            return ['ok' => false, 'locked' => true];
        }

        $u = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $valid = $u && (int) $u['is_active'] === 1 && password_verify($password, $u['password_hash']);
        if (!$u) {
            // Vérification factice : même durée de réponse que pour un compte existant.
            password_verify($password, '$2y$12$12nYsJOdmCFOt/U49FV3xum.aHc62TwNeArQ3q7ET2s0u6zKOZ1Ae');
        }
        $db->insert('login_attempts', ['key_hash' => $keyHash, 'ip_hash' => $ipHash, 'success' => $valid ? 1 : 0, 'created_at' => Db::now()]);

        if (!$valid) {
            if ($u) {
                Audit::log('auth.login_failed', 'user', (int) $u['id'], [], (int) $u['id']);
            }
            return ['ok' => false, 'locked' => false];
        }
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $u['password_hash'] = self::hash($password);
            $db->update('users', ['password_hash' => $u['password_hash']], 'id = ?', [$u['id']]);
        }
        $db->update('users', ['last_login_at' => Db::now()], 'id = ?', [$u['id']]);
        return ['ok' => true, 'user' => $u];
    }

    public static function login(array $u): void
    {
        Session::regenerate();
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['pwv'] = self::pwVersion($u);
        self::$user = $u;
        self::$loaded = true;
        Audit::log('auth.login', 'user', (int) $u['id']);
    }

    public static function refreshSessionVersion(array $u): void
    {
        $_SESSION['pwv'] = self::pwVersion($u);
    }

    public static function logout(): void
    {
        if (self::user()) {
            Audit::log('auth.logout', 'user', self::id());
        }
        Session::destroy();
        self::$user = null;
    }

    public static function secret(): string
    {
        $k = Config::get('APP_KEY', '');
        if (strlen((string) $k) < 32) {
            throw new \RuntimeException('APP_KEY manquante ou trop courte dans .env (32 caractères minimum).');
        }
        return $k;
    }
}
