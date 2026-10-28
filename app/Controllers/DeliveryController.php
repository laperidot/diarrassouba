<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\Upload;
use App\Services\DeliveryService;

final class DeliveryController extends Controller
{
    public function index(): string
    {
        Auth::require('deliveries.view');
        $svc = new DeliveryService();
        $status = Request::query('statut', 'open');
        return $this->view('deliveries/index', [
            'title' => Auth::role() === 'courier' ? 'Mes livraisons' : 'Livraisons et retraits',
            'deliveries' => $svc->list(['status' => $status, 'mode' => Request::query('mode')], 60),
            'ready' => Auth::can('deliveries.manage') ? $svc->readyParcels() : [],
            'status' => $status,
        ]);
    }

    public function plan(string $id): never
    {
        Auth::require('deliveries.manage');
        $did = $this->attempt(fn () => (new DeliveryService())->plan((int) $id, Request::all()), "colis/$id");
        Session::flash('success', 'Remise planifiée.');
        $this->redirect('livraisons/' . $did);
    }

    public function show(string $id): string
    {
        Auth::require('deliveries.view');
        $svc = new DeliveryService();
        $d = $svc->find((int) $id);
        $this->notFoundUnless($d);
        return $this->view('deliveries/show', ['title' => 'Remise du colis ' . $d['tracking_code'], 'd' => $d, 'proofs' => $svc->proofs((int) $id)]);
    }

    public function start(string $id): never
    {
        Auth::require('deliveries.confirm');
        $this->attempt(fn () => (new DeliveryService())->start((int) $id), "livraisons/$id");
        Session::flash('success', 'Livraison démarrée : le client voit que le colis est en route.');
        $this->redirect('livraisons/' . $id);
    }

    public function confirm(string $id): never
    {
        Auth::require('deliveries.confirm');
        $this->attempt(fn () => (new DeliveryService())->confirm((int) $id, Request::all(), Request::file('proof')), "livraisons/$id");
        Session::flash('success', 'Remise confirmée : colis livré.');
        $this->redirect('livraisons/' . $id);
    }

    public function fail(string $id): never
    {
        Auth::require('deliveries.confirm');
        $this->attempt(fn () => (new DeliveryService())->fail((int) $id, Request::str('incident')), "livraisons/$id");
        Session::flash('warning', 'Tentative échouée enregistrée. Planifiez une nouvelle remise depuis la fiche du colis.');
        $this->redirect('livraisons/' . $id);
    }

    public function cancel(string $id): never
    {
        Auth::require('deliveries.manage');
        $this->attempt(fn () => (new DeliveryService())->cancel((int) $id), "livraisons/$id");
        Session::flash('success', 'Remise annulée.');
        $this->redirect('livraisons');
    }

    public function file(string $id): never
    {
        Auth::require('deliveries.view');
        $proof = Db::get()->one('SELECT dp.*, d.agent_id FROM delivery_proofs dp JOIN deliveries d ON d.id = dp.delivery_id WHERE dp.id = ?', [(int) $id]);
        $this->notFoundUnless($proof);
        if (Auth::role() === 'courier' && (int) $proof['agent_id'] !== Auth::id()) {
            throw new HttpException(403);
        }
        Upload::send($proof['path'], $proof['mime'], $proof['original_name']);
    }
}
