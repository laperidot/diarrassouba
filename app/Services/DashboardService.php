<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Domain;

/**
 * Indicateurs calculés exclusivement depuis la base. Aucune valeur n'est inventée :
 * en l'absence de données, la valeur est 0 et l'interface affiche un état vide.
 * Les montants sont présentés par devise, sans conversion.
 */
final class DashboardService
{
    private Db $db;

    public const PERIODS = [
        '7d' => '7 jours', '30d' => '30 jours', '90d' => '3 mois', '12m' => '12 mois', 'all' => 'Depuis le début',
    ];

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    /** @return array{0:?string,1:?string,2:string} [début UTC, fin UTC, libellé] */
    public function range(string $period, ?string $from = null, ?string $to = null): array
    {
        if ($period === 'custom' && $from && $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [local_to_utc("$from 00:00"), local_to_utc("$to 23:59:59"), 'du ' . d($from) . ' au ' . d($to)];
        }
        $now = new \DateTimeImmutable('now', local_tz());
        $start = match ($period) {
            '7d' => $now->modify('-6 days'),
            '90d' => $now->modify('-89 days'),
            '12m' => $now->modify('-12 months')->modify('+1 day'),
            'all' => null,
            default => $now->modify('-29 days'),
        };
        return [
            $start ? local_to_utc($start->format('Y-m-d') . ' 00:00') : null,
            null,
            self::PERIODS[$period] ?? self::PERIODS['30d'],
        ];
    }

    private function between(string $col, ?string $from, ?string $to, array &$p): string
    {
        $sql = '';
        if ($from) {
            $sql .= " AND $col >= ?";
            $p[] = $from;
        }
        if ($to) {
            $sql .= " AND $col <= ?";
            $p[] = $to;
        }
        return $sql;
    }

    /** Photo instantanée : nombre de colis par statut (tous colis non archivés). */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(array_keys(Domain::parcelStatuses()), 0);
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM parcels GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    public function operations(?string $from, ?string $to): array
    {
        $p = [];
        $parcels = $this->db->one('SELECT COUNT(*) AS n, COALESCE(SUM(weight_g),0) AS w FROM parcels WHERE 1=1' . $this->between('received_at', $from, $to, $p), $p);
        $p = [];
        $shipments = (int) $this->db->value("SELECT COUNT(*) FROM shipments WHERE status <> 'cancelled'" . $this->between('created_at', $from, $to, $p), $p);
        $p = [];
        $departed = $this->db->one(
            "SELECT COUNT(*) AS n FROM shipments WHERE actual_departure IS NOT NULL" . $this->between('actual_departure', $from, $to, $p),
            $p
        );
        // Poids transporté = colis dont le dossier est effectivement parti sur la période.
        $p = [];
        $flownWeight = (int) $this->db->value(
            'SELECT COALESCE(SUM(p.weight_g),0) FROM parcels p JOIN shipments s ON s.id = p.shipment_id WHERE s.actual_departure IS NOT NULL'
            . $this->between('s.actual_departure', $from, $to, $p),
            $p
        );
        return [
            'parcels' => (int) $parcels['n'],
            'registered_weight_g' => (int) $parcels['w'],
            'shipments' => $shipments,
            'flights' => (int) $departed['n'],
            'flown_weight_g' => $flownWeight,
        ];
    }

    /** Facturé, encaissé, créances et dépenses, par devise. */
    public function finance(?string $from, ?string $to): array
    {
        $out = [];
        $p = [];
        foreach ($this->db->all(
            "SELECT currency, SUM(total_minor) AS v FROM invoices WHERE status IN ('validated','partial','paid','credited') AND validated_at IS NOT NULL"
            . $this->between('validated_at', $from, $to, $p) . ' GROUP BY currency',
            $p
        ) as $r) {
            $out[$r['currency']]['billed'] = (int) $r['v']; // les avoirs (montants négatifs) viennent en déduction
        }
        $p = [];
        foreach ($this->db->all('SELECT currency, SUM(amount_minor) AS v FROM payments WHERE 1=1' . $this->between('paid_at', $from, $to, $p) . ' GROUP BY currency', $p) as $r) {
            $out[$r['currency']]['collected'] = (int) $r['v'];
        }
        foreach ($this->db->all("SELECT currency, SUM(total_minor - paid_minor) AS v FROM invoices WHERE kind = 'invoice' AND status IN ('validated','partial') GROUP BY currency") as $r) {
            $out[$r['currency']]['receivable'] = (int) $r['v'];
        }
        $p = [];
        $fromDate = $from ? dt($from, 'Y-m-d') : null;
        $toDate = $to ? dt($to, 'Y-m-d') : null;
        foreach ($this->db->all("SELECT currency, SUM(amount_minor) AS v FROM expenses WHERE status = 'active'" . $this->between('expense_date', $fromDate, $toDate, $p) . ' GROUP BY currency', $p) as $r) {
            $out[$r['currency']]['expenses'] = (int) $r['v'];
        }
        foreach ($out as &$row) {
            $row += ['billed' => 0, 'collected' => 0, 'receivable' => 0, 'expenses' => 0];
        }
        ksort($out);
        return $out;
    }

    /** Remises sur la période : taux de réussite au premier passage, délai moyen. */
    public function deliveryPerformance(?string $from, ?string $to): array
    {
        $p = [];
        $rows = $this->db->all("SELECT status, attempt_no, mode FROM deliveries WHERE status IN ('done','failed')" . $this->between('updated_at', $from, $to, $p), $p);
        $done = count(array_filter($rows, fn ($r) => $r['status'] === 'done'));
        $failed = count($rows) - $done;
        $deliveriesOnly = array_filter($rows, fn ($r) => $r['mode'] === 'delivery');
        $firstOk = count(array_filter($deliveriesOnly, fn ($r) => $r['status'] === 'done' && (int) $r['attempt_no'] === 1));

        // Délai réception -> remise, en jours, sur les colis remis pendant la période.
        $p = [];
        $pairs = $this->db->all(
            "SELECT p.received_at, e.occurred_at FROM tracking_events e JOIN parcels p ON p.id = e.parcel_id
             WHERE e.status = 'delivered'" . $this->between('e.occurred_at', $from, $to, $p),
            $p
        );
        $days = array_map(fn ($r) => max(0, (strtotime($r['occurred_at']) - strtotime($r['received_at'])) / 86400), $pairs);
        return [
            'done' => $done,
            'failed' => $failed,
            'first_attempt_rate' => count($deliveriesOnly) ? (int) round(100 * $firstOk / count($deliveriesOnly)) : null,
            'avg_days' => $days ? round(array_sum($days) / count($days), 1) : null,
        ];
    }

    /** Colis enregistrés par semaine (8 à 12 dernières semaines) — regroupement fait en PHP (portable). */
    public function weeklyParcels(int $weeks = 10): array
    {
        $tz = local_tz();
        $start = (new \DateTimeImmutable('monday this week', $tz))->modify('-' . ($weeks - 1) . ' weeks');
        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $w = $start->modify("+$i weeks");
            $buckets[$w->format('Y-m-d')] = ['label' => $w->format('d/m'), 'parcels' => 0, 'weight_g' => 0];
        }
        $rows = $this->db->all('SELECT received_at, weight_g FROM parcels WHERE received_at >= ?', [local_to_utc($start->format('Y-m-d') . ' 00:00')]);
        foreach ($rows as $r) {
            $local = (new \DateTimeImmutable($r['received_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
            $monday = $local->modify('monday this week')->format('Y-m-d');
            if ($local->format('N') === '1') {
                $monday = $local->format('Y-m-d');
            }
            if (isset($buckets[$monday])) {
                $buckets[$monday]['parcels']++;
                $buckets[$monday]['weight_g'] += (int) $r['weight_g'];
            }
        }
        return array_values($buckets);
    }

    public function recentActivity(int $limit = 8): array
    {
        return $this->db->all(
            "SELECT e.status, e.occurred_at, e.location, p.id AS parcel_id, p.tracking_code, u.name AS user_name
             FROM tracking_events e JOIN parcels p ON p.id = e.parcel_id LEFT JOIN users u ON u.id = e.user_id
             ORDER BY e.created_at DESC, e.id DESC LIMIT $limit"
        );
    }

    public function recentPayments(int $limit = 6): array
    {
        return $this->db->all(
            "SELECT pay.id, pay.receipt_number, pay.amount_minor, pay.currency, pay.method, pay.paid_at, i.id AS invoice_id, c.name AS client_name
             FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN clients c ON c.id = i.client_id
             ORDER BY pay.paid_at DESC, pay.id DESC LIMIT $limit"
        );
    }

    /** Dossiers et colis nécessitant une intervention, triés par urgence. */
    public function attention(bool $withFinance): array
    {
        $items = [];
        foreach ($this->db->all(
            "SELECT id, tracking_code, status, status_changed_at FROM parcels
             WHERE status IN ('anomaly','blocked','delivery_failed','check_required') ORDER BY status_changed_at LIMIT 10"
        ) as $r) {
            $items[] = ['tone' => 'alert', 'title' => Domain::parcelLabel($r['status']) . ' : ' . $r['tracking_code'], 'detail' => 'Depuis ' . ago($r['status_changed_at']), 'url' => url('colis/' . $r['id'])];
        }
        $limit = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        foreach ($this->db->all(
            "SELECT p.id, p.tracking_code, p.status_changed_at FROM parcels p WHERE p.status = 'at_depot' AND p.status_changed_at < ?
             AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.parcel_id = p.id AND d.status IN ('planned','in_progress'))
             ORDER BY p.status_changed_at LIMIT 6",
            [$limit]
        ) as $r) {
            $items[] = ['tone' => 'wait', 'title' => $r['tracking_code'] . ' attend au dépôt', 'detail' => 'Disponible ' . ago($r['status_changed_at']) . ', aucun retrait planifié', 'url' => url('colis/' . $r['id'])];
        }
        foreach ($this->db->all(
            "SELECT id, reference, planned_departure, planned_arrival, status FROM shipments
             WHERE (status = 'preparation' AND planned_departure IS NOT NULL AND planned_departure < ?)
                OR (status IN ('departed','in_transit') AND planned_arrival IS NOT NULL AND planned_arrival < ?)
             ORDER BY planned_departure LIMIT 6",
            [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() - 6 * 3600)]
        ) as $r) {
            $late = $r['status'] === 'preparation' ? 'Départ prévu le ' . dt($r['planned_departure'], 'd/m H:i') . ' non confirmé' : 'Arrivée prévue le ' . dt($r['planned_arrival'], 'd/m H:i') . ' non confirmée';
            $items[] = ['tone' => 'air', 'title' => 'Dossier ' . $r['reference'], 'detail' => $late, 'url' => url('dossiers/' . $r['id'])];
        }
        if ($withFinance) {
            $today = (new \DateTimeImmutable('now', local_tz()))->format('Y-m-d');
            foreach ($this->db->all(
                "SELECT i.id, i.number, i.due_date, i.total_minor - i.paid_minor AS due, i.currency, c.name FROM invoices i JOIN clients c ON c.id = i.client_id
                 WHERE i.kind = 'invoice' AND i.status IN ('validated','partial') AND i.due_date < ? ORDER BY i.due_date LIMIT 6",
                [$today]
            ) as $r) {
                $items[] = ['tone' => 'alert', 'title' => 'Facture ' . $r['number'] . ' échue', 'detail' => $r['name'] . ', reste ' . money((int) $r['due'], $r['currency']) . ' depuis le ' . d($r['due_date']), 'url' => url('factures/' . $r['id'])];
            }
            $drafts = (int) $this->db->value("SELECT COUNT(*) FROM invoices WHERE status = 'draft'");
            if ($drafts) {
                $items[] = ['tone' => 'ground', 'title' => pluralize($drafts, 'brouillon de facture', 'brouillons de facture') . ' à valider', 'detail' => 'Non numérotés, donc non exigibles', 'url' => url('factures', ['status' => 'draft'])];
            }
        }
        return $items;
    }
}
