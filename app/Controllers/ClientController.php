<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Services\ClientService;

final class ClientController extends Controller
{
    public function index(): string
    {
        Auth::require('clients.view');
        $svc = new ClientService();
        $q = Request::query('q');
        $page = $this->page();
        $archived = Request::query('archives') === '1';
        return $this->view('clients/index', [
            'title' => 'Clients',
            'clients' => $svc->search($q, 30, ($page - 1) * 30, $archived),
            'total' => $svc->count($q, $archived),
            'q' => $q, 'page' => $page, 'archived' => $archived,
        ]);
    }

    /** Recherche JSON pour le sélecteur de client (formulaires colis et factures). */
    public function search(): never
    {
        Auth::require('clients.view');
        $q = Request::query('q');
        if (mb_strlen($q) < 2) {
            $this->json([]);
        }
        $showPhone = Auth::can('clients.contacts');
        $this->json(array_map(fn ($c) => [
            'id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'],
            'phone' => $showPhone ? $c['phone'] : mask_phone($c['phone']), 'city' => $c['city'],
        ], (new ClientService())->search($q, 8)));
    }

    public function create(): string
    {
        Auth::require('clients.edit');
        return $this->view('clients/form', ['title' => 'Nouveau client', 'client' => null]);
    }

    public function store(): never
    {
        Auth::require('clients.edit');
        $id = $this->attempt(fn () => (new ClientService())->create(Request::all(), Request::bool('confirm_duplicate')), 'clients/nouveau');
        Session::flash('success', 'Client enregistré.');
        $this->redirect('clients/' . $id);
    }

    public function show(string $id): string
    {
        Auth::require('clients.view');
        $svc = new ClientService();
        $client = $svc->find((int) $id);
        $this->notFoundUnless($client);
        return $this->view('clients/show', [
            'title' => $client['name'],
            'client' => $client,
            'parcels' => $svc->parcels((int) $id),
            'invoices' => Auth::can('invoices.view') ? $svc->invoices((int) $id) : [],
            'balances' => Auth::can('invoices.view') ? $svc->balances((int) $id) : [],
            'duplicates' => $svc->duplicates($client['name'], $client['phone'], (int) $id),
        ]);
    }

    public function edit(string $id): string
    {
        Auth::require('clients.edit');
        $client = (new ClientService())->find((int) $id);
        $this->notFoundUnless($client);
        return $this->view('clients/form', ['title' => 'Modifier le client', 'client' => $client]);
    }

    public function update(string $id): never
    {
        Auth::require('clients.edit');
        $this->attempt(fn () => (new ClientService())->update((int) $id, Request::all()), "clients/$id/modifier");
        Session::flash('success', 'Fiche client mise à jour.');
        $this->redirect('clients/' . $id);
    }

    public function archive(string $id): never
    {
        Auth::require('clients.edit');
        $restore = Request::bool('restore');
        $this->attempt(fn () => (new ClientService())->setArchived((int) $id, !$restore), "clients/$id");
        Session::flash('success', $restore ? 'Client réactivé.' : 'Client archivé : il n’apparaît plus dans les recherches. Son historique est conservé.');
        $this->redirect('clients/' . $id);
    }
}
