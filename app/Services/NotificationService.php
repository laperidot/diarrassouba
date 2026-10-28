<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Domain;

/**
 * Notifications internes (dans l'application) et externes (e-mail, SMS, WhatsApp).
 * Aucune notification externe n'est simulée : chaque tentative est enregistrée avec
 * son résultat réel (sent, failed) ou « not_configured » si le canal n'est pas paramétré.
 */
final class NotificationService
{
    private Db $db;

    public const EVENTS = [
        'parcel.registered'   => 'Colis enregistré',
        'parcel.departed'     => 'Départ confirmé',
        'parcel.arrived'      => 'Arrivée à destination',
        'parcel.at_depot'     => 'Colis disponible au dépôt',
        'parcel.out_for_delivery' => 'Mise en livraison',
        'parcel.delivered'    => 'Livraison confirmée',
        'parcel.exception'    => 'Incident sur un colis',
        'invoice.issued'      => 'Facture émise',
        'payment.recorded'    => 'Paiement enregistré',
        'invoice.due_soon'    => 'Échéance proche',
    ];

    /** Statut colis -> événement client. */
    private const STATUS_EVENTS = [
        'registered' => 'parcel.registered', 'shipped' => 'parcel.departed', 'arrived' => 'parcel.arrived',
        'at_depot' => 'parcel.at_depot', 'out_for_delivery' => 'parcel.out_for_delivery', 'delivered' => 'parcel.delivered',
    ];

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::get();
    }

    /** Notification interne aux utilisateurs actifs des rôles donnés. */
    public function staff(array $roles, string $event, string $title, ?string $body = null, ?string $entityType = null, ?int $entityId = null, ?int $exceptUserId = null): void
    {
        $roles = array_values(array_unique(array_merge($roles, ['admin'])));
        $in = implode(',', array_fill(0, count($roles), '?'));
        $users = $this->db->all("SELECT id FROM users WHERE is_active = 1 AND role_code IN ($in)", $roles);
        foreach ($users as $u) {
            if ((int) $u['id'] === $exceptUserId) {
                continue;
            }
            $this->db->insert('notifications', [
                'user_id' => $u['id'], 'channel' => 'inapp', 'event' => $event, 'title' => mb_substr($title, 0, 150),
                'body' => $body ? mb_substr($body, 0, 500) : null, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'status' => 'unread', 'created_at' => Db::now(),
            ]);
        }
    }

    public function forUser(int $userId, int $limit = 40): array
    {
        return $this->db->all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit, [$userId]);
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL AND channel = 'inapp'", [$userId]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->run("UPDATE notifications SET read_at = ?, status = 'read' WHERE user_id = ? AND read_at IS NULL", [Db::now(), $userId]);
    }

    /** Journal des envois externes (pour l'écran Notifications). */
    public function externalLog(int $limit = 60): array
    {
        return $this->db->all(
            "SELECT n.*, c.name AS client_name FROM notifications n LEFT JOIN clients c ON c.id = n.client_id
             WHERE n.channel <> 'inapp' ORDER BY n.id DESC LIMIT $limit"
        );
    }

    /** À appeler après un changement de statut de colis. */
    public function parcelStatusChanged(array $parcel, string $status): void
    {
        if (isset(Domain::PARCEL_EXCEPTIONS[$status])) {
            $this->staff(['manager', 'logistics'], 'parcel.exception', 'Colis ' . $parcel['tracking_code'] . ' : ' . Domain::parcelLabel($status), null, 'parcel', (int) $parcel['id']);
            return;
        }
        $event = self::STATUS_EVENTS[$status] ?? null;
        if (!$event) {
            return;
        }
        $label = Domain::parcelLabel($status);
        $company = Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT');
        $trackUrl = absolute_url('suivi/' . $parcel['tracking_code']);
        $text = "$company : votre colis {$parcel['tracking_code']} — $label. Suivi : $trackUrl";
        // Destinataire informé de l'arrivée et de la disponibilité ; expéditeur de l'enregistrement et du départ.
        $targets = match ($status) {
            'registered', 'shipped' => [(int) $parcel['sender_id']],
            'delivered' => [(int) $parcel['sender_id'], (int) $parcel['recipient_id']],
            default => [(int) $parcel['recipient_id']],
        };
        foreach (array_unique($targets) as $clientId) {
            $this->toClient($clientId, $event, "Colis {$parcel['tracking_code']} : $label", $text, 'parcel', (int) $parcel['id']);
        }
    }

    /** Envoi vers un client sur chaque canal ; résultat réel enregistré. */
    public function toClient(int $clientId, string $event, string $title, string $text, ?string $entityType = null, ?int $entityId = null): array
    {
        $client = $this->db->one('SELECT id, name, phone, email FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            return [];
        }
        $results = [];
        if ($client['email']) {
            $results['email'] = $this->sendEmail($client['email'], $title, $text);
        }
        if ($client['phone']) {
            $results['sms'] = $this->sendSms($client['phone'], $text);
            $results['whatsapp'] = $this->sendWhatsapp($client['phone'], $text);
        }
        $logUnconfigured = Config::bool('NOTIFY_LOG_UNCONFIGURED', true);
        foreach ($results as $channel => [$status, $error]) {
            if ($status === 'not_configured' && !$logUnconfigured) {
                continue;
            }
            $this->db->insert('notifications', [
                'client_id' => $clientId, 'channel' => $channel, 'event' => $event, 'title' => mb_substr($title, 0, 150),
                'body' => mb_substr($text, 0, 500), 'entity_type' => $entityType, 'entity_id' => $entityId,
                'status' => $status, 'error' => $error ? mb_substr($error, 0, 255) : null,
                'sent_at' => $status === 'sent' ? Db::now() : null, 'created_at' => Db::now(),
            ]);
        }
        return $results;
    }

    /** @return array{0:string,1:?string} [statut, erreur] */
    private function sendEmail(string $to, string $subject, string $text): array
    {
        if (!Config::bool('MAIL_ENABLED', false)) {
            return ['not_configured', 'E-mail désactivé (MAIL_ENABLED=false)'];
        }
        $from = Config::get('MAIL_FROM', '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['not_configured', 'MAIL_FROM invalide'];
        }
        $headers = [
            'From' => '=?UTF-8?B?' . base64_encode((string) Config::get('APP_NAME', 'Diarrassouba')) . "?= <$from>",
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'MIME-Version' => '1.0',
        ];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
        return $ok ? ['sent', null] : ['failed', 'Le serveur de messagerie a refusé l’envoi'];
    }

    /**
     * SMS via une passerelle HTTP (SMS_DRIVER=webhook) : POST JSON {to, message}
     * avec en-tête Authorization: Bearer SMS_WEBHOOK_TOKEN. Compatible avec la plupart
     * des agrégateurs (ou un petit relais vers l'API du prestataire retenu).
     */
    private function sendSms(string $phone, string $text): array
    {
        if (Config::get('SMS_DRIVER', 'none') !== 'webhook' || !Config::get('SMS_WEBHOOK_URL')) {
            return ['not_configured', 'Aucun prestataire SMS configuré'];
        }
        return $this->postJson((string) Config::get('SMS_WEBHOOK_URL'), ['to' => $this->e164($phone), 'message' => $text], (string) Config::get('SMS_WEBHOOK_TOKEN', ''));
    }

    /**
     * WhatsApp Cloud API officielle (Meta). Hors fenêtre de 24 h, Meta impose un modèle
     * de message approuvé : WHATSAPP_TEMPLATE (avec un paramètre texte {{1}}).
     */
    private function sendWhatsapp(string $phone, string $text): array
    {
        $token = Config::get('WHATSAPP_TOKEN');
        $phoneId = Config::get('WHATSAPP_PHONE_NUMBER_ID');
        $template = Config::get('WHATSAPP_TEMPLATE');
        if (Config::get('WHATSAPP_DRIVER', 'none') !== 'cloud_api' || !$token || !$phoneId || !$template) {
            return ['not_configured', 'API WhatsApp officielle non configurée'];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($this->e164($phone), '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => Config::get('WHATSAPP_TEMPLATE_LANG', 'fr')],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($text, 0, 1000)]]]],
            ],
        ];
        return $this->postJson('https://graph.facebook.com/v20.0/' . rawurlencode($phoneId) . '/messages', $payload, $token);
    }

    private function postJson(string $url, array $payload, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['failed', 'Extension cURL absente'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? "Authorization: Bearer $token" : null]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['failed', 'Réseau : ' . $err];
        }
        return $code >= 200 && $code < 300 ? ['sent', null] : ['failed', "HTTP $code : " . mb_substr((string) $body, 0, 200)];
    }

    private function e164(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        if (str_starts_with(trim($phone), '+')) {
            return '+' . $d;
        }
        if (str_starts_with($d, '00')) {
            return '+' . substr($d, 2);
        }
        $cc = preg_replace('/\D+/', '', (string) Config::get('DEFAULT_PHONE_PREFIX', '225'));
        return '+' . $cc . $d;
    }
}
