<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Db;
use App\Core\Domain;
use App\Core\Money;
use App\Core\Sequence;
use App\Core\ValidationException;
use App\Core\Validator;

/** Dossiers d'expédition aérienne (un dossier = un vol / une LTA regroupant des colis). */
final class ShipmentService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT s.*, (SELECT COUNT(*) FROM parcels p WHERE p.shipment_id = s.id) AS parcel_count,
                    (SELECT COALESCE(SUM(p.weight_g),0) FROM parcels p WHERE p.shipment_id = s.id) AS total_weight_g
             FROM shipments s WHERE s.id = ?',
            [$id]
        );
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Dossier introuvable.');
    }

    public function list(array $f = [], int $limit = 30, int $offset = 0): array
    {
        [$where, $params] = $this->filters($f);
        return $this->db->all(
            "SELECT s.*, (SELECT COUNT(*) FROM parcels p WHERE p.shipment_id = s.id) AS parcel_count,
                    (SELECT COALESCE(SUM(p.weight_g),0) FROM parcels p WHERE p.shipment_id = s.id) AS total_weight_g
             FROM shipments s WHERE $where
             ORDER BY CASE WHEN s.status IN ('closed','cancelled') THEN 1 ELSE 0 END, COALESCE(s.planned_departure, s.created_at) DESC
             LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function count(array $f = []): int
    {
        [$where, $params] = $this->filters($f);
        return (int) $this->db->value("SELECT COUNT(*) FROM shipments s WHERE $where", $params);
    }

    private function filters(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['q'])) {
            $w[] = "(s.reference LIKE ? ESCAPE '!' OR s.flight_number LIKE ? ESCAPE '!' OR s.awb_number LIKE ? ESCAPE '!' OR s.airline LIKE ? ESCAPE '!')";
            array_push($p, Db::like($f['q']), Db::like($f['q']), Db::like($f['q']), Db::like($f['q']));
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'active') {
                $w[] = "s.status IN ('preparation','departed','in_transit','arrived')";
            } elseif (isset(Domain::SHIPMENT_STATUSES[$f['status']])) {
                $w[] = 's.status = ?';
                $p[] = $f['status'];
            }
        }
        return [implode(' AND ', $w), $p];
    }

    public function active(): array
    {
        return $this->db->all("SELECT id, reference, flight_number, destination_city, status FROM shipments WHERE status IN ('preparation','departed','in_transit','arrived') ORDER BY created_at DESC");
    }

    private function input(array $in): array
    {
        $currency = (string) ($in['freight_currency'] ?? 'XOF');
        $data = [
            'origin_country' => (string) ($in['origin_country'] ?? 'EG'),
            'origin_city' => trim((string) ($in['origin_city'] ?? '')) ?: null,
            'destination_country' => (string) ($in['destination_country'] ?? 'CI'),
            'destination_city' => trim((string) ($in['destination_city'] ?? '')) ?: null,
            'airline' => trim((string) ($in['airline'] ?? '')) ?: null,
            'flight_number' => strtoupper(str_replace(' ', '', trim((string) ($in['flight_number'] ?? '')))) ?: null,
            'awb_number' => trim((string) ($in['awb_number'] ?? '')) ?: null,
            'carrier_reference' => trim((string) ($in['carrier_reference'] ?? '')) ?: null,
            'planned_departure' => local_to_utc(($in['planned_departure'] ?? '') ?: null),
            'planned_arrival' => local_to_utc(($in['planned_arrival'] ?? '') ?: null),
            'actual_departure' => local_to_utc(($in['actual_departure'] ?? '') ?: null),
            'actual_arrival' => local_to_utc(($in['actual_arrival'] ?? '') ?: null),
            'freight_cost_minor' => Money::parse($in['freight_cost'] ?? '', $currency),
            'freight_currency' => $currency,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
        ];
        $v = (new Validator())
            ->in('origin_country', $data['origin_country'], array_keys(Domain::COUNTRIES), 'Pays de départ')
            ->in('destination_country', $data['destination_country'], array_keys(Domain::COUNTRIES), 'Pays de destination')
            ->in('freight_currency', $currency, array_keys(Domain::CURRENCIES), 'Devise')
            ->max('airline', $data['airline'], 80, 'Compagnie')
            ->max('flight_number', $data['flight_number'], 20, 'Numéro de vol')
            ->max('notes', $data['notes'], 2000, 'Observations');
        foreach (['planned_departure', 'planned_arrival', 'actual_departure', 'actual_arrival'] as $k) {
            if (!empty($in[$k]) && $data[$k] === null) {
                $v->add($k, 'Date invalide.');
            }
        }
        if ($data['planned_departure'] && $data['planned_arrival'] && $data['planned_arrival'] < $data['planned_departure']) {
            $v->add('planned_arrival', 'L’arrivée prévue précède le départ prévu.');
        }
        if ($data['actual_departure'] && $data['actual_arrival'] && $data['actual_arrival'] < $data['actual_departure']) {
            $v->add('actual_arrival', 'L’arrivée réelle précède le départ réel.');
        }
        if (($in['freight_cost'] ?? '') !== '' && ($data['freight_cost_minor'] === null || $data['freight_cost_minor'] < 0)) {
            $v->add('freight_cost', 'Montant invalide.');
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        return $data;
    }

    public function create(array $in): int
    {
        $data = $this->input($in);
        return $this->db->tx(function () use ($data) {
            $data['reference'] = Sequence::yearly('EXP', 4);
            $data['status'] = 'preparation';
            $data['created_by'] = Auth::id();
            $data['created_at'] = $data['updated_at'] = Db::now();
            $id = $this->db->insert('shipments', $data);
            Audit::log('shipment.created', 'shipment', $id);
            return $id;
        });
    }

    public function update(int $id, array $in): void
    {
        $s = $this->get($id);
        if (in_array($s['status'], ['closed', 'cancelled'], true)) {
            throw new BusinessException('Dossier clôturé ou annulé : modification impossible.');
        }
        $data = $this->input($in) + ['updated_at' => Db::now()];
        $this->db->update('shipments', $data, 'id = ?', [$id]);
        Audit::log('shipment.updated', 'shipment', $id);
    }

    /**
     * Associe des colis au dossier. Un colis ne peut appartenir qu'à un seul dossier actif
     * (contrainte UNIQUE en base) et doit être en attente d'expédition.
     * @return array{added:int, skipped:array<string,string>}
     */
    public function addParcels(int $shipmentId, array $parcelIds): array
    {
        $s = $this->get($shipmentId);
        if ($s['status'] !== 'preparation') {
            throw new BusinessException('Les colis ne peuvent être ajoutés qu’à un dossier en préparation.');
        }
        $added = 0;
        $skipped = [];
        $this->db->tx(function () use ($shipmentId, $parcelIds, &$added, &$skipped, $s) {
            foreach ($parcelIds as $pid) {
                $p = $this->db->one('SELECT id, tracking_code, status, shipment_id, destination_country FROM parcels WHERE id = ?' . $this->db->forUpdate(), [(int) $pid]);
                if (!$p) {
                    continue;
                }
                if ($p['shipment_id']) {
                    $skipped[$p['tracking_code']] = (int) $p['shipment_id'] === $shipmentId ? 'déjà dans ce dossier' : 'déjà associé à un autre dossier actif';
                    continue;
                }
                if (!in_array($p['status'], ['registered', 'awaiting', 'check_required'], true)) {
                    $skipped[$p['tracking_code']] = 'statut « ' . Domain::parcelLabel($p['status']) . ' » incompatible';
                    continue;
                }
                if ($p['destination_country'] !== $s['destination_country']) {
                    $skipped[$p['tracking_code']] = 'destination différente du dossier';
                    continue;
                }
                try {
                    $this->db->insert('shipment_parcels', [
                        'shipment_id' => $shipmentId, 'parcel_id' => $p['id'], 'active_flag' => 1,
                        'added_by' => Auth::id(), 'added_at' => Db::now(),
                    ]);
                } catch (\PDOException $e) {
                    if (Db::isUniqueViolation($e)) {
                        $skipped[$p['tracking_code']] = 'déjà associé à un dossier actif';
                        continue;
                    }
                    throw $e;
                }
                $this->db->update('parcels', ['shipment_id' => $shipmentId, 'updated_at' => Db::now()], 'id = ?', [$p['id']]);
                if ($p['status'] === 'registered') {
                    (new ParcelService($this->db))->changeStatus((int) $p['id'], 'awaiting', [
                        'public_comment' => 'Colis affecté à un prochain vol.', 'silent' => true, 'via' => 'shipment',
                    ]);
                }
                $added++;
            }
            Audit::log('shipment.parcels_added', 'shipment', $shipmentId, ['count' => $added]);
        });
        return ['added' => $added, 'skipped' => $skipped];
    }

    public function removeParcel(int $shipmentId, int $parcelId): void
    {
        $s = $this->get($shipmentId);
        if ($s['status'] !== 'preparation') {
            throw new BusinessException('Un colis ne peut être retiré que d’un dossier en préparation.');
        }
        $this->db->tx(function () use ($shipmentId, $parcelId) {
            $n = $this->db->run(
                'UPDATE shipment_parcels SET active_flag = NULL, removed_at = ?, removed_by = ? WHERE shipment_id = ? AND parcel_id = ? AND active_flag = 1',
                [Db::now(), Auth::id(), $shipmentId, $parcelId]
            )->rowCount();
            if ($n === 0) {
                throw new BusinessException('Ce colis n’est pas dans ce dossier.');
            }
            $this->db->update('parcels', ['shipment_id' => null, 'updated_at' => Db::now()], 'id = ?', [$parcelId]);
            Audit::log('shipment.parcel_removed', 'shipment', $shipmentId, ['parcel_id' => $parcelId]);
        });
    }

    /**
     * Change le statut du dossier. Si $applyToParcels est vrai, le statut correspondant
     * est appliqué aux colis du dossier dont la transition est cohérente ; les autres
     * (incidents, colis déjà plus avancés) sont laissés tels quels et listés.
     * @return array{updated:int, skipped:array<string,string>}
     */
    public function changeStatus(int $id, string $to, bool $applyToParcels, array $opts = []): array
    {
        $s = $this->get($id);
        $from = $s['status'];
        if (!in_array($to, Domain::SHIPMENT_TRANSITIONS[$from] ?? [], true)) {
            throw new BusinessException(sprintf('Passage du dossier de « %s » à « %s » non autorisé.', Domain::SHIPMENT_STATUSES[$from] ?? $from, Domain::SHIPMENT_STATUSES[$to] ?? $to));
        }
        if ($to === 'departed' && (int) $s['parcel_count'] === 0) {
            throw new BusinessException('Ajoutez au moins un colis avant de confirmer le départ.');
        }
        if ($to === 'closed') {
            $open = (int) $this->db->value("SELECT COUNT(*) FROM parcels WHERE shipment_id = ? AND status NOT IN ('delivered')", [$id]);
            if ($open > 0 && empty($opts['force_close'])) {
                throw new BusinessException("$open colis de ce dossier ne sont pas encore remis. Cochez « Clôturer malgré tout » pour confirmer.");
            }
        }
        if ($to === 'cancelled' && (int) $s['parcel_count'] > 0) {
            throw new BusinessException('Retirez d’abord les colis du dossier avant de l’annuler.');
        }

        $updated = 0;
        $skipped = [];
        $parcelIds = [];
        $this->db->tx(function () use ($id, $to, $from, $opts, &$parcelIds) {
            $patch = ['status' => $to, 'updated_at' => Db::now()];
            $when = !empty($opts['occurred_at']) ? local_to_utc($opts['occurred_at']) : Db::now();
            if ($to === 'departed') {
                $patch['actual_departure'] = $when;
            }
            if ($to === 'arrived') {
                $patch['actual_arrival'] = $when;
            }
            $this->db->update('shipments', $patch, 'id = ?', [$id]);
            Audit::log('shipment.status', 'shipment', $id, ['from' => $from, 'to' => $to]);
            $parcelIds = array_column($this->db->all('SELECT id FROM parcels WHERE shipment_id = ?', [$id]), 'id');
        });

        $target = Domain::SHIPMENT_TO_PARCEL[$to] ?? null;
        if ($applyToParcels && $target) {
            $parcels = new ParcelService($this->db);
            $location = match ($to) {
                'departed' => $s['origin_city'] ?: country_name($s['origin_country']),
                'arrived' => $s['destination_city'] ?: country_name($s['destination_country']),
                default => null,
            };
            $comment = match ($to) {
                'departed' => 'Embarqué' . ($s['flight_number'] ? ' sur le vol ' . $s['flight_number'] : '') . '.',
                'in_transit' => 'Colis en vol vers ' . ($s['destination_city'] ?: country_name($s['destination_country'])) . '.',
                'arrived' => 'Vol arrivé à destination, dédouanement en cours.',
                default => null,
            };
            foreach ($parcelIds as $pid) {
                $p = $this->db->one('SELECT tracking_code, status FROM parcels WHERE id = ?', [$pid]);
                if (isset(Domain::PARCEL_EXCEPTIONS[$p['status']])) {
                    $skipped[$p['tracking_code']] = 'en incident (« ' . Domain::parcelLabel($p['status']) . ' ») : à traiter individuellement';
                    continue;
                }
                $flow = array_keys(Domain::PARCEL_FLOW);
                if (array_search($p['status'], $flow, true) >= array_search($target, $flow, true)) {
                    $skipped[$p['tracking_code']] = 'déjà au statut « ' . Domain::parcelLabel($p['status']) . ' »';
                    continue;
                }
                try {
                    $parcels->changeStatus((int) $pid, $target, [
                        'via' => 'shipment', 'location' => $location, 'public_comment' => $comment, 'occurred_at' => $opts['occurred_at'] ?? null,
                    ]);
                    $updated++;
                } catch (BusinessException $e) {
                    $skipped[$p['tracking_code']] = $e->getMessage();
                }
            }
        }
        return ['updated' => $updated, 'skipped' => $skipped];
    }

    public function parcels(int $shipmentId): array
    {
        return $this->db->all(
            'SELECT p.id, p.tracking_code, p.description, p.weight_g, p.pieces, p.status, p.amount_minor, p.currency,
                    s.name AS sender_name, r.name AS recipient_name
             FROM parcels p JOIN clients s ON s.id = p.sender_id JOIN clients r ON r.id = p.recipient_id
             WHERE p.shipment_id = ? ORDER BY p.tracking_code',
            [$shipmentId]
        );
    }

    /** Colis pouvant être ajoutés (même destination, pas encore affectés). */
    /** Dossiers en préparation vers ce pays : vols dans lesquels un colis peut encore être chargé. */
    public function openFor(string $destinationCountry): array
    {
        return $this->db->all(
            "SELECT s.id, s.reference, s.airline, s.flight_number, s.planned_departure, s.destination_city,
                    (SELECT COUNT(*) FROM parcels p WHERE p.shipment_id = s.id) AS parcel_count
             FROM shipments s WHERE s.status = 'preparation' AND s.destination_country = ?
             ORDER BY COALESCE(s.planned_departure, s.created_at) LIMIT 5",
            [$destinationCountry]
        );
    }

    public function candidates(int $shipmentId): array
    {
        $s = $this->get($shipmentId);
        return $this->db->all(
            "SELECT p.id, p.tracking_code, p.description, p.weight_g, p.received_at, s.name AS sender_name, r.name AS recipient_name
             FROM parcels p JOIN clients s ON s.id = p.sender_id JOIN clients r ON r.id = p.recipient_id
             WHERE p.shipment_id IS NULL AND p.status IN ('registered','awaiting','check_required') AND p.destination_country = ?
             ORDER BY p.received_at LIMIT 300",
            [$s['destination_country']]
        );
    }

    /**
     * Marge estimée par devise : facturé (factures actives) - dépenses rattachées.
     * Ce n'est pas un résultat comptable : frais généraux et taux de change exclus.
     */
    public function margin(int $shipmentId): array
    {
        $rows = [];
        foreach ($this->db->all(
            "SELECT i.currency, SUM(ii.line_total_minor) AS amount FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id JOIN parcels p ON p.id = ii.parcel_id
             WHERE p.shipment_id = ? AND ii.billing_lock = 1 AND i.status IN ('validated','partial','paid') GROUP BY i.currency",
            [$shipmentId]
        ) as $r) {
            $rows[$r['currency']]['billed'] = (int) $r['amount'];
        }
        foreach ($this->db->all(
            "SELECT currency, SUM(amount_minor) AS amount FROM expenses WHERE shipment_id = ? AND status = 'active' GROUP BY currency",
            [$shipmentId]
        ) as $r) {
            $rows[$r['currency']]['expenses'] = (int) $r['amount'];
        }
        foreach ($rows as $cur => &$r) {
            $r += ['billed' => 0, 'expenses' => 0];
            $r['margin'] = $r['billed'] - $r['expenses'];
        }
        $unbilled = (int) $this->db->value(
            'SELECT COUNT(*) FROM parcels p WHERE p.shipment_id = ? AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.parcel_id = p.id AND ii.billing_lock = 1)',
            [$shipmentId]
        );
        return ['rows' => $rows, 'unbilled' => $unbilled];
    }
}
