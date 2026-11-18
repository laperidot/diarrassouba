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
        $role = isset(ClientService::ROLES[Request::query('role')]) ? Request::query('role') : '';
        return $this->view('clients/index', [
            'title' => 'Clients',
            'clients' => $svc->search($q, 30, ($page - 1) * 30, $archived, $role),
            'total' => $svc->count($q, $archived, $role),
            'q' => $q, 'page' => $page, 'archived' => $archived, 'role' => $role,
            'counts' => ['' => $svc->count($q, $archived)] + array_map(fn ($r) => $svc->count($q, $archived, $r), array_combine(array_keys(ClientService::ROLES), array_keys(ClientService::ROLES))),
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

    /** Relevé de compte PDF (factures, avoirs, paiements, solde progressif) ; période facultative ?du=&au=. */
    public function statement(string $id): never
    {
        Auth::require('invoices.view');
        $client = (new ClientService())->find((int) $id);
        $this->notFoundUnless($client);
        $date = fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', Request::query($k)) ? Request::query($k) : null;
        $data = (new \App\Services\InvoiceService())->statement((int) $id, $date('du'), $date('au'));
        $bytes = (new \App\Services\PdfService())->statement($client, $data);
        $this->sendPdf($bytes, 'releve-' . $client['code'] . '.pdf', Request::query('dl') === '1');
    }

    /** Lien public signé vers le relevé (page mobile + PDF) et message prêt à envoyer (JSON). */
    public function shareStatement(string $id): never
    {
        Auth::require('invoices.view');
        $client = (new ClientService())->find((int) $id);
        $this->notFoundUnless($client);
        $svc = new \App\Services\InvoiceService();
        $st = $svc->statement((int) $id);
        if (!$st['accounts']) {
            $this->json(['error' => 'Ce client n’a pas encore de facture terminée : rien à envoyer.'], 422);
        }
        $hours = \App\Core\Config::int('SHARE_LINK_TTL_HOURS', 72);
        $url = \App\Core\Signer::shareUrl('releve', (int) $id);
        \App\Core\Audit::log('client.statement_shared', 'client', (int) $id);
        $this->json([
            'url' => $url, 'hours' => $hours, 'title' => 'Relevé de paiement ' . $client['code'],
            'text' => $svc->statementMessage($client, $st, $url, $hours),
            'phone' => intl_phone($client['phone'], $client['country']),
        ]);
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
        Session::flash('success', 'Client mis à jour.');
        $this->redirect('clients/' . $id);
    }

    public function archive(string $id): never
    {
        Auth::require('clients.edit');
        $restore = Request::bool('restore');
        $this->attempt(fn () => (new ClientService())->setArchived((int) $id, !$restore), "clients/$id");
        Session::flash('success', $restore ? 'Client de nouveau affiché.' : 'Client rangé : il n’apparaît plus dans les recherches, mais rien n’est effacé.');
        $this->redirect('clients/' . $id);
    }
}
