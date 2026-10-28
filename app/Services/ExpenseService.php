<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Db;
use App\Core\Domain;
use App\Core\Money;
use App\Core\Upload;
use App\Core\ValidationException;
use App\Core\Validator;

/**
 * Dépenses opérationnelles.
 *  - Billets d'avion (déplacement d'un accompagnateur) distincts du fret aérien (transport des colis).
 *  - Rattachement à un dossier, ou aux frais généraux (shipment_id NULL).
 *  - Anti double comptabilisation : référence de billet / de pièce unique par catégorie
 *    (dedupe_key), et alerte si même montant, même date, même catégorie, même dossier.
 *  - Le « Frais de fret prévus » du dossier est un devis : seules les dépenses comptent dans la marge.
 */
final class ExpenseService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT e.*, s.reference AS shipment_reference, u.name AS created_by_name
             FROM expenses e LEFT JOIN shipments s ON s.id = e.shipment_id LEFT JOIN users u ON u.id = e.created_by WHERE e.id = ?',
            [$id]
        );
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Dépense introuvable.');
    }

    public function list(array $f, int $limit = 30, int $offset = 0): array
    {
        [$where, $p] = $this->filters($f);
        return $this->db->all(
            "SELECT e.*, s.reference AS shipment_reference,
               (SELECT COUNT(*) FROM expense_attachments a WHERE a.expense_id = e.id) AS attachment_count
             FROM expenses e LEFT JOIN shipments s ON s.id = e.shipment_id WHERE $where
             ORDER BY e.expense_date DESC, e.id DESC LIMIT $limit OFFSET $offset",
            $p
        );
    }

    public function count(array $f): int
    {
        [$where, $p] = $this->filters($f);
        return (int) $this->db->value("SELECT COUNT(*) FROM expenses e WHERE $where", $p);
    }

    /** Totaux par devise sur le filtre courant (dépenses actives). */
    public function totals(array $f): array
    {
        [$where, $p] = $this->filters($f);
        return $this->db->all("SELECT e.currency, SUM(e.amount_minor) AS total FROM expenses e WHERE $where AND e.status = 'active' GROUP BY e.currency", $p);
    }

    private function filters(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['category']) && isset(Domain::EXPENSE_CATEGORIES[$f['category']])) {
            $w[] = 'e.category = ?';
            $p[] = $f['category'];
        }
        if (!empty($f['shipment_id'])) {
            if ($f['shipment_id'] === 'general') {
                $w[] = 'e.shipment_id IS NULL';
            } else {
                $w[] = 'e.shipment_id = ?';
                $p[] = (int) $f['shipment_id'];
            }
        }
        if (!empty($f['from'])) {
            $w[] = 'e.expense_date >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'e.expense_date <= ?';
            $p[] = $f['to'];
        }
        if (!empty($f['q'])) {
            $w[] = "(e.description LIKE ? ESCAPE '!' OR e.ticket_reference LIKE ? ESCAPE '!' OR e.beneficiary LIKE ? ESCAPE '!' OR e.supplier_reference LIKE ? ESCAPE '!')";
            array_push($p, Db::like($f['q']), Db::like($f['q']), Db::like($f['q']), Db::like($f['q']));
        }
        if (empty($f['with_cancelled'])) {
            $w[] = "e.status = 'active'";
        }
        return [implode(' AND ', $w), $p];
    }

    private function input(array $in): array
    {
        $currency = (string) ($in['currency'] ?? 'XOF');
        $data = [
            'category' => (string) ($in['category'] ?? ''),
            'shipment_id' => (int) ($in['shipment_id'] ?? 0) ?: null,
            'description' => trim((string) ($in['description'] ?? '')),
            'ticket_reference' => strtoupper(trim((string) ($in['ticket_reference'] ?? ''))) ?: null,
            'airline' => trim((string) ($in['airline'] ?? '')) ?: null,
            'beneficiary' => trim((string) ($in['beneficiary'] ?? '')) ?: null,
            'supplier_reference' => trim((string) ($in['supplier_reference'] ?? '')) ?: null,
            'expense_date' => (string) ($in['expense_date'] ?? ''),
            'amount_minor' => Money::parse($in['amount'] ?? '', $currency),
            'currency' => $currency,
            'payment_status' => (string) ($in['payment_status'] ?? 'unpaid'),
        ];
        $v = (new Validator())
            ->in('category', $data['category'], array_keys(Domain::EXPENSE_CATEGORIES), 'Catégorie')
            ->required('description', $data['description'], 'Le libellé')
            ->max('description', $data['description'], 255, 'Libellé')
            ->date('expense_date', $data['expense_date'], 'La date', true)
            ->in('currency', $currency, array_keys(Domain::CURRENCIES), 'Devise')
            ->in('payment_status', $data['payment_status'], array_keys(Domain::EXPENSE_PAYMENT_STATUS), 'Statut de paiement')
            ->positive('amount', $data['amount_minor'], 'Le montant');
        if ($data['category'] === 'air_ticket') {
            $v->required('ticket_reference', $data['ticket_reference'], 'La référence du billet')
              ->required('airline', $data['airline'], 'La compagnie');
        }
        if ($data['shipment_id'] && !$this->db->value('SELECT id FROM shipments WHERE id = ?', [$data['shipment_id']])) {
            $v->add('shipment_id', 'Dossier introuvable.');
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data['dedupe_key'] = match (true) {
            $data['category'] === 'air_ticket' && $data['ticket_reference'] => 'ticket:' . $data['ticket_reference'],
            $data['supplier_reference'] !== null => $data['category'] . ':' . mb_strtoupper($data['supplier_reference']),
            default => null,
        };
        return $data;
    }

    /** Dépenses quasi identiques déjà saisies (même catégorie, montant, date, dossier). */
    public function similar(array $data, ?int $excludeId = null): array
    {
        $sql = "SELECT id, description, amount_minor, currency, expense_date FROM expenses
                WHERE status = 'active' AND category = ? AND amount_minor = ? AND currency = ? AND expense_date = ?
                AND " . ($data['shipment_id'] ? 'shipment_id = ?' : 'shipment_id IS NULL');
        $p = [$data['category'], $data['amount_minor'], $data['currency'], $data['expense_date']];
        if ($data['shipment_id']) {
            $p[] = $data['shipment_id'];
        }
        if ($excludeId) {
            $sql .= ' AND id <> ?';
            $p[] = $excludeId;
        }
        return $this->db->all($sql . ' LIMIT 3', $p);
    }

    public function create(array $in, ?array $file, bool $confirmSimilar = false): int
    {
        $data = $this->input($in);
        if (!$confirmSimilar && ($sim = $this->similar($data))) {
            throw new ValidationException(['similar' => sprintf(
                'Une dépense identique existe déjà (%s, %s). Cochez « Il s’agit bien d’une autre dépense » pour l’enregistrer quand même.',
                $sim[0]['description'], Money::format((int) $sim[0]['amount_minor'], $sim[0]['currency'])
            )]);
        }
        return $this->db->tx(function () use ($data, $file) {
            $data['status'] = 'active';
            $data['created_by'] = Auth::id();
            $data['created_at'] = $data['updated_at'] = Db::now();
            try {
                $id = $this->db->insert('expenses', $data);
            } catch (\PDOException $e) {
                if (Db::isUniqueViolation($e)) {
                    throw new ValidationException([$data['category'] === 'air_ticket' ? 'ticket_reference' : 'supplier_reference' => 'Cette référence est déjà enregistrée : la dépense existe déjà.']);
                }
                throw $e;
            }
            $this->attach($id, $file);
            Audit::log('expense.created', 'expense', $id, ['category' => $data['category'], 'amount' => $data['amount_minor'], 'currency' => $data['currency']]);
            return $id;
        });
    }

    public function update(int $id, array $in, ?array $file): void
    {
        $e = $this->get($id);
        if ($e['status'] !== 'active') {
            throw new BusinessException('Dépense annulée : modification impossible.');
        }
        $data = $this->input($in) + ['updated_at' => Db::now()];
        $this->db->tx(function () use ($id, $data, $file) {
            try {
                $this->db->update('expenses', $data, 'id = ?', [$id]);
            } catch (\PDOException $e) {
                if (Db::isUniqueViolation($e)) {
                    throw new ValidationException(['ticket_reference' => 'Cette référence est déjà utilisée par une autre dépense.']);
                }
                throw $e;
            }
            $this->attach($id, $file);
            Audit::log('expense.updated', 'expense', $id, ['amount' => $data['amount_minor']]);
        });
    }

    public function cancel(int $id, string $reason): void
    {
        $e = $this->get($id);
        if ($e['status'] !== 'active') {
            throw new BusinessException('Dépense déjà annulée.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'Indiquez le motif de l’annulation (5 caractères minimum).']);
        }
        // La clé anti-doublon est libérée pour permettre une ressaisie corrigée.
        $this->db->update('expenses', ['status' => 'cancelled', 'dedupe_key' => null, 'cancel_reason' => mb_substr(trim($reason), 0, 255), 'updated_at' => Db::now()], 'id = ?', [$id]);
        Audit::log('expense.cancelled', 'expense', $id, ['reason' => mb_substr(trim($reason), 0, 120)]);
    }

    public function attach(int $expenseId, ?array $file): void
    {
        $stored = Upload::store($file, 'expenses');
        if ($stored) {
            $this->db->insert('expense_attachments', $stored + ['expense_id' => $expenseId, 'uploaded_by' => Auth::id(), 'created_at' => Db::now()]);
        }
    }

    public function attachments(int $expenseId): array
    {
        return $this->db->all('SELECT * FROM expense_attachments WHERE expense_id = ? ORDER BY id', [$expenseId]);
    }
}
