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
use App\Core\Sequence;
use App\Core\ValidationException;

/**
 * Facturation au kilogramme.
 *  - Une ligne par colis : poids facturable × tarif/kg (+ frais autorisés), valeurs figées dans la ligne.
 *  - Double facturation impossible : invoice_items.billing_lock = 1 tant que la facture est active,
 *    et UNIQUE(parcel_id, billing_lock) en base.
 *  - Brouillon modifiable/supprimable ; facture validée immuable (annulation tracée ou avoir).
 */
final class InvoiceService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT i.*, c.name AS client_name, c.code AS client_code, c.phone AS client_phone, c.email AS client_email,
                    c.address AS client_address, c.city AS client_city, sh.reference AS shipment_reference,
                    o.number AS credit_of_number
             FROM invoices i JOIN clients c ON c.id = i.client_id
             LEFT JOIN shipments sh ON sh.id = i.shipment_id
             LEFT JOIN invoices o ON o.id = i.credit_of_invoice_id
             WHERE i.id = ?',
            [$id]
        );
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Facture introuvable.');
    }

    public function items(int $invoiceId): array
    {
        return $this->db->all('SELECT ii.*, p.status AS parcel_status FROM invoice_items ii LEFT JOIN parcels p ON p.id = ii.parcel_id WHERE ii.invoice_id = ? ORDER BY ii.id', [$invoiceId]);
    }

    public function installments(int $invoiceId): array
    {
        return $this->db->all('SELECT * FROM invoice_installments WHERE invoice_id = ? ORDER BY due_date', [$invoiceId]);
    }

    public function list(array $f, int $limit = 30, int $offset = 0): array
    {
        [$where, $params] = $this->filters($f);
        return $this->db->all(
            "SELECT i.*, c.name AS client_name,
              (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS item_count
             FROM invoices i JOIN clients c ON c.id = i.client_id WHERE $where
             ORDER BY i.created_at DESC, i.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
    }

    public function count(array $f): int
    {
        [$where, $params] = $this->filters($f);
        return (int) $this->db->value("SELECT COUNT(*) FROM invoices i JOIN clients c ON c.id = i.client_id WHERE $where", $params);
    }

    private function filters(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['q'])) {
            $w[] = "(i.number LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!' OR c.code LIKE ? ESCAPE '!')";
            array_push($p, Db::like($f['q']), Db::like($f['q']), Db::like($f['q']));
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'open') {
                $w[] = "i.status IN ('validated','partial') AND i.kind = 'invoice'";
            } elseif ($f['status'] === 'overdue') {
                $w[] = "i.status IN ('validated','partial') AND i.kind = 'invoice' AND i.due_date < ?";
                $p[] = (new \DateTimeImmutable('now', local_tz()))->format('Y-m-d');
            } elseif (isset(Domain::INVOICE_STATUSES[$f['status']])) {
                $w[] = 'i.status = ?';
                $p[] = $f['status'];
            }
        }
        if (!empty($f['client_id'])) {
            $w[] = 'i.client_id = ?';
            $p[] = (int) $f['client_id'];
        }
        return [implode(' AND ', $w), $p];
    }

    /** Colis facturables pour un client (expéditeur ou destinataire), pas encore facturés. */
    public function billableParcels(int $clientId, ?int $shipmentId = null): array
    {
        $sql = "SELECT p.id, p.tracking_code, p.description, p.billable_weight_g, p.rate_per_kg_minor, p.amount_minor, p.currency,
                       p.status, p.received_at, p.shipment_id, sh.reference AS shipment_reference,
                       CASE WHEN p.sender_id = ? THEN 'sender' ELSE 'recipient' END AS client_role
                FROM parcels p LEFT JOIN shipments sh ON sh.id = p.shipment_id
                WHERE (p.sender_id = ? OR p.recipient_id = ?)
                  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.parcel_id = p.id AND ii.billing_lock = 1)";
        $params = [$clientId, $clientId, $clientId];
        if ($shipmentId) {
            $sql .= ' AND p.shipment_id = ?';
            $params[] = $shipmentId;
        }
        return $this->db->all($sql . ' ORDER BY p.received_at DESC LIMIT 200', $params);
    }

    /**
     * Crée un brouillon pour un ou plusieurs colis d'un même client et d'une même devise.
     * $fees : [parcel_id => ['amount' => '1500', 'label' => 'Emballage']]
     */
    public function createDraft(int $clientId, array $parcelIds, array $opts = []): int
    {
        $client = $this->db->one('SELECT * FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            throw new ValidationException(['client_id' => 'Client introuvable.']);
        }
        $parcelIds = array_values(array_unique(array_map('intval', $parcelIds)));
        if (!$parcelIds) {
            throw new ValidationException(['parcel_ids' => 'Sélectionnez au moins un colis à facturer.']);
        }
        $terms = $opts['payment_terms'] ?? null ?: ($client['default_payment_terms'] ?: null);
        if ($terms !== null && !isset(Domain::PAYMENT_TERMS[$terms])) {
            throw new ValidationException(['payment_terms' => 'Modalité de paiement inconnue.']);
        }
        $taxBp = (int) ($opts['tax_rate_bp'] ?? Config::int('DEFAULT_TAX_RATE_BP', 0));
        if ($taxBp < 0 || $taxBp > 5000) {
            throw new ValidationException(['tax_rate' => 'Taux de taxe invalide.']);
        }
        $due = ($opts['due_date'] ?? '') ?: null;
        if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw new ValidationException(['due_date' => 'Date d’échéance invalide.']);
        }

        return $this->db->tx(function () use ($clientId, $parcelIds, $opts, $terms, $taxBp, $due) {
            $currency = null;
            $shipmentIds = [];
            $rows = [];
            foreach ($parcelIds as $pid) {
                $p = $this->db->one('SELECT * FROM parcels WHERE id = ?' . $this->db->forUpdate(), [$pid]);
                if (!$p) {
                    throw new BusinessException("Colis #$pid introuvable.");
                }
                if ((int) $p['sender_id'] !== $clientId && (int) $p['recipient_id'] !== $clientId) {
                    throw new BusinessException("Le colis {$p['tracking_code']} n’appartient pas à ce client (ni expéditeur, ni destinataire).");
                }
                $already = $this->db->one('SELECT i.id, i.number, i.status FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE ii.parcel_id = ? AND ii.billing_lock = 1', [$pid]);
                if ($already) {
                    throw new BusinessException(sprintf('Le colis %s est déjà facturé (%s).', $p['tracking_code'], $already['number'] ?? 'brouillon #' . $already['id']));
                }
                $currency ??= $p['currency'];
                if ($p['currency'] !== $currency) {
                    throw new BusinessException('Tous les colis d’une même facture doivent être dans la même devise.');
                }
                $shipmentIds[$p['shipment_id'] ?? 0] = true;
                $rows[] = $p;
            }
            $shipmentId = count($shipmentIds) === 1 && array_key_first($shipmentIds) ? array_key_first($shipmentIds) : null;
            $invoiceId = $this->db->insert('invoices', [
                'kind' => 'invoice', 'client_id' => $clientId, 'shipment_id' => $shipmentId, 'status' => 'draft',
                'currency' => $currency, 'payment_terms' => $terms, 'due_date' => $due, 'tax_rate_bp' => $taxBp,
                'notes' => mb_substr(trim((string) ($opts['notes'] ?? '')), 0, 500) ?: null,
                'created_by' => Auth::id(), 'created_at' => Db::now(), 'updated_at' => Db::now(),
            ]);
            foreach ($rows as $p) {
                $fee = $opts['fees'][$p['id']] ?? null;
                $feeMinor = $fee ? (Money::parse((string) ($fee['amount'] ?? ''), $currency) ?? 0) : 0;
                if ($feeMinor < 0) {
                    throw new BusinessException('Les frais supplémentaires ne peuvent pas être négatifs.');
                }
                $transport = Money::transportAmount((int) $p['billable_weight_g'], (int) $p['rate_per_kg_minor']);
                try {
                    $this->db->insert('invoice_items', [
                        'invoice_id' => $invoiceId, 'parcel_id' => $p['id'], 'tracking_code' => $p['tracking_code'],
                        'description' => mb_substr($p['description'], 0, 255),
                        'billable_weight_g' => $p['billable_weight_g'], 'rate_per_kg_minor' => $p['rate_per_kg_minor'],
                        'transport_minor' => $transport, 'fees_minor' => $feeMinor,
                        'fees_label' => $feeMinor > 0 ? (mb_substr(trim((string) ($fee['label'] ?? '')), 0, 120) ?: 'Frais supplémentaires') : null,
                        'line_total_minor' => $transport + $feeMinor, 'billing_lock' => 1,
                    ]);
                } catch (\PDOException $e) {
                    if (Db::isUniqueViolation($e)) {
                        throw new BusinessException("Le colis {$p['tracking_code']} vient d’être facturé par ailleurs.");
                    }
                    throw $e;
                }
            }
            $this->recalc($invoiceId);
            Audit::log('invoice.draft_created', 'invoice', $invoiceId, ['items' => count($rows)]);
            return $invoiceId;
        });
    }

    /** Recalcule les totaux d'un brouillon à partir de ses lignes. */
    private function recalc(int $invoiceId): void
    {
        $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        $sum = $this->db->one('SELECT COALESCE(SUM(transport_minor),0) AS t, COALESCE(SUM(fees_minor),0) AS f FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
        $subtotal = (int) $sum['t'];
        $fees = (int) $sum['f'];
        $tax = Money::tax($subtotal + $fees, (int) $inv['tax_rate_bp']);
        $this->db->update('invoices', [
            'subtotal_minor' => $subtotal, 'fees_minor' => $fees, 'tax_minor' => $tax,
            'total_minor' => $subtotal + $fees + $tax, 'updated_at' => Db::now(),
        ], 'id = ?', [$invoiceId]);
    }

    private function requireDraft(int $id): array
    {
        $inv = $this->get($id);
        if ($inv['status'] !== 'draft') {
            throw new BusinessException('Seul un brouillon peut être modifié. Une facture validée se corrige par annulation ou avoir.');
        }
        return $inv;
    }

    public function updateDraft(int $id, array $in): void
    {
        $inv = $this->requireDraft($id);
        $terms = ($in['payment_terms'] ?? '') ?: null;
        if ($terms !== null && !isset(Domain::PAYMENT_TERMS[$terms])) {
            throw new ValidationException(['payment_terms' => 'Modalité de paiement inconnue.']);
        }
        $due = ($in['due_date'] ?? '') ?: null;
        if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw new ValidationException(['due_date' => 'Date d’échéance invalide.']);
        }
        $taxBp = self::parseRateBp((string) ($in['tax_rate'] ?? '0'));
        if ($taxBp === null) {
            throw new ValidationException(['tax_rate' => 'Taux de taxe invalide (ex. 18 ou 18,5).']);
        }
        $this->db->tx(function () use ($id, $in, $terms, $due, $taxBp, $inv) {
            $this->db->update('invoices', [
                'payment_terms' => $terms, 'due_date' => $due, 'tax_rate_bp' => $taxBp,
                'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null, 'updated_at' => Db::now(),
            ], 'id = ?', [$id]);
            // Frais supplémentaires par ligne
            foreach ((array) ($in['fees'] ?? []) as $itemId => $fee) {
                $item = $this->db->one('SELECT * FROM invoice_items WHERE id = ? AND invoice_id = ?', [(int) $itemId, $id]);
                if (!$item || !is_array($fee)) {
                    continue;
                }
                $amount = Money::parse((string) ($fee['amount'] ?? ''), $inv['currency']) ?? 0;
                if ($amount < 0) {
                    throw new ValidationException(['fees' => 'Les frais supplémentaires ne peuvent pas être négatifs.']);
                }
                $this->db->update('invoice_items', [
                    'fees_minor' => $amount,
                    'fees_label' => $amount > 0 ? (mb_substr(trim((string) ($fee['label'] ?? '')), 0, 120) ?: 'Frais supplémentaires') : null,
                    'line_total_minor' => (int) $item['transport_minor'] + $amount,
                ], 'id = ?', [$item['id']]);
            }
            $this->recalc($id);
            // Échéancier (paiement en plusieurs fois)
            $this->db->run('DELETE FROM invoice_installments WHERE invoice_id = ?', [$id]);
            if ($terms === 'installments' || $terms === 'deposit') {
                foreach ((array) ($in['installments'] ?? []) as $row) {
                    if (!is_array($row) || empty($row['due_date']) || ($row['amount'] ?? '') === '') {
                        continue;
                    }
                    $amt = Money::parse((string) $row['amount'], $inv['currency']);
                    if ($amt === null || $amt <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $row['due_date'])) {
                        throw new ValidationException(['installments' => 'Échéancier : chaque ligne doit avoir une date et un montant positif.']);
                    }
                    $this->db->insert('invoice_installments', [
                        'invoice_id' => $id, 'due_date' => $row['due_date'], 'amount_minor' => $amt,
                        'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 80) ?: null,
                    ]);
                }
            }
            Audit::log('invoice.draft_updated', 'invoice', $id);
        });
    }

    public static function parseRateBp(string $s): ?int
    {
        $s = str_replace([' ', '%'], '', trim($s));
        if ($s === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $s)) {
            return null;
        }
        [$i, $f] = array_pad(explode('.', str_replace(',', '.', $s)), 2, '');
        return (int) $i * 100 + (int) str_pad($f, 2, '0');
    }

    public function removeItem(int $invoiceId, int $itemId): void
    {
        $this->requireDraft($invoiceId);
        $this->db->tx(function () use ($invoiceId, $itemId) {
            $n = $this->db->run('DELETE FROM invoice_items WHERE id = ? AND invoice_id = ?', [$itemId, $invoiceId])->rowCount();
            if (!$n) {
                throw new BusinessException('Ligne introuvable.');
            }
            $this->recalc($invoiceId);
            Audit::log('invoice.item_removed', 'invoice', $invoiceId, ['item' => $itemId]);
        });
    }

    public function deleteDraft(int $id): void
    {
        $this->requireDraft($id);
        $this->db->tx(function () use ($id) {
            $this->db->run('DELETE FROM invoice_installments WHERE invoice_id = ?', [$id]);
            $this->db->run('DELETE FROM invoice_items WHERE invoice_id = ?', [$id]);
            $this->db->run('DELETE FROM invoices WHERE id = ? AND status = ?', [$id, 'draft']);
            Audit::log('invoice.draft_deleted', 'invoice', $id);
        });
    }

    /** Validation : numérotation définitive, instantané client, facture figée. */
    public function validate(int $id): void
    {
        $this->db->tx(function () use ($id) {
            $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?' . $this->db->forUpdate(), [$id]);
            if (!$inv || $inv['status'] !== 'draft') {
                throw new BusinessException('Cette facture n’est pas un brouillon.');
            }
            $count = (int) $this->db->value('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?', [$id]);
            if ($count === 0) {
                throw new BusinessException('Une facture sans ligne ne peut pas être validée.');
            }
            $this->recalc($id);
            $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?', [$id]);
            if ((int) $inv['total_minor'] <= 0) {
                throw new BusinessException('Le total de la facture doit être supérieur à zéro.');
            }
            if (in_array($inv['payment_terms'], ['installments', 'deposit'], true)) {
                $sum = (int) $this->db->value('SELECT COALESCE(SUM(amount_minor),0) FROM invoice_installments WHERE invoice_id = ?', [$id]);
                if ($sum !== 0 && $sum !== (int) $inv['total_minor']) {
                    throw new BusinessException(sprintf(
                        'L’échéancier (%s) ne correspond pas au total (%s).',
                        Money::format($sum, $inv['currency']), Money::format((int) $inv['total_minor'], $inv['currency'])
                    ));
                }
            }
            $client = $this->db->one('SELECT code, name, phone, email, address, city, country FROM clients WHERE id = ?', [$inv['client_id']]);
            $today = (new \DateTimeImmutable('now', local_tz()))->format('Y-m-d');
            $due = $inv['due_date'] ?: (new \DateTimeImmutable($today))->modify('+' . Config::int('DEFAULT_DUE_DAYS', 15) . ' days')->format('Y-m-d');
            $this->db->update('invoices', [
                'number' => Sequence::yearly('FAC'),
                'status' => 'validated', 'issue_date' => $today, 'due_date' => $due,
                'client_snapshot' => json_encode($client, JSON_UNESCAPED_UNICODE),
                'validated_at' => Db::now(), 'validated_by' => Auth::id(), 'updated_at' => Db::now(),
            ], 'id = ?', [$id]);
            Audit::log('invoice.validated', 'invoice', $id, ['total' => (int) $inv['total_minor'], 'currency' => $inv['currency']]);
        });
        $inv = $this->get($id);
        (new NotificationService($this->db))->toClient(
            (int) $inv['client_id'], 'invoice.issued', 'Facture ' . $inv['number'],
            sprintf('%s : facture %s de %s émise, échéance le %s.', Config::get('APP_NAME', 'Diarrassouba'), $inv['number'], Money::format((int) $inv['total_minor'], $inv['currency']), d($inv['due_date'])),
            'invoice', $id
        );
    }

    /** Annulation d'une facture validée sans paiement : les colis redeviennent facturables. */
    public function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'Indiquez le motif de l’annulation (5 caractères minimum).']);
        }
        $this->db->tx(function () use ($id, $reason) {
            $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?' . $this->db->forUpdate(), [$id]);
            if (!$inv || !in_array($inv['status'], ['validated'], true) || $inv['kind'] !== 'invoice') {
                throw new BusinessException('Seule une facture validée et sans paiement peut être annulée.');
            }
            if ((int) $inv['paid_minor'] !== 0) {
                throw new BusinessException('Cette facture a reçu des paiements : annulez-les d’abord ou émettez un avoir.');
            }
            $this->db->update('invoices', [
                'status' => 'cancelled', 'cancelled_at' => Db::now(), 'cancelled_by' => Auth::id(),
                'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => Db::now(),
            ], 'id = ?', [$id]);
            $this->db->run('UPDATE invoice_items SET billing_lock = NULL WHERE invoice_id = ?', [$id]);
            Audit::log('invoice.cancelled', 'invoice', $id, ['reason' => mb_substr($reason, 0, 120)]);
        });
    }

    /**
     * Avoir total sur une facture validée/partiellement payée/payée.
     * La facture d'origine passe « Avoir émis », son solde restant n'est plus exigible
     * et ses colis redeviennent facturables. Les sommes déjà encaissées restent tracées :
     * un remboursement éventuel s'enregistre par l'annulation des paiements concernés.
     */
    public function creditNote(int $id, string $reason): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'Indiquez le motif de l’avoir (5 caractères minimum).']);
        }
        return $this->db->tx(function () use ($id, $reason) {
            $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?' . $this->db->forUpdate(), [$id]);
            if (!$inv || $inv['kind'] !== 'invoice' || !in_array($inv['status'], ['validated', 'partial', 'paid'], true)) {
                throw new BusinessException('Un avoir ne peut être émis que sur une facture validée.');
            }
            $creditId = $this->db->insert('invoices', [
                'number' => Sequence::yearly('AVO'), 'kind' => 'credit_note', 'client_id' => $inv['client_id'],
                'shipment_id' => $inv['shipment_id'], 'credit_of_invoice_id' => $id, 'status' => 'validated',
                'currency' => $inv['currency'], 'issue_date' => (new \DateTimeImmutable('now', local_tz()))->format('Y-m-d'),
                'subtotal_minor' => -(int) $inv['subtotal_minor'], 'fees_minor' => -(int) $inv['fees_minor'],
                'tax_rate_bp' => $inv['tax_rate_bp'], 'tax_minor' => -(int) $inv['tax_minor'], 'total_minor' => -(int) $inv['total_minor'],
                'client_snapshot' => $inv['client_snapshot'], 'notes' => mb_substr('Avoir sur ' . $inv['number'] . ' — ' . $reason, 0, 500),
                'validated_at' => Db::now(), 'validated_by' => Auth::id(), 'created_by' => Auth::id(),
                'created_at' => Db::now(), 'updated_at' => Db::now(),
            ]);
            foreach ($this->items($id) as $it) {
                $this->db->insert('invoice_items', [
                    'invoice_id' => $creditId, 'parcel_id' => null, 'tracking_code' => $it['tracking_code'],
                    'description' => 'Annulation : ' . mb_substr($it['description'], 0, 230),
                    'billable_weight_g' => $it['billable_weight_g'], 'rate_per_kg_minor' => $it['rate_per_kg_minor'],
                    'transport_minor' => -(int) $it['transport_minor'], 'fees_minor' => -(int) $it['fees_minor'],
                    'fees_label' => $it['fees_label'], 'line_total_minor' => -(int) $it['line_total_minor'], 'billing_lock' => null,
                ]);
            }
            $this->db->update('invoices', [
                'status' => 'credited', 'cancelled_at' => Db::now(), 'cancelled_by' => Auth::id(),
                'cancel_reason' => mb_substr($reason, 0, 255), 'updated_at' => Db::now(),
            ], 'id = ?', [$id]);
            $this->db->run('UPDATE invoice_items SET billing_lock = NULL WHERE invoice_id = ?', [$id]);
            Audit::log('invoice.credit_note', 'invoice', $id, ['credit_note_id' => $creditId]);
            return $creditId;
        });
    }

    /** Recalcule le montant payé et le statut à partir des paiements (appelé dans une transaction). */
    public function refreshPaid(int $id): void
    {
        $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?', [$id]);
        $paid = (int) $this->db->value('SELECT COALESCE(SUM(amount_minor),0) FROM payments WHERE invoice_id = ?', [$id]);
        $status = $inv['status'];
        if (in_array($status, ['validated', 'partial', 'paid'], true)) {
            $status = match (true) {
                $paid <= 0 => 'validated',
                $paid >= (int) $inv['total_minor'] => 'paid',
                default => 'partial',
            };
        }
        $this->db->update('invoices', ['paid_minor' => $paid, 'status' => $status, 'updated_at' => Db::now()], 'id = ?', [$id]);
    }

    /** Factures arrivant à échéance dans N jours : notification interne (bin/daily.php). */
    public function dueSoon(int $days = 3): array
    {
        $today = (new \DateTimeImmutable('now', local_tz()));
        return $this->db->all(
            "SELECT i.*, c.name AS client_name FROM invoices i JOIN clients c ON c.id = i.client_id
             WHERE i.kind = 'invoice' AND i.status IN ('validated','partial') AND i.due_date BETWEEN ? AND ? ORDER BY i.due_date",
            [$today->format('Y-m-d'), $today->modify("+$days days")->format('Y-m-d')]
        );
    }
}
