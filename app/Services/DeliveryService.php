<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Db;
use App\Core\Domain;
use App\Core\Upload;
use App\Core\ValidationException;

/**
 * Remise des colis : retrait au dépôt ou livraison à domicile.
 * Le statut « Livré ou récupéré » n'est posé qu'après confirmation explicite
 * (nom de la personne qui reçoit + case de confirmation), éventuellement avec preuve.
 */
final class DeliveryService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    /** Un agent de livraison ne voit que ses propres tournées. */
    private function scope(): array
    {
        if (Auth::role() === 'courier') {
            return [' AND d.agent_id = ?', [Auth::id()]];
        }
        return ['', []];
    }

    public function find(int $id): ?array
    {
        [$scope, $sp] = $this->scope();
        return $this->db->one(
            "SELECT d.*, p.tracking_code, p.description, p.status AS parcel_status, p.pieces, p.weight_g,
                    p.delivery_contact_name, p.delivery_contact_phone, r.name AS recipient_client, r.phone AS recipient_phone,
                    r.address AS recipient_address, u.name AS agent_name, cu.name AS confirmed_by_name
             FROM deliveries d JOIN parcels p ON p.id = d.parcel_id JOIN clients r ON r.id = p.recipient_id
             LEFT JOIN users u ON u.id = d.agent_id LEFT JOIN users cu ON cu.id = d.confirmed_by
             WHERE d.id = ?$scope",
            array_merge([$id], $sp)
        );
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Livraison introuvable.');
    }

    public function list(array $f, int $limit = 40, int $offset = 0): array
    {
        [$scope, $sp] = $this->scope();
        $w = '1=1' . $scope;
        $p = $sp;
        if (!empty($f['status'])) {
            if ($f['status'] === 'open') {
                $w .= " AND d.status IN ('planned','in_progress')";
            } elseif (isset(Domain::DELIVERY_STATUSES[$f['status']])) {
                $w .= ' AND d.status = ?';
                $p[] = $f['status'];
            }
        }
        if (!empty($f['mode']) && isset(Domain::DELIVERY_MODES[$f['mode']])) {
            $w .= ' AND d.mode = ?';
            $p[] = $f['mode'];
        }
        return $this->db->all(
            "SELECT d.*, p.tracking_code, p.description, p.delivery_contact_name, r.name AS recipient_client, r.phone AS recipient_phone, u.name AS agent_name
             FROM deliveries d JOIN parcels p ON p.id = d.parcel_id JOIN clients r ON r.id = p.recipient_id
             LEFT JOIN users u ON u.id = d.agent_id
             WHERE $w ORDER BY CASE d.status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END, d.planned_date, d.id DESC
             LIMIT $limit OFFSET $offset",
            $p
        );
    }

    public function forParcel(int $parcelId): array
    {
        return $this->db->all(
            'SELECT d.*, u.name AS agent_name, cu.name AS confirmed_by_name,
                (SELECT COUNT(*) FROM delivery_proofs dp WHERE dp.delivery_id = d.id) AS proof_count
             FROM deliveries d LEFT JOIN users u ON u.id = d.agent_id LEFT JOIN users cu ON cu.id = d.confirmed_by
             WHERE d.parcel_id = ? ORDER BY d.id DESC',
            [$parcelId]
        );
    }

    public function agents(): array
    {
        return $this->db->all("SELECT id, name, role_code FROM users WHERE is_active = 1 AND role_code IN ('courier','logistics','manager','admin') ORDER BY name");
    }

    /** Colis prêts à être remis (arrivés, au dépôt, ou après une tentative échouée). */
    public function readyParcels(): array
    {
        return $this->db->all(
            "SELECT p.id, p.tracking_code, p.description, p.status, r.name AS recipient_name
             FROM parcels p JOIN clients r ON r.id = p.recipient_id
             WHERE p.status IN ('arrived','at_depot','delivery_failed')
               AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.parcel_id = p.id AND d.status IN ('planned','in_progress'))
             ORDER BY p.status_changed_at LIMIT 200"
        );
    }

    public function plan(int $parcelId, array $in): int
    {
        $mode = (string) ($in['mode'] ?? '');
        $errors = [];
        if (!isset(Domain::DELIVERY_MODES[$mode])) {
            $errors['mode'] = 'Choisissez retrait au dépôt ou livraison.';
        }
        $agentId = (int) ($in['agent_id'] ?? 0) ?: null;
        if ($mode === 'delivery' && !$agentId) {
            $errors['agent_id'] = 'Désignez le responsable de la livraison.';
        }
        if ($agentId && !$this->db->value("SELECT id FROM users WHERE id = ? AND is_active = 1", [$agentId])) {
            $errors['agent_id'] = 'Agent introuvable.';
        }
        $planned = ($in['planned_date'] ?? '') ?: null;
        if ($planned !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $planned)) {
            $errors['planned_date'] = 'Date invalide.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $this->db->tx(function () use ($parcelId, $mode, $agentId, $planned, $in) {
            $p = $this->db->one('SELECT * FROM parcels WHERE id = ?' . $this->db->forUpdate(), [$parcelId]);
            if (!$p) {
                throw new BusinessException('Colis introuvable.');
            }
            if (!in_array($p['status'], ['arrived', 'at_depot', 'delivery_failed'], true)) {
                throw new BusinessException('Le colis doit être arrivé à destination (ou au dépôt) pour planifier sa remise.');
            }
            if ($this->db->value("SELECT id FROM deliveries WHERE parcel_id = ? AND status IN ('planned','in_progress')", [$parcelId])) {
                throw new BusinessException('Une remise est déjà planifiée pour ce colis.');
            }
            $attempt = (int) $this->db->value('SELECT COUNT(*) FROM deliveries WHERE parcel_id = ?', [$parcelId]) + 1;
            $id = $this->db->insert('deliveries', [
                'parcel_id' => $parcelId, 'mode' => $mode, 'agent_id' => $agentId, 'planned_date' => $planned,
                'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 255) ?: null,
                'status' => 'planned', 'attempt_no' => $attempt, 'created_by' => Auth::id(),
                'created_at' => Db::now(), 'updated_at' => Db::now(),
            ]);
            Audit::log('delivery.planned', 'delivery', $id, ['parcel_id' => $parcelId, 'mode' => $mode]);
            return $id;
        });
    }

    private function lockOwn(int $id): array
    {
        $d = $this->db->one('SELECT * FROM deliveries WHERE id = ?' . $this->db->forUpdate(), [$id]);
        if (!$d) {
            throw new BusinessException('Livraison introuvable.');
        }
        if (Auth::role() === 'courier' && (int) $d['agent_id'] !== Auth::id()) {
            throw new \App\Core\HttpException(403);
        }
        return $d;
    }

    /** Départ en tournée (livraison) : le colis passe « En cours de livraison ». */
    public function start(int $id): void
    {
        $d = $this->db->tx(function () use ($id) {
            $d = $this->lockOwn($id);
            if ($d['status'] !== 'planned' || $d['mode'] !== 'delivery') {
                throw new BusinessException('Seule une livraison planifiée peut être démarrée.');
            }
            $this->db->update('deliveries', ['status' => 'in_progress', 'updated_at' => Db::now()], 'id = ?', [$id]);
            Audit::log('delivery.started', 'delivery', $id);
            return $d;
        });
        $p = $this->db->one('SELECT status FROM parcels WHERE id = ?', [$d['parcel_id']]);
        if ($p['status'] !== 'out_for_delivery') {
            $from = $p['status'] === 'delivery_failed' ? 'at_depot' : null;
            $parcels = new ParcelService($this->db);
            if ($from) {
                $parcels->changeStatus((int) $d['parcel_id'], 'at_depot', ['public_comment' => 'Nouvelle tentative de livraison programmée.', 'silent' => true]);
            }
            $parcels->changeStatus((int) $d['parcel_id'], 'out_for_delivery', ['public_comment' => 'Le livreur est en route.']);
        }
    }

    public function confirm(int $id, array $in, ?array $proof): void
    {
        $name = trim((string) ($in['recipient_name'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['recipient_name'] = 'Saisissez le nom de la personne qui reçoit le colis.';
        }
        if (empty($in['confirm'])) {
            $errors['confirm'] = 'Cochez la case pour confirmer la remise du colis.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $d = $this->db->tx(function () use ($id, $in, $name, $proof) {
            $d = $this->lockOwn($id);
            if (!in_array($d['status'], ['planned', 'in_progress'], true)) {
                throw new BusinessException('Cette remise est déjà clôturée.');
            }
            if ($d['mode'] === 'delivery' && $d['status'] !== 'in_progress') {
                throw new BusinessException('Démarrez la livraison avant de confirmer la remise.');
            }
            $stored = Upload::store($proof, 'proofs');
            if ($stored) {
                $this->db->insert('delivery_proofs', $stored + ['delivery_id' => $id, 'uploaded_by' => Auth::id(), 'created_at' => Db::now()]);
            }
            $this->db->update('deliveries', [
                'status' => 'done', 'recipient_name' => mb_substr($name, 0, 150),
                'recipient_id_note' => mb_substr(trim((string) ($in['recipient_id_note'] ?? '')), 0, 120) ?: null,
                'confirmed_at' => Db::now(), 'confirmed_by' => Auth::id(), 'updated_at' => Db::now(),
            ], 'id = ?', [$id]);
            $p = $this->db->one('SELECT status FROM parcels WHERE id = ?', [$d['parcel_id']]);
            // Retrait au dépôt depuis « Arrivé » : on passe d'abord par « Disponible au dépôt ».
            if ($d['mode'] === 'pickup' && $p['status'] === 'arrived') {
                (new ParcelService($this->db))->changeStatus((int) $d['parcel_id'], 'at_depot', ['silent' => true]);
            }
            if ($d['mode'] === 'pickup' && $p['status'] === 'delivery_failed') {
                (new ParcelService($this->db))->changeStatus((int) $d['parcel_id'], 'at_depot', ['silent' => true]);
            }
            Audit::log('delivery.confirmed', 'delivery', $id, ['parcel_id' => (int) $d['parcel_id'], 'proof' => (bool) $stored]);
            return $d;
        });
        (new ParcelService($this->db))->changeStatus((int) $d['parcel_id'], 'delivered', [
            'via' => 'delivery',
            'public_comment' => $d['mode'] === 'pickup' ? 'Colis récupéré au dépôt.' : 'Colis remis au destinataire.',
        ]);
    }

    public function fail(int $id, string $incident): void
    {
        if (mb_strlen(trim($incident)) < 3) {
            throw new ValidationException(['incident' => 'Décrivez le motif de l’échec (absent, adresse introuvable…).']);
        }
        $d = $this->db->tx(function () use ($id, $incident) {
            $d = $this->lockOwn($id);
            if (!in_array($d['status'], ['planned', 'in_progress'], true)) {
                throw new BusinessException('Cette remise est déjà clôturée.');
            }
            $this->db->update('deliveries', ['status' => 'failed', 'incident' => mb_substr(trim($incident), 0, 500), 'updated_at' => Db::now()], 'id = ?', [$id]);
            Audit::log('delivery.failed', 'delivery', $id);
            return $d;
        });
        $p = $this->db->one('SELECT status FROM parcels WHERE id = ?', [$d['parcel_id']]);
        if ($p['status'] === 'out_for_delivery') {
            (new ParcelService($this->db))->changeStatus((int) $d['parcel_id'], 'delivery_failed', [
                'public_comment' => 'Tentative de livraison infructueuse, nous allons vous recontacter.',
                'internal_comment' => mb_substr(trim($incident), 0, 500),
            ]);
        }
    }

    public function cancel(int $id): void
    {
        $this->db->tx(function () use ($id) {
            $d = $this->lockOwn($id);
            if ($d['status'] !== 'planned') {
                throw new BusinessException('Seule une remise planifiée (non démarrée) peut être annulée.');
            }
            $this->db->update('deliveries', ['status' => 'cancelled', 'updated_at' => Db::now()], 'id = ?', [$id]);
            Audit::log('delivery.cancelled', 'delivery', $id);
        });
    }

    public function proofs(int $deliveryId): array
    {
        return $this->db->all('SELECT * FROM delivery_proofs WHERE delivery_id = ?', [$deliveryId]);
    }
}
