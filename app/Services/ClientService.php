<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Db;
use App\Core\Domain;
use App\Core\Sequence;
use App\Core\ValidationException;
use App\Core\Validator;

/** Fiches clients (expéditeurs et destinataires sont des clients). */
final class ClientService
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM clients WHERE id = ?', [$id]);
    }

    public function get(int $id): array
    {
        return $this->find($id) ?? throw new BusinessException('Client introuvable.');
    }

    /** Rôles d'un client dans les colis : filtre de la liste des clients. */
    public const ROLES = ['sender' => 'Expéditeurs', 'recipient' => 'Destinataires'];

    /** Recherche par nom, code client ou téléphone ; $role limite aux expéditeurs ou aux destinataires. */
    public function search(string $q, int $limit = 30, int $offset = 0, bool $withArchived = false, string $role = ''): array
    {
        [$where, $params] = $this->searchWhere($q, $withArchived, $role);
        $sql = "SELECT c.*,
                  (SELECT COUNT(*) FROM parcels p WHERE p.sender_id = c.id OR p.recipient_id = c.id) AS parcel_count,
                  (SELECT COUNT(*) FROM parcels p WHERE p.sender_id = c.id) AS sent_count,
                  (SELECT COUNT(*) FROM parcels p WHERE p.recipient_id = c.id) AS received_count
                FROM clients c WHERE $where ORDER BY c.updated_at DESC LIMIT $limit OFFSET $offset";
        return $this->db->all($sql, $params);
    }

    public function count(string $q, bool $withArchived = false, string $role = ''): int
    {
        [$where, $params] = $this->searchWhere($q, $withArchived, $role);
        return (int) $this->db->value("SELECT COUNT(*) FROM clients c WHERE $where", $params);
    }

    private function searchWhere(string $q, bool $withArchived, string $role = ''): array
    {
        $where = $withArchived ? '1=1' : 'c.archived_at IS NULL';
        if ($role === 'sender' || $role === 'recipient') {
            $where .= " AND EXISTS (SELECT 1 FROM parcels p WHERE p.{$role}_id = c.id)";
        }
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $where .= " AND (c.name LIKE ? ESCAPE '!' OR c.code LIKE ? ESCAPE '!'";
            $params[] = Db::like($q);
            $params[] = Db::like($q);
            if (strlen($digits) >= 4) {
                $where .= " OR c.phone_normalized LIKE ? ESCAPE '!'";
                $params[] = Db::like($digits);
            }
            $where .= ')';
        }
        return [$where, $params];
    }

    /** Versements d'un client (relevé partagé), du plus récent au plus ancien. */
    public function statementPayments(int $id): array
    {
        return $this->db->all(
            "SELECT pay.paid_at, pay.method, pay.amount_minor, pay.currency, pay.receipt_number, i.number AS invoice_number
             FROM payments pay JOIN invoices i ON i.id = pay.invoice_id
             WHERE i.client_id = ? AND i.status NOT IN ('draft','cancelled') ORDER BY pay.paid_at DESC, pay.id DESC",
            [$id]
        );
    }

    /** Factures validées d'un client avec les codes des colis facturés (relevé partagé). */
    public function statementInvoices(int $id): array
    {
        return $this->db->all(
            "SELECT i.id, i.number, i.status, i.issue_date, i.due_date, i.currency, i.total_minor, i.paid_minor,
                    (SELECT GROUP_CONCAT(ii.tracking_code) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS codes
             FROM invoices i WHERE i.client_id = ? AND i.kind = 'invoice' AND i.status NOT IN ('draft','cancelled')
             ORDER BY i.issue_date DESC, i.id DESC",
            [$id]
        );
    }

    /** Rôle du client dans les colis : 'sender', 'recipient', 'both' ou ''. */
    public function role(int $id): string
    {
        $sent = (int) $this->db->value('SELECT COUNT(*) FROM parcels WHERE sender_id = ?', [$id]);
        $received = (int) $this->db->value('SELECT COUNT(*) FROM parcels WHERE recipient_id = ?', [$id]);
        return $sent && $received ? 'both' : ($sent ? 'sender' : ($received ? 'recipient' : ''));
    }

    /** Doublons probables : même téléphone, ou même nom exact. */
    public function duplicates(string $name, ?string $phone, ?int $excludeId = null): array
    {
        $norm = normalize_phone($phone);
        $conds = ['LOWER(name) = ?'];
        $params = [mb_strtolower(trim($name))];
        if (strlen($norm) >= 8) {
            $conds[] = 'phone_normalized = ?';
            $params[] = $norm;
        }
        $sql = 'SELECT id, code, name, phone, city FROM clients WHERE archived_at IS NULL AND (' . implode(' OR ', $conds) . ')';
        if ($excludeId) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        return $this->db->all($sql . ' LIMIT 5', $params);
    }

    private function validate(array $in): array
    {
        $data = [
            'kind' => ($in['kind'] ?? 'person') === 'company' ? 'company' : 'person',
            'name' => trim((string) ($in['name'] ?? '')),
            'phone' => trim((string) ($in['phone'] ?? '')) ?: null,
            'phone_alt' => trim((string) ($in['phone_alt'] ?? '')) ?: null,
            'email' => mb_strtolower(trim((string) ($in['email'] ?? ''))) ?: null,
            'country' => trim((string) ($in['country'] ?? '')) ?: null,
            'city' => trim((string) ($in['city'] ?? '')) ?: null,
            'address' => trim((string) ($in['address'] ?? '')) ?: null,
            'default_payment_terms' => trim((string) ($in['default_payment_terms'] ?? '')) ?: null,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
        ];
        $v = (new Validator())
            ->required('name', $data['name'], 'Le nom')
            ->max('name', $data['name'], 150, 'Nom')
            ->required('phone', $data['phone'], 'Le téléphone')
            ->phone('phone', $data['phone'])
            ->phone('phone_alt', $data['phone_alt'], 'Second téléphone')
            ->email('email', $data['email'])
            ->in('country', $data['country'], array_keys(Domain::COUNTRIES), 'Pays', true)
            ->in('default_payment_terms', $data['default_payment_terms'], array_keys(Domain::PAYMENT_TERMS), 'Modalité de paiement', true)
            ->max('address', $data['address'], 255, 'Adresse')
            ->max('notes', $data['notes'], 2000, 'Notes');
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data['phone_normalized'] = normalize_phone($data['phone']);
        return $data;
    }

    /**
     * Crée un client. Si un doublon probable existe et que la création n'est pas
     * explicitement confirmée, une ValidationException « duplicate » est levée.
     */
    public function create(array $in, bool $confirmDuplicate = false): int
    {
        $data = $this->validate($in);
        if (!$confirmDuplicate && ($dups = $this->duplicates($data['name'], $data['phone']))) {
            $list = implode(', ', array_map(fn ($d) => $d['name'] . ' (' . $d['code'] . ')', $dups));
            throw new ValidationException(['duplicate' => "Client probablement déjà enregistré : $list. Ouvrez la fiche existante ou confirmez la création."]);
        }
        return $this->db->tx(function () use ($data) {
            $data['code'] = sprintf('CL-%05d', Sequence::next('client'));
            $data['created_by'] = Auth::id();
            $data['created_at'] = $data['updated_at'] = Db::now();
            $id = $this->db->insert('clients', $data);
            Audit::log('client.created', 'client', $id);
            return $id;
        });
    }

    public function update(int $id, array $in): void
    {
        $this->get($id);
        $data = $this->validate($in);
        $data['updated_at'] = Db::now();
        $this->db->update('clients', $data, 'id = ?', [$id]);
        Audit::log('client.updated', 'client', $id);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $this->get($id);
        $this->db->update('clients', ['archived_at' => $archived ? Db::now() : null, 'updated_at' => Db::now()], 'id = ?', [$id]);
        Audit::log($archived ? 'client.archived' : 'client.restored', 'client', $id);
    }

    /**
     * Résout un client depuis un formulaire de colis : soit un identifiant existant,
     * soit nom + téléphone (réutilise la fiche existante si le téléphone correspond).
     * @return array{id:int, reused:bool, created:bool}
     */
    public function resolve(?int $id, string $name, string $phone, string $field, ?string $city = null, ?string $country = null): array
    {
        if ($id) {
            $c = $this->find($id);
            if (!$c || $c['archived_at']) {
                throw new ValidationException([$field => 'Client introuvable ou rangé.']);
            }
            return ['id' => $id, 'reused' => true, 'created' => false];
        }
        if (trim($name) === '' || trim($phone) === '') {
            throw new ValidationException([$field => 'Choisissez un client existant ou saisissez son nom et son téléphone.']);
        }
        $norm = normalize_phone($phone);
        if (strlen($norm) >= 8) {
            $existing = $this->db->one('SELECT id FROM clients WHERE phone_normalized = ? AND archived_at IS NULL ORDER BY id LIMIT 1', [$norm]);
            if ($existing) {
                return ['id' => (int) $existing['id'], 'reused' => true, 'created' => false];
            }
        }
        try {
            $newId = $this->create(['name' => $name, 'phone' => $phone, 'city' => $city, 'country' => $country], true);
        } catch (ValidationException $e) {
            $msg = reset($e->errors);
            throw new ValidationException([$field => $msg]);
        }
        return ['id' => $newId, 'reused' => false, 'created' => true];
    }

    public function parcels(int $clientId, int $limit = 50): array
    {
        return $this->db->all(
            "SELECT p.*, s.name AS sender_name, r.name AS recipient_name,
                    CASE WHEN p.sender_id = ? THEN 'sender' ELSE 'recipient' END AS role_in_parcel
             FROM parcels p JOIN clients s ON s.id = p.sender_id JOIN clients r ON r.id = p.recipient_id
             WHERE p.sender_id = ? OR p.recipient_id = ? ORDER BY p.received_at DESC LIMIT $limit",
            [$clientId, $clientId, $clientId]
        );
    }

    public function invoices(int $clientId): array
    {
        return $this->db->all('SELECT * FROM invoices WHERE client_id = ? ORDER BY created_at DESC LIMIT 50', [$clientId]);
    }

    /** Solde dû par devise. */
    public function balances(int $clientId): array
    {
        return $this->db->all(
            "SELECT currency, SUM(total_minor - paid_minor) AS due FROM invoices
             WHERE client_id = ? AND kind = 'invoice' AND status IN ('validated','partial') GROUP BY currency",
            [$clientId]
        );
    }
}
