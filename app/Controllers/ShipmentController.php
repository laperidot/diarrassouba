<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Domain;
use App\Core\Request;
use App\Core\Session;
use App\Services\ParcelService;
use App\Services\ShipmentService;

final class ShipmentController extends Controller
{
    public function index(): string
    {
        Auth::require('shipments.view');
        $svc = new ShipmentService();
        $f = ['q' => Request::query('q'), 'status' => Request::query('statut', 'active')];
        $page = $this->page();
        return $this->view('shipments/index', [
            'title' => 'Dossiers d’expédition',
            'shipments' => $svc->list($f, 30, ($page - 1) * 30),
            'total' => $svc->count($f), 'f' => $f, 'page' => $page,
        ]);
    }

    public function create(): string
    {
        Auth::require('shipments.edit');
        // Depuis la fiche d'un colis : la destination du vol est préremplie.
        $dest = Request::query('destination');
        $preset = isset(Domain::COUNTRIES[$dest]) ? ['destination_country' => $dest, 'destination_city' => Domain::COUNTRY_CITY[$dest] ?? ''] : [];
        return $this->view('shipments/form', ['title' => 'Nouveau dossier', 'shipment' => null, 'preset' => $preset]);
    }

    public function store(): never
    {
        Auth::require('shipments.edit');
        $id = $this->attempt(fn () => (new ShipmentService())->create(Request::all()), 'dossiers/nouveau');
        Session::flash('success', 'Dossier créé. Ajoutez-y les colis qui partent sur ce vol.');
        $this->redirect('dossiers/' . $id);
    }

    public function show(string $id): string
    {
        Auth::require('shipments.view');
        $svc = new ShipmentService();
        $s = $svc->find((int) $id);
        $this->notFoundUnless($s);
        return $this->view('shipments/show', [
            'title' => 'Dossier ' . $s['reference'],
            'shipment' => $s,
            'parcels' => $svc->parcels((int) $id),
            'candidates' => Auth::can('shipments.load') && $s['status'] === 'preparation' ? $svc->candidates((int) $id) : [],
            'margin' => Auth::can('dashboard.finance') ? $svc->margin((int) $id) : null,
            'transitions' => Domain::SHIPMENT_TRANSITIONS[$s['status']] ?? [],
            'scanFocus' => Request::query('scan') === '1',
        ]);
    }

    public function edit(string $id): string
    {
        Auth::require('shipments.edit');
        $s = (new ShipmentService())->find((int) $id);
        $this->notFoundUnless($s);
        return $this->view('shipments/form', ['title' => 'Modifier le dossier', 'shipment' => $s]);
    }

    public function update(string $id): never
    {
        Auth::require('shipments.edit');
        $this->attempt(fn () => (new ShipmentService())->update((int) $id, Request::all()), "dossiers/$id/modifier");
        Session::flash('success', 'Dossier mis à jour.');
        $this->redirect('dossiers/' . $id);
    }

    public function addParcels(string $id): never
    {
        Auth::require('shipments.load');
        $ids = Request::ids('parcel_ids');
        $code = Request::str('code');
        // Ajout depuis la fiche d'un colis : on y revient ; scan au comptoir : le champ reste prêt pour le suivant.
        $fromParcel = Request::str('retour') === 'colis' && count($ids) === 1;
        $back = "dossiers/$id";
        if ($fromParcel) {
            $back = 'colis/' . $ids[0];
        } elseif ($code !== '') {
            $back .= '?scan=1';
        }
        $result = $this->attempt(function () use ($id, &$ids, $code) {
            if (!$ids && $code !== '') {
                $parcel = (new ParcelService())->findByCode($code);
                if (!$parcel) {
                    throw new \App\Core\BusinessException('Aucun colis ne porte le code « ' . mb_substr($code, 0, 20) . ' ». Vérifiez l’étiquette.');
                }
                $ids = [(int) $parcel['id']];
            }
            if (!$ids) {
                throw new \App\Core\BusinessException('Scannez un code ou cochez au moins un colis à ajouter.');
            }
            return (new ShipmentService())->addParcels((int) $id, $ids);
        }, $back);
        if ($result['added']) {
            $s = (new ShipmentService())->get((int) $id);
            Session::flash('success', ($fromParcel ? 'Colis mis dans le vol ' . $s['reference'] : pluralize($result['added'], 'colis ajouté', 'colis ajoutés') . ' au dossier')
                . ' — total : ' . pluralize((int) $s['parcel_count'], 'colis', 'colis') . ', ' . kg((int) $s['total_weight_g']) . '.');
        }
        foreach ($result['skipped'] as $c => $why) {
            Session::flash('warning', "$c non ajouté : $why.");
        }
        if ($fromParcel) {
            $this->redirect('colis/' . $ids[0]);
        }
        $this->redirect('dossiers/' . $id, $code !== '' ? ['scan' => 1] : []);
    }

    public function removeParcel(string $id, string $pid): never
    {
        Auth::require('shipments.edit');
        $this->attempt(fn () => (new ShipmentService())->removeParcel((int) $id, (int) $pid), "dossiers/$id");
        Session::flash('success', 'Colis retiré du dossier.');
        $this->redirect('dossiers/' . $id);
    }

    public function status(string $id): never
    {
        Auth::require('shipments.edit');
        $to = Request::str('status');
        // Depuis le tableau de bord : on y revient, message compris.
        $back = Request::str('retour') === 'tableau-de-bord' ? 'tableau-de-bord' : "dossiers/$id";
        $result = $this->attempt(fn () => (new ShipmentService())->changeStatus((int) $id, $to, Request::bool('apply_parcels'), [
            'occurred_at' => Request::str('occurred_at') ?: null,
            'force_close' => Request::bool('force_close'),
        ]), $back);
        $s = (new ShipmentService())->get((int) $id);
        $msg = $s['reference'] . ' : ' . mb_strtolower(Domain::SHIPMENT_STATUSES[$to] ?? $to) . '.';
        if ($result['updated']) {
            $msg .= ' ' . pluralize($result['updated'], 'colis mis à jour', 'colis mis à jour') . '.';
        }
        Session::flash('success', $msg);
        foreach ($result['skipped'] as $code => $why) {
            Session::flash('warning', "$code inchangé : $why.");
        }
        $this->redirect($back);
    }

    /** Manifeste imprimable (liste de colisage du vol). */
    public function manifest(string $id): string
    {
        Auth::require('shipments.view');
        $svc = new ShipmentService();
        $s = $svc->find((int) $id);
        $this->notFoundUnless($s);
        return $this->view('shipments/manifest', ['title' => 'Manifeste ' . $s['reference'], 'shipment' => $s, 'parcels' => $svc->parcels((int) $id)], 'print');
    }
}
