<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Config;
use App\Core\Db;
use App\Core\Domain;
use App\Core\Money;
use App\Core\Upload;
use App\Core\ValidationException;
use App\Core\Validator;

/** Colis : enregistrement, modification, statuts et historique. */
final class ParcelService
{
    /** Alphabet Crockford sans I, L, O, U (pas de confusion à la lecture ni à la saisie). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    /**
     * Code de suivi : DI-XXXX-XXXX, 8 caractères aléatoires (40 bits, ~1 100 milliards
     * de combinaisons). Combiné à la limitation des recherches, l'énumération est impraticable.
     */
    public static function generateCode(): string
    {
        $bytes = random_bytes(8);
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return 'DI-' . substr($chars, 0, 4) . '-' . substr($chars, 4, 4);
    }

    /** Normalise une saisie : minuscules, espaces, O->0, I/L->1, tirets manquants. */
    public static function normalizeCode(string $input): ?string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (str_starts_with($s, 'DI')) {
            $s = substr($s, 2);
        }
        $s = strtr($s, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
        if (strlen($s) !== 8 || strspn($s, self::ALPHABET) !== 8) {
            return null;
        }
        return 'DI-' . substr($s, 0, 4) . '-' . substr($s, 4, 4);
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT p.*, s.name AS sender_name, s.phone AS sender_phone, s.code AS sender_code,
                    r.name AS recipient_name, r.phone AS recipient_phone, r.code AS recipient_code, r.address AS recipient_address,
                    sh.reference AS shipment_reference, sh.status AS shipment_status, sh.flight_number, sh.airline,
                    sh.planned_arrival, sh.actual_arrival, sh.actual_departure
             FROM parcels p
             JOIN clients s ON s.id = p.sender_id
             JOIN clients r ON r.id = p.recipient_id
             LEFT JOIN shipments sh ON sh.id = p.shipment_id
             WHERE p.id = ?',
            [$id]
        );
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Colis introuvable.');
    }

    /** Faux pour un agent de livraison si le colis n'est sur aucune de ses tournées. */
    public function visibleToCurrentUser(int $id): bool
    {
        if (Auth::role() !== 'courier') {
            return true;
        }
        return (bool) $this->db->value('SELECT 1 FROM deliveries WHERE parcel_id = ? AND agent_id = ?', [$id, Auth::id()]);
    }

    public function findByCode(string $code): ?array
    {
        $norm = self::normalizeCode($code);
        if (!$norm) {
            return null;
        }
        $id = $this->db->value('SELECT id FROM parcels WHERE tracking_code = ?', [$norm]);
        return $id ? $this->find((int) $id) : null;
    }

    /** Liste filtrée et paginée. Filtres : q, status, shipment_id, destination, from, to, unbilled. */
    public function list(array $f, int $limit = 30, int $offset = 0): array
    {
        [$where, $params] = $this->filters($f);
        return $this->db->all(
            "SELECT p.id, p.tracking_code, p.description, p.weight_g, p.billable_weight_g, p.amount_minor, p.currency, p.status,
                    p.received_at, p.destination_city, p.destination_country, p.status_changed_at, p.pieces,
                    s.name AS sender_name, r.name AS recipient_name, sh.reference AS shipment_reference
             FROM parcels p JOIN clients s ON s.id = p.sender_id JOIN clients r ON r.id = p.recipient_id
             LEFT JOIN shipments sh ON sh.id = p.shipment_id
             WHERE $where ORDER BY p.received_at DESC, p.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function count(array $f): int
    {
        [$where, $params] = $this->filters($f);
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM parcels p JOIN clients s ON s.id = p.sender_id JOIN clients r ON r.id = p.recipient_id WHERE $where",
            $params
        );
    }

    private function filters(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        // Un agent de livraison ne voit que les colis de ses propres tournées.
        if (Auth::role() === 'courier') {
            $w[] = 'EXISTS (SELECT 1 FROM deliveries d WHERE d.parcel_id = p.id AND d.agent_id = ?)';
            $p[] = Auth::id();
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $code = self::normalizeCode($q);
            $digits = preg_replace('/\D+/', '', $q);
            $sub = ["p.tracking_code LIKE ? ESCAPE '!'", "s.name LIKE ? ESCAPE '!'", "r.name LIKE ? ESCAPE '!'", "p.description LIKE ? ESCAPE '!'"];
            array_push($p, Db::like(strtoupper($q)), Db::like($q), Db::like($q), Db::like($q));
            if ($code) {
                $sub[] = 'p.tracking_code = ?';
                $p[] = $code;
            }
            if (strlen($digits) >= 6) {
                $sub[] = "s.phone_normalized LIKE ? ESCAPE '!'";
                $sub[] = "r.phone_normalized LIKE ? ESCAPE '!'";
                $p[] = Db::like($digits);
                $p[] = Db::like($digits);
            }
            $w[] = '(' . implode(' OR ', $sub) . ')';
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'exceptions') {
                $w[] = "p.status IN ('anomaly','blocked','delivery_failed','check_required')";
            } elseif (isset(Domain::parcelStatuses()[$f['status']])) {
                $w[] = 'p.status = ?';
                $p[] = $f['status'];
            }
        }
        if (!empty($f['shipment_id'])) {
            $w[] = 'p.shipment_id = ?';
            $p[] = (int) $f['shipment_id'];
        }
        if (!empty($f['no_shipment'])) {
            $w[] = 'p.shipment_id IS NULL';
        }
        if (!empty($f['destination'])) {
            $w[] = 'p.destination_country = ?';
            $p[] = $f['destination'];
        }
        if (!empty($f['client_id'])) {
            $w[] = '(p.sender_id = ? OR p.recipient_id = ?)';
            $p[] = (int) $f['client_id'];
            $p[] = (int) $f['client_id'];
        }
        if (!empty($f['from'])) {
            $w[] = 'p.received_at >= ?';
            $p[] = local_to_utc($f['from'] . ' 00:00');
        }
        if (!empty($f['to'])) {
            $w[] = 'p.received_at <= ?';
            $p[] = local_to_utc($f['to'] . ' 23:59:59');
        }
        if (!empty($f['unbilled'])) {
            $w[] = 'NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.parcel_id = p.id AND ii.billing_lock = 1)';
        }
        if (!empty($f['currency'])) {
            $w[] = 'p.currency = ?';
            $p[] = $f['currency'];
        }
        return [implode(' AND ', $w), $p];
    }

    /** Facture active (brouillon ou validée) contenant le colis, s'il y en a une. */
    public function activeInvoice(int $parcelId): ?array
    {
        return $this->db->one(
            'SELECT i.* FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE ii.parcel_id = ? AND ii.billing_lock = 1',
            [$parcelId]
        );
    }

    private function input(array $in, ?array $existing): array
    {
        $currency = (string) ($in['currency'] ?? Config::get('DEFAULT_CURRENCY', 'XOF'));
        $data = [
            'description' => trim((string) ($in['description'] ?? '')),
            'pieces' => max(1, (int) ($in['pieces'] ?? 1)),
            'weight_g' => Money::parseKg($in['weight_kg'] ?? ''),
            'currency' => $currency,
            'origin_country' => (string) ($in['origin_country'] ?? 'EG'),
            'origin_city' => trim((string) ($in['origin_city'] ?? '')) ?: null,
            'destination_country' => (string) ($in['destination_country'] ?? 'CI'),
            'destination_city' => trim((string) ($in['destination_city'] ?? '')) ?: null,
            'delivery_contact_name' => trim((string) ($in['delivery_contact_name'] ?? '')) ?: null,
            'delivery_contact_phone' => trim((string) ($in['delivery_contact_phone'] ?? '')) ?: null,
            'payment_terms' => trim((string) ($in['payment_terms'] ?? '')) ?: null,
            'internal_notes' => trim((string) ($in['internal_notes'] ?? '')) ?: null,
            'public_weight' => !empty($in['public_weight']) ? 1 : 0,
            'received_at' => local_to_utc(($in['received_at'] ?? '') ?: null) ?? ($existing['received_at'] ?? Db::now()),
        ];
        $tariffId = (int) ($in['tariff_id'] ?? 0) ?: null;
        $rate = Money::parse($in['rate'] ?? '', $currency);

        $v = (new Validator())
            ->required('description', $data['description'], 'La description du contenu')
            ->max('description', $data['description'], 255, 'Description')
            ->in('currency', $currency, array_keys(Domain::CURRENCIES), 'Devise')
            ->in('origin_country', $data['origin_country'], array_keys(Domain::COUNTRIES), 'Pays de départ')
            ->in('destination_country', $data['destination_country'], array_keys(Domain::COUNTRIES), 'Pays de destination')
            ->in('payment_terms', $data['payment_terms'], array_keys(Domain::PAYMENT_TERMS), 'Modalité de paiement', true)
            ->phone('delivery_contact_phone', $data['delivery_contact_phone'], 'Téléphone du contact de livraison')
            ->max('internal_notes', $data['internal_notes'], 2000, 'Observations');
        if ($data['weight_g'] === null || $data['weight_g'] <= 0) {
            $v->add('weight_kg', 'Poids invalide : saisissez un nombre en kilogrammes, par exemple 12,5.');
        } elseif ($data['weight_g'] > 5_000_000) {
            $v->add('weight_kg', 'Poids supérieur à 5 000 kg : vérifiez la saisie.');
        }

        $minG = 0;
        if ($tariffId) {
            $tariff = $this->db->one('SELECT * FROM tariffs WHERE id = ? AND is_active = 1', [$tariffId]);
            if (!$tariff) {
                $v->add('tariff_id', 'Tarif introuvable ou désactivé.');
            } else {
                $minG = (int) $tariff['min_billable_g'];
                if ($tariff['currency'] !== $currency) {
                    $v->add('currency', 'La devise doit être celle du tarif choisi (' . $tariff['currency'] . ').');
                }
                // Sans droit financier, le tarif de la grille s'impose.
                if ($rate === null || !Auth::can('parcels.finance')) {
                    $rate = (int) $tariff['rate_per_kg_minor'];
                }
            }
        }
        if ($rate === null || $rate < 0) {
            $v->add('rate', 'Tarif par kilogramme invalide.');
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data['tariff_id'] = $tariffId;
        $data['rate_per_kg_minor'] = $rate;
        $data['billable_weight_g'] = Money::billableWeight($data['weight_g'], $minG);
        $data['amount_minor'] = Money::transportAmount($data['billable_weight_g'], $rate);
        return $data;
    }

    /** Enregistre un colis (et, si besoin, l'expéditeur / le destinataire). Retourne l'id. */
    public function create(array $in, array $files = []): array
    {
        $clients = new ClientService($this->db);
        $notices = [];
        $result = $this->db->tx(function () use ($in, $clients, &$notices, $files) {
            $data = $this->input($in, null);
            $sender = $clients->resolve((int) ($in['sender_id'] ?? 0) ?: null, (string) ($in['sender_name'] ?? ''), (string) ($in['sender_phone'] ?? ''), 'sender', $data['origin_city'], $data['origin_country']);
            $recipient = $clients->resolve((int) ($in['recipient_id'] ?? 0) ?: null, (string) ($in['recipient_name'] ?? ''), (string) ($in['recipient_phone'] ?? ''), 'recipient', $data['destination_city'], $data['destination_country']);
            if (empty($in['sender_id']) && $sender['reused']) {
                $notices[] = 'Expéditeur déjà connu : la fiche existante (même téléphone) a été utilisée.';
            }
            if (empty($in['recipient_id']) && $recipient['reused']) {
                $notices[] = 'Destinataire déjà connu : la fiche existante (même téléphone) a été utilisée.';
            }
            $data['sender_id'] = $sender['id'];
            $data['recipient_id'] = $recipient['id'];
            $data['status'] = 'registered';
            $data['status_changed_at'] = Db::now();
            $data['created_by'] = Auth::id();
            $data['created_at'] = $data['updated_at'] = Db::now();

            // Code unique : la contrainte UNIQUE tranche, on retente en cas de collision.
            for ($i = 0; ; $i++) {
                $data['tracking_code'] = self::generateCode();
                try {
                    $id = $this->db->insert('parcels', $data);
                    break;
                } catch (\PDOException $e) {
                    if (!Db::isUniqueViolation($e) || $i >= 5) {
                        throw $e;
                    }
                }
            }
            $this->db->insert('tracking_events', [
                'parcel_id' => $id, 'status' => 'registered', 'previous_status' => null,
                'location' => $data['origin_city'] ?: country_name($data['origin_country']),
                'public_comment' => 'Colis pris en charge à notre comptoir.', 'user_id' => Auth::id(),
                'occurred_at' => $data['received_at'], 'created_at' => Db::now(),
            ]);
            foreach ($files as $file) {
                $this->attach($id, $file);
            }
            Audit::log('parcel.created', 'parcel', $id, ['weight_g' => $data['weight_g'], 'amount' => $data['amount_minor'], 'currency' => $data['currency']]);
            return $id;
        });
        $parcel = $this->get($result);
        (new NotificationService($this->db))->parcelStatusChanged($parcel, 'registered');
        return ['id' => $result, 'notices' => $notices];
    }

    public function update(int $id, array $in): void
    {
        $existing = $this->get($id);
        $data = $this->input($in, $existing);
        $billingChanged = $data['weight_g'] !== (int) $existing['weight_g']
            || $data['rate_per_kg_minor'] !== (int) $existing['rate_per_kg_minor']
            || $data['currency'] !== $existing['currency']
            || $data['billable_weight_g'] !== (int) $existing['billable_weight_g'];
        if ($billingChanged && ($inv = $this->activeInvoice($id))) {
            throw new BusinessException(sprintf(
                'Ce colis figure sur la facture %s : son poids, son tarif et sa devise ne peuvent plus être modifiés. Retirez-le du brouillon ou annulez la facture d’abord.',
                $inv['number'] ?? 'brouillon #' . $inv['id']
            ));
        }
        if (!Auth::can('parcels.finance')) {
            foreach (['rate_per_kg_minor', 'currency', 'tariff_id'] as $k) {
                $data[$k] = $existing[$k];
            }
            $data['amount_minor'] = Money::transportAmount($data['billable_weight_g'], (int) $existing['rate_per_kg_minor']);
        }
        if (!empty($in['recipient_id']) && (int) $in['recipient_id'] !== (int) $existing['recipient_id']) {
            $r = (new ClientService($this->db))->resolve((int) $in['recipient_id'], '', '', 'recipient');
            $data['recipient_id'] = $r['id'];
        }
        if (!empty($in['sender_id']) && (int) $in['sender_id'] !== (int) $existing['sender_id']) {
            if ($this->activeInvoice($id)) {
                throw new BusinessException('Expéditeur non modifiable : le colis est déjà facturé.');
            }
            $s = (new ClientService($this->db))->resolve((int) $in['sender_id'], '', '', 'sender');
            $data['sender_id'] = $s['id'];
        }
        $data['updated_at'] = Db::now();
        $this->db->update('parcels', $data, 'id = ?', [$id]);
        $changed = array_keys(array_filter($data, fn ($v, $k) => array_key_exists($k, $existing) && (string) $existing[$k] !== (string) $v && $k !== 'updated_at', ARRAY_FILTER_USE_BOTH));
        Audit::log('parcel.updated', 'parcel', $id, ['fields' => $changed]);
    }

    /**
     * Change le statut d'un colis en respectant les transitions autorisées.
     * $via = 'delivery' est requis pour poser « delivered » (confirmation explicite de remise).
     */
    public function changeStatus(int $id, string $to, array $opts = []): void
    {
        $parcel = $this->db->tx(function () use ($id, $to, $opts) {
            $p = $this->db->one('SELECT * FROM parcels WHERE id = ?' . $this->db->forUpdate(), [$id]);
            if (!$p) {
                throw new BusinessException('Colis introuvable.');
            }
            $from = $p['status'];
            if ($from === $to) {
                throw new BusinessException('Le colis est déjà au statut « ' . Domain::parcelLabel($to) . ' ».');
            }
            if (!isset(Domain::parcelStatuses()[$to])) {
                throw new BusinessException('Statut inconnu.');
            }
            if (in_array($to, Domain::PARCEL_STATUS_RESERVED, true) && ($opts['via'] ?? null) !== 'delivery') {
                throw new BusinessException('La remise d’un colis se confirme depuis le module Livraisons (nom du destinataire et confirmation obligatoires).');
            }
            $viaShipment = ($opts['via'] ?? null) === 'shipment';
            if (!Domain::canParcelTransition($from, $to) && !($viaShipment && in_array($to, ['shipped', 'in_transit', 'arrived'], true) && in_array($from, ['registered', 'awaiting', 'shipped', 'in_transit'], true))) {
                throw new BusinessException(sprintf('Passage de « %s » à « %s » non autorisé.', Domain::parcelLabel($from), Domain::parcelLabel($to)));
            }
            $occurred = !empty($opts['occurred_at']) ? (local_to_utc($opts['occurred_at']) ?? Db::now()) : Db::now();
            if ($occurred > gmdate('Y-m-d H:i:s', time() + 600)) {
                throw new BusinessException('La date de l’événement ne peut pas être dans le futur.');
            }
            $this->db->update('parcels', ['status' => $to, 'status_changed_at' => Db::now(), 'updated_at' => Db::now()], 'id = ?', [$id]);
            $this->db->insert('tracking_events', [
                'parcel_id' => $id, 'status' => $to, 'previous_status' => $from,
                'location' => mb_substr(trim((string) ($opts['location'] ?? '')), 0, 120) ?: null,
                'public_comment' => mb_substr(trim((string) ($opts['public_comment'] ?? '')), 0, 255) ?: null,
                'internal_comment' => mb_substr(trim((string) ($opts['internal_comment'] ?? '')), 0, 500) ?: null,
                'user_id' => Auth::id(), 'occurred_at' => $occurred, 'created_at' => Db::now(),
            ]);
            Audit::log('parcel.status', 'parcel', $id, ['from' => $from, 'to' => $to, 'via' => $opts['via'] ?? 'manual']);
            return $p;
        });
        if (empty($opts['silent'])) {
            (new NotificationService($this->db))->parcelStatusChanged($parcel, $to);
        }
    }

    public function events(int $parcelId, bool $publicOnly = false): array
    {
        return $this->db->all(
            'SELECT e.*, u.name AS user_name FROM tracking_events e LEFT JOIN users u ON u.id = e.user_id
             WHERE e.parcel_id = ? ORDER BY e.occurred_at DESC, e.id DESC',
            [$parcelId]
        );
    }

    public function attach(int $parcelId, array $file): void
    {
        $stored = Upload::store($file, 'parcels');
        if ($stored) {
            $this->db->insert('parcel_attachments', $stored + ['parcel_id' => $parcelId, 'uploaded_by' => Auth::id(), 'created_at' => Db::now()]);
            Audit::log('parcel.attachment', 'parcel', $parcelId);
        }
    }

    public function attachments(int $parcelId): array
    {
        return $this->db->all('SELECT * FROM parcel_attachments WHERE parcel_id = ? ORDER BY id', [$parcelId]);
    }

    public function tariffs(): array
    {
        return $this->db->all('SELECT * FROM tariffs WHERE is_active = 1 ORDER BY origin_country, destination_country, name');
    }
}
