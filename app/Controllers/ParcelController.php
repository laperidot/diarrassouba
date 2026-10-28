<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Domain;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\Upload;
use App\Services\DeliveryService;
use App\Services\ParcelService;
use App\Services\PdfService;
use App\Services\ShipmentService;

final class ParcelController extends Controller
{
    private function filters(): array
    {
        return [
            'q' => Request::query('q'), 'status' => Request::query('statut'), 'destination' => Request::query('destination'),
            'from' => Request::query('du'), 'to' => Request::query('au'), 'shipment_id' => Request::query('dossier'),
        ];
    }

    public function index(): string
    {
        Auth::require('parcels.view');
        $svc = new ParcelService();
        $f = $this->filters();
        $page = $this->page();
        return $this->view('parcels/index', [
            'title' => 'Colis',
            'parcels' => $svc->list($f, 30, ($page - 1) * 30),
            'total' => $svc->count($f),
            'f' => $f, 'page' => $page,
        ]);
    }

    public function create(): string
    {
        Auth::require('parcels.create');
        return $this->view('parcels/form', [
            'title' => 'Enregistrer un colis', 'parcel' => null,
            'tariffs' => (new ParcelService())->tariffs(),
            // Vols en préparation (toutes destinations) : le formulaire n'affiche que ceux de la destination choisie.
            'openShipments' => Auth::can('shipments.load') ? (new ShipmentService())->list(['status' => 'preparation'], 50) : [],
        ]);
    }

    public function store(): never
    {
        Auth::require('parcels.create');
        $files = [];
        if ($f = Request::file('attachment')) {
            $files[] = $f;
        }
        $result = $this->attempt(fn () => (new ParcelService())->create(Request::all(), $files), 'colis/nouveau');
        foreach ($result['notices'] as $n) {
            Session::flash('info', $n);
        }
        $msg = 'Colis enregistré. Imprimez l’étiquette et collez-la sur le colis.';
        // Vol choisi dans le formulaire : le colis est mis dedans dans la foulée.
        // Le colis reste enregistré même si le chargement échoue (vol parti entre-temps, destination différente…).
        $shipmentId = Request::int('shipment_id');
        if ($shipmentId && Auth::can('shipments.load')) {
            try {
                $load = (new ShipmentService())->addParcels($shipmentId, [(int) $result['id']]);
                $ref = (new ShipmentService())->get($shipmentId)['reference'];
                if ($load['added']) {
                    $msg = 'Colis enregistré et mis dans le vol ' . $ref . '. Imprimez l’étiquette et collez-la sur le colis.';
                }
                foreach ($load['skipped'] as $why) {
                    Session::flash('warning', 'Colis enregistré, mais non mis dans le vol ' . $ref . ' : ' . $why . '. Vous pouvez le faire depuis cette fiche.');
                }
            } catch (\App\Core\BusinessException $e) {
                Session::flash('warning', 'Colis enregistré, mais non mis dans le vol : ' . $e->getMessage());
            }
        }
        Session::flash('success', $msg);
        $this->redirect('colis/' . $result['id']);
    }

    public function show(string $id): string
    {
        Auth::require('parcels.view');
        $svc = new ParcelService();
        $parcel = $svc->find((int) $id);
        $this->notFoundUnless($parcel);
        if (!$svc->visibleToCurrentUser((int) $id)) {
            throw new HttpException(403);
        }
        $deliveries = (new DeliveryService())->forParcel((int) $id);
        // Colis encore au comptoir : proposer directement les vols en préparation vers sa destination.
        $loadable = !$parcel['shipment_id'] && in_array($parcel['status'], ['registered', 'awaiting', 'check_required'], true);
        return $this->view('parcels/show', [
            'loadable' => $loadable && Auth::can('shipments.load'),
            'openShipments' => $loadable && Auth::can('shipments.load') ? (new ShipmentService())->openFor($parcel['destination_country']) : [],
            'title' => 'Colis ' . $parcel['tracking_code'],
            'parcel' => $parcel,
            'events' => $svc->events((int) $id),
            'attachments' => $svc->attachments((int) $id),
            'invoice' => Auth::can('invoices.view') ? $svc->activeInvoice((int) $id) : null,
            'deliveries' => $deliveries,
            'transitions' => array_values(array_diff(Domain::parcelTransitions($parcel['status']), Domain::PARCEL_STATUS_RESERVED)),
            'agents' => Auth::can('deliveries.manage') ? (new DeliveryService())->agents() : [],
        ]);
    }

    public function edit(string $id): string
    {
        Auth::require('parcels.edit');
        $svc = new ParcelService();
        $parcel = $svc->find((int) $id);
        $this->notFoundUnless($parcel);
        return $this->view('parcels/form', [
            'title' => 'Modifier le colis', 'parcel' => $parcel, 'tariffs' => $svc->tariffs(),
            'billingLocked' => $svc->activeInvoice((int) $id) !== null,
        ]);
    }

    public function update(string $id): never
    {
        Auth::require('parcels.edit');
        $this->attempt(fn () => (new ParcelService())->update((int) $id, Request::all()), "colis/$id/modifier");
        Session::flash('success', 'Colis mis à jour.');
        $this->redirect('colis/' . $id);
    }

    public function status(string $id): never
    {
        Auth::require('parcels.status');
        $to = Request::str('status');
        $this->attempt(fn () => (new ParcelService())->changeStatus((int) $id, $to, [
            'public_comment' => Request::str('public_comment'),
            'internal_comment' => Request::str('internal_comment'),
            'location' => Request::str('location'),
            'occurred_at' => Request::str('occurred_at') ?: null,
        ]), "colis/$id");
        Session::flash('success', 'Statut mis à jour : ' . Domain::parcelLabel($to) . '.');
        $this->redirect('colis/' . $id);
    }

    public function attach(string $id): never
    {
        Auth::require('parcels.edit');
        $this->notFoundUnless((new ParcelService())->find((int) $id));
        $this->attempt(function () use ($id) {
            $file = Request::file('attachment');
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new \App\Core\BusinessException('Choisissez un fichier à joindre.');
            }
            (new ParcelService())->attach((int) $id, $file);
        }, "colis/$id");
        Session::flash('success', 'Pièce jointe ajoutée.');
        $this->redirect('colis/' . $id);
    }

    public function file(string $id): never
    {
        Auth::require('parcels.internal');
        $att = Db::get()->one('SELECT * FROM parcel_attachments WHERE id = ?', [(int) $id]);
        $this->notFoundUnless($att);
        Upload::send($att['path'], $att['mime'], $att['original_name']);
    }

    public function label(string $id): never
    {
        Auth::require('parcels.view');
        $svc = new ParcelService();
        $parcel = $svc->find((int) $id);
        $this->notFoundUnless($parcel);
        if (!$svc->visibleToCurrentUser((int) $id)) {
            throw new HttpException(403);
        }
        $this->sendPdf((new PdfService())->label($parcel), 'etiquette-' . $parcel['tracking_code'] . '.pdf');
    }
}
