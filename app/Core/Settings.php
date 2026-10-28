<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Paramètres de l'entreprise modifiables par l'administrateur (table « settings »).
 * Une valeur enregistrée prime sur .env ; sans valeur enregistrée, .env s'applique.
 * Si la table n'existe pas encore (migration non lancée), .env s'applique sans erreur.
 */
final class Settings
{
    /** Clés modifiables : libellé, aide, longueur maximale, saisie sur plusieurs lignes. */
    public const EDITABLE = [
        'COMPANY_PHONE' => [
            'label' => 'Téléphone de l’entreprise',
            'hint' => 'Affiché sur la page publique de suivi, les factures, les reçus et les étiquettes. Laisser vide pour ne pas l’afficher.',
            'max' => 40, 'multiline' => false,
        ],
        'COMPANY_DEPOT_INFO' => [
            'label' => 'Informations de retrait au dépôt',
            'hint' => 'Jours et heures d’ouverture, adresse ou consignes : montrés au client quand son colis est disponible au dépôt.',
            'max' => 300, 'multiline' => true,
        ],
    ];

    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (isset(self::EDITABLE[$key])) {
            $saved = self::saved();
            if (array_key_exists($key, $saved)) {
                return (string) $saved[$key];
            }
        }
        return (string) Config::get($key, $default);
    }

    /** Valeurs enregistrées en base (clé => valeur), lues une fois par requête. */
    public static function saved(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::get()->all('SELECT setting_key, setting_value FROM settings') as $row) {
                    self::$cache[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
                }
            } catch (\Throwable $e) {
                // Table absente (migration 002 non appliquée) : on retombe sur .env.
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    /** Vérifie puis enregistre les paramètres ; renvoie la liste des clés modifiées. */
    public static function save(array $input, ?int $userId): array
    {
        $errors = [];
        $clean = [];
        foreach (self::EDITABLE as $key => $def) {
            $v = str_replace("\r\n", "\n", trim((string) ($input[$key] ?? '')));
            $v = (string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $def['multiline'] ? $v : str_replace("\n", ' ', $v));
            if (mb_strlen($v) > $def['max']) {
                $errors[$key] = $def['max'] . ' caractères maximum.';
            } elseif ($key === 'COMPANY_PHONE' && $v !== '' && !preg_match('/^\+?[\d\s().\-\/]{6,40}$/', $v)) {
                $errors[$key] = 'Numéro invalide : chiffres, espaces et « + ( ) - . / » uniquement.';
            }
            $clean[$key] = $v;
        }
        if ($errors) {
            throw new ValidationException($errors, 'Vérifiez les champs signalés.');
        }
        $changed = array_keys(array_filter($clean, fn ($v, $k) => $v !== self::get($k), ARRAY_FILTER_USE_BOTH));
        if (!$changed) {
            return [];
        }
        $db = Db::get();
        $db->tx(function (Db $db) use ($clean, $changed, $userId) {
            foreach ($changed as $key) {
                $db->run('DELETE FROM settings WHERE setting_key = ?', [$key]);
                $db->insert('settings', ['setting_key' => $key, 'setting_value' => $clean[$key], 'updated_by' => $userId, 'updated_at' => Db::now()]);
            }
            Audit::log('settings.updated', null, null, ['keys' => $changed]);
        });
        self::$cache = null;
        return $changed;
    }

    /** Pour les tests. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
