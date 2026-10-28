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
 * Encaissements. Règles :
 *  - montant > 0 et ≤ solde restant (pas de trop-perçu) ;
 *  - référence de transaction unique par moyen de paiement ;
 *  - jeton de formulaire unique (un double clic n'enregistre qu'un paiement) ;
 *  - toute correction = écriture d'annulation tracée (jamais de suppression).
 *  - « confirmation = manual » : saisi par un agent. « provider » est réservé à un
 *    retour automatique d'un prestataire (non branché : aucune confirmation n'est simulée).
 */
final class PaymentService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT p.*, i.number AS invoice_number, i.client_id, i.total_minor AS invoice_total, i.paid_minor AS invoice_paid,
                    c.name AS client_name, c.code AS client_code, u.name AS recorded_by_name, r.receipt_number AS reversal_of_receipt
             FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN clients c ON c.id = i.client_id
             LEFT JOIN users u ON u.id = p.recorded_by LEFT JOIN payments r ON r.id = p.reversal_of_id
             WHERE p.id = ?',
            [$id]
        );
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Paiement introuvable.');
    }

    public function forInvoice(int $invoiceId): array
    {
        return $this->db->all('SELECT p.*, u.name AS recorded_by_name FROM payments p LEFT JOIN users u ON u.id = p.recorded_by WHERE p.invoice_id = ? ORDER BY p.paid_at, p.id', [$invoiceId]);
    }

    public function list(array $f, int $limit = 30, int $offset = 0): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['q'])) {
            $w[] = "(pay.receipt_number LIKE ? ESCAPE '!' OR pay.reference LIKE ? ESCAPE '!' OR i.number LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!')";
            array_push($p, Db::like($f['q']), Db::like($f['q']), Db::like($f['q']), Db::like($f['q']));
        }
        if (!empty($f['method']) && isset(Domain::PAYMENT_METHODS[$f['method']])) {
            $w[] = 'pay.method = ?';
            $p[] = $f['method'];
        }
        $where = implode(' AND ', $w);
        return $this->db->all(
            "SELECT pay.*, i.number AS invoice_number, c.name AS client_name FROM payments pay
             JOIN invoices i ON i.id = pay.invoice_id JOIN clients c ON c.id = i.client_id
             WHERE $where ORDER BY pay.paid_at DESC, pay.id DESC LIMIT $limit OFFSET $offset",
            $p
        );
    }

    public function record(int $invoiceId, array $in): int
    {
        $method = (string) ($in['method'] ?? '');
        $reference = trim((string) ($in['reference'] ?? '')) ?: null;
        $token = (string) ($in['form_token'] ?? '');
        $errors = [];
        if (!isset(Domain::PAYMENT_METHODS[$method])) {
            $errors['method'] = 'Choisissez le moyen de paiement.';
        }
        if ($method !== 'cash' && $method !== '' && $reference === null) {
            $errors['reference'] = 'La référence de transaction est obligatoire pour ce moyen de paiement.';
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $errors['form_token'] = 'Formulaire expiré : rechargez la page.';
        }
        $paidAt = local_to_utc(($in['paid_at'] ?? '') ?: null) ?? Db::now();
        if ($paidAt > gmdate('Y-m-d H:i:s', time() + 600)) {
            $errors['paid_at'] = 'La date de paiement ne peut pas être dans le futur.';
        }

        $id = $this->db->tx(function () use ($invoiceId, $in, $method, $reference, $token, $paidAt, &$errors) {
            $inv = $this->db->one('SELECT * FROM invoices WHERE id = ?' . $this->db->forUpdate(), [$invoiceId]);
            if (!$inv) {
                throw new BusinessException('Facture introuvable.');
            }
            if ($inv['kind'] !== 'invoice' || !in_array($inv['status'], ['validated', 'partial'], true)) {
                throw new BusinessException('Un paiement ne peut être enregistré que sur une facture validée non soldée.');
            }
            $amount = Money::parse((string) ($in['amount'] ?? ''), $inv['currency']);
            $balance = (int) $inv['total_minor'] - (int) $inv['paid_minor'];
            if ($amount === null || $amount <= 0) {
                $errors['amount'] = 'Montant invalide.';
            } elseif ($amount > $balance) {
                $errors['amount'] = 'Montant supérieur au solde restant (' . Money::format($balance, $inv['currency']) . ').';
            }
            if ($this->db->value('SELECT id FROM payments WHERE form_token = ?', [$token])) {
                throw new BusinessException('Ce paiement a déjà été enregistré (double envoi du formulaire).');
            }
            if ($reference && ($dup = $this->db->one('SELECT receipt_number FROM payments WHERE method = ? AND reference = ?', [$method, $reference]))) {
                $errors['reference'] = 'Cette référence de transaction est déjà enregistrée (reçu ' . $dup['receipt_number'] . ').';
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            try {
                $id = $this->db->insert('payments', [
                    'invoice_id' => $invoiceId, 'receipt_number' => Sequence::yearly('REC'),
                    'amount_minor' => $amount, 'currency' => $inv['currency'], 'method' => $method, 'reference' => $reference,
                    'confirmation' => 'manual', 'paid_at' => $paidAt,
                    'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255) ?: null,
                    'form_token' => $token, 'recorded_by' => Auth::id(), 'created_at' => Db::now(),
                ]);
            } catch (\PDOException $e) {
                if (Db::isUniqueViolation($e)) {
                    throw new BusinessException('Paiement en double détecté : rien n’a été enregistré.');
                }
                throw $e;
            }
            (new InvoiceService($this->db))->refreshPaid($invoiceId);
            Audit::log('payment.recorded', 'payment', $id, ['invoice_id' => $invoiceId, 'amount' => $amount, 'method' => $method]);
            return $id;
        });

        $pay = $this->get($id);
        $notif = new NotificationService($this->db);
        $notif->staff(['manager', 'cashier'], 'payment.recorded', sprintf('Paiement %s : %s', $pay['receipt_number'], Money::format((int) $pay['amount_minor'], $pay['currency'])), $pay['client_name'], 'invoice', $invoiceId, Auth::id());
        $notif->toClient((int) $pay['client_id'], 'payment.recorded', 'Reçu ' . $pay['receipt_number'], sprintf(
            '%s : paiement de %s reçu pour la facture %s (reçu %s). Merci.',
            Config::get('APP_NAME', 'Diarrassouba'), Money::format((int) $pay['amount_minor'], $pay['currency']), $pay['invoice_number'], $pay['receipt_number']
        ), 'payment', $id);
        return $id;
    }

    /** Annulation d'un paiement erroné ou remboursé : écriture négative liée, motif obligatoire. */
    public function reverse(int $paymentId, string $reason): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'Indiquez le motif de l’annulation (5 caractères minimum).']);
        }
        return $this->db->tx(function () use ($paymentId, $reason) {
            $pay = $this->db->one('SELECT * FROM payments WHERE id = ?' . $this->db->forUpdate(), [$paymentId]);
            if (!$pay || (int) $pay['amount_minor'] <= 0 || $pay['reversal_of_id']) {
                throw new BusinessException('Ce paiement ne peut pas être annulé.');
            }
            if ($pay['reversed_by_id']) {
                throw new BusinessException('Ce paiement est déjà annulé.');
            }
            $this->db->one('SELECT id FROM invoices WHERE id = ?' . $this->db->forUpdate(), [$pay['invoice_id']]);
            try {
                $revId = $this->db->insert('payments', [
                    'invoice_id' => $pay['invoice_id'], 'receipt_number' => Sequence::yearly('ANN'),
                    'amount_minor' => -(int) $pay['amount_minor'], 'currency' => $pay['currency'], 'method' => $pay['method'],
                    'reference' => null, 'confirmation' => 'manual', 'paid_at' => Db::now(),
                    'notes' => mb_substr('Annulation de ' . $pay['receipt_number'] . ' — ' . $reason, 0, 255),
                    'reversal_of_id' => $paymentId, 'recorded_by' => Auth::id(), 'created_at' => Db::now(),
                ]);
            } catch (\PDOException $e) {
                if (Db::isUniqueViolation($e)) {
                    throw new BusinessException('Ce paiement est déjà annulé.');
                }
                throw $e;
            }
            $this->db->update('payments', ['reversed_by_id' => $revId], 'id = ?', [$paymentId]);
            (new InvoiceService($this->db))->refreshPaid((int) $pay['invoice_id']);
            Audit::log('payment.reversed', 'payment', $paymentId, ['reversal_id' => $revId, 'amount' => (int) $pay['amount_minor'], 'reason' => mb_substr($reason, 0, 120)]);
            return $revId;
        });
    }
}
