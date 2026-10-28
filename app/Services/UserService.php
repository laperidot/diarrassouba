<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Db;
use App\Core\Domain;
use App\Core\ValidationException;
use App\Core\Validator;

/** Comptes utilisateurs et grille tarifaire (administration). */
final class UserService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    public function all(): array
    {
        return $this->db->all('SELECT id, name, email, phone, role_code, is_active, last_login_at, created_at FROM users ORDER BY is_active DESC, name');
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Utilisateur introuvable.');
    }

    public function save(?int $id, array $in): int
    {
        $data = [
            'name' => trim((string) ($in['name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($in['email'] ?? ''))),
            'phone' => trim((string) ($in['phone'] ?? '')) ?: null,
            'role_code' => (string) ($in['role_code'] ?? ''),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        $password = (string) ($in['password'] ?? '');
        $v = (new Validator())
            ->required('name', $data['name'], 'Le nom')
            ->required('email', $data['email'], 'L’e-mail')
            ->email('email', $data['email'])
            ->phone('phone', $data['phone'])
            ->in('role_code', $data['role_code'], array_keys(Domain::ROLES), 'Rôle');
        if ($id === null && $password === '') {
            $v->add('password', 'Définissez un mot de passe provisoire.');
        }
        if ($password !== '' && ($pb = Auth::passwordProblems($password))) {
            $v->add('password', 'Mot de passe : ' . implode(', ', $pb) . '.');
        }
        $dup = $this->db->value('SELECT id FROM users WHERE email = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$data['email'], $id] : [$data['email']]);
        if ($dup) {
            $v->add('email', 'Cet e-mail est déjà utilisé.');
        }
        if ($id !== null && $id === Auth::id() && ($data['role_code'] !== 'admin' || !$data['is_active'])) {
            $v->add('role_code', 'Vous ne pouvez pas retirer vos propres droits d’administrateur.');
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if ($password !== '') {
            $data['password_hash'] = Auth::hash($password);
            $data['must_change_password'] = 1; // mot de passe provisoire défini par un administrateur
        }
        $data['updated_at'] = Db::now();
        if ($id === null) {
            $data['created_at'] = Db::now();
            $id = $this->db->insert('users', $data);
            Audit::log('user.created', 'user', $id, ['role' => $data['role_code']]);
        } else {
            $before = $this->get($id);
            $this->db->update('users', $data, 'id = ?', [$id]);
            Audit::log('user.updated', 'user', $id, [
                'role' => $before['role_code'] !== $data['role_code'] ? [$before['role_code'], $data['role_code']] : null,
                'active' => (int) $before['is_active'] !== $data['is_active'] ? $data['is_active'] : null,
                'password_reset' => $password !== '',
            ]);
        }
        return $id;
    }

    public function changeOwnPassword(int $userId, string $current, string $new, string $confirm): void
    {
        $u = $this->get($userId);
        $errors = [];
        if (!password_verify($current, $u['password_hash'])) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        }
        if ($pb = Auth::passwordProblems($new)) {
            $errors['password'] = 'Le nouveau mot de passe doit contenir ' . implode(' et ', $pb) . '.';
        }
        if ($new !== $confirm) {
            $errors['password_confirm'] = 'Les deux saisies ne correspondent pas.';
        }
        if ($new === $current) {
            $errors['password'] = 'Choisissez un mot de passe différent de l’actuel.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $hash = Auth::hash($new);
        $this->db->update('users', ['password_hash' => $hash, 'must_change_password' => 0, 'updated_at' => Db::now()], 'id = ?', [$userId]);
        Audit::log('user.password_changed', 'user', $userId);
        Auth::refreshSessionVersion($this->get($userId));
    }

    // ---------------- Tarifs ----------------
    public function tariffs(): array
    {
        return $this->db->all('SELECT * FROM tariffs ORDER BY is_active DESC, origin_country, destination_country, name');
    }

    public function saveTariff(?int $id, array $in): int
    {
        $currency = (string) ($in['currency'] ?? 'XOF');
        $data = [
            'name' => trim((string) ($in['name'] ?? '')),
            'origin_country' => (string) ($in['origin_country'] ?? ''),
            'destination_country' => (string) ($in['destination_country'] ?? ''),
            'currency' => $currency,
            'rate_per_kg_minor' => \App\Core\Money::parse($in['rate'] ?? '', $currency),
            'min_billable_g' => \App\Core\Money::parseKg(($in['min_kg'] ?? '') === '' ? '0' : $in['min_kg']),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        $v = (new Validator())
            ->required('name', $data['name'], 'Le nom du tarif')
            ->in('origin_country', $data['origin_country'], array_keys(Domain::COUNTRIES), 'Départ')
            ->in('destination_country', $data['destination_country'], array_keys(Domain::COUNTRIES), 'Destination')
            ->in('currency', $currency, array_keys(Domain::CURRENCIES), 'Devise')
            ->positive('rate', $data['rate_per_kg_minor'], 'Le tarif par kg');
        if ($data['min_billable_g'] === null) {
            $v->add('min_kg', 'Minimum facturable invalide.');
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data['updated_at'] = Db::now();
        if ($id) {
            // Une modification de tarif n'affecte pas les colis déjà enregistrés (tarif copié dans le colis).
            $this->db->update('tariffs', $data, 'id = ?', [$id]);
            Audit::log('tariff.updated', 'tariff', $id, ['rate' => $data['rate_per_kg_minor'], 'currency' => $currency]);
            return $id;
        }
        $data['created_by'] = Auth::id();
        $data['created_at'] = Db::now();
        $id = $this->db->insert('tariffs', $data);
        Audit::log('tariff.created', 'tariff', $id, ['rate' => $data['rate_per_kg_minor'], 'currency' => $currency]);
        return $id;
    }

    public function audit(array $f, int $limit = 100): array
    {
        $w = '1=1';
        $p = [];
        if (!empty($f['entity_type'])) {
            $w .= ' AND a.entity_type = ?';
            $p[] = $f['entity_type'];
        }
        if (!empty($f['entity_id'])) {
            $w .= ' AND a.entity_id = ?';
            $p[] = (int) $f['entity_id'];
        }
        if (!empty($f['action'])) {
            $w .= " AND a.action LIKE ? ESCAPE '!'";
            $p[] = Db::like($f['action']);
        }
        return $this->db->all("SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $w ORDER BY a.id DESC LIMIT $limit", $p);
    }
}
