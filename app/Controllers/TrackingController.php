<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Db;
use App\Core\Domain;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\ParcelService;

/**
 * Suivi public sans compte. Ne montre que des informations non sensibles :
 * pas de notes internes, pas de montants, pas de coordonnées complètes, pas d'agent nominatif.
 * Recherches limitées par adresse IP pour empêcher l'énumération des codes.
 */
final class TrackingController extends Controller
{
    public function form(): string
    {
        $raw = Request::query('code');
        if ($raw !== '') {
            $code = ParcelService::normalizeCode($raw);
            if ($code) {
                $this->redirect('suivi/' . $code);
            }
            return $this->view('tracking/show', ['state' => 'initial', 'code' => mb_substr($raw, 0, 20), 'formatError' => true], 'public');
        }
        return $this->view('tracking/show', ['state' => 'initial', 'code' => ''], 'public');
    }

    public function lookup(): never
    {
        $code = ParcelService::normalizeCode(Request::str('code'));
        if (!$code) {
            $this->redirect('suivi', ['erreur' => 'format', 'code' => mb_substr(Request::str('code'), 0, 20)]);
        }
        $this->redirect('suivi/' . $code);
    }

    public function show(string $code): string
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $max = Config::int('TRACKING_RATE_LIMIT', 30);
        if (!RateLimiter::hit('tracking', Request::ip(), $max, 600)) {
            http_response_code(429);
            return $this->view('tracking/show', ['state' => 'limited', 'code' => $code], 'public');
        }
        $norm = ParcelService::normalizeCode($code);
        if (!$norm) {
            return $this->view('tracking/show', ['state' => 'unknown', 'code' => $code], 'public');
        }
        if ($norm !== $code) {
            $this->redirect('suivi/' . $norm);
        }
        try {
            $service = new ParcelService();
            $parcel = $service->findByCode($norm);
        } catch (\Throwable $e) {
            error_log('Suivi public : ' . $e->getMessage());
            http_response_code(503);
            return $this->view('tracking/show', ['state' => 'error', 'code' => $code], 'public');
        }
        if (!$parcel) {
            // Même délai de réponse et même message, que le format soit plausible ou non.
            usleep(random_int(80000, 160000));
            http_response_code(404);
            return $this->view('tracking/show', ['state' => 'unknown', 'code' => $norm], 'public');
        }
        $events = array_map(fn ($e) => [
            'status' => $e['status'],
            'label' => Domain::parcelLabel($e['status']),
            'occurred_at' => $e['occurred_at'],
            'location' => $e['location'],
            'comment' => $e['public_comment'],
            'alert' => isset(Domain::PARCEL_EXCEPTIONS[$e['status']]),
        ], $service->events((int) $parcel['id']));

        $public = [
            'tracking_code' => $parcel['tracking_code'],
            'status' => $parcel['status'],
            'weight' => (int) $parcel['public_weight'] === 1 ? (int) $parcel['weight_g'] : null,
            'pieces' => (int) $parcel['pieces'],
            'origin_city' => $parcel['origin_city'] ?: Domain::COUNTRY_CITY[$parcel['origin_country']] ?? null,
            'origin_country' => $parcel['origin_country'],
            'destination_city' => $parcel['destination_city'] ?: Domain::COUNTRY_CITY[$parcel['destination_country']] ?? null,
            'destination_country' => $parcel['destination_country'],
            'received_at' => $parcel['received_at'],
            'recipient' => mask_name($parcel['recipient_name']),
            'eta' => in_array($parcel['status'], ['awaiting', 'shipped', 'in_transit'], true) && $parcel['planned_arrival'] && !$parcel['actual_arrival'] ? $parcel['planned_arrival'] : null,
            'flight' => in_array($parcel['status'], ['shipped', 'in_transit'], true) ? $parcel['flight_number'] : null,
        ];
        return $this->view('tracking/show', ['state' => 'found', 'parcel' => $public, 'events' => $events, 'code' => $norm], 'public');
    }
}
