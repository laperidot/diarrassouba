<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Domain;
use App\Core\Request;
use App\Core\Session;
use App\Core\Signer;
use App\Services\ClientService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PdfService;
use App\Services\ShipmentService;

final class InvoiceController extends Controller
{
    public function index(): string
    {
        Auth::require('invoices.view');
        $svc = new InvoiceService();
        $f = ['q' => Request::query('q'), 'status' => Request::query('status')];
        $page = $this->page();
        return $this->view('invoices/index', [
            'title' => 'Factures',
            'invoices' => $svc->list($f, 30, ($page - 1) * 30),
            'total' => $svc->count($f), 'f' => $f, 'page' => $page,
        ]);
    }

    /** Étape 1 : choisir le client ; étape 2 : cocher les colis à facturer. */
    public function create(): string
    {
        Auth::require('invoices.edit');
        $clientId = (int) Request::query('client');
        $shipmentId = (int) Request::query('dossier') ?: null;
        $client = $clientId ? (new ClientService())->find($clientId) : null;
        return $this->view('invoices/create', [
            'title' => 'Nouvelle facture',
            'client' => $client,
            'parcels' => $client ? (new InvoiceService())->billableParcels($clientId, $shipmentId) : [],
            'shipments' => (new ShipmentService())->list([], 50),
            'shipmentId' => $shipmentId,
        ]);
    }

    public function store(): never
    {
        Auth::require('invoices.edit');
        $clientId = (int) Request::str('client_id');
        $id = $this->attempt(fn () => (new InvoiceService())->createDraft($clientId, Request::ids('parcel_ids'), [
            'payment_terms' => Request::str('payment_terms'),
            'due_date' => Request::str('due_date'),
            'notes' => Request::str('notes'),
        ]), 'factures/nouvelle?client=' . $clientId);
        Session::flash('success', 'Brouillon créé. Vérifiez les lignes puis validez la facture.');
        $this->redirect('factures/' . $id);
    }

    public function show(string $id): string
    {
        Auth::require('invoices.view');
        $svc = new InvoiceService();
        $inv = $svc->find((int) $id);
        $this->notFoundUnless($inv);
        return $this->view('invoices/show', [
            'title' => ($inv['kind'] === 'credit_note' ? 'Avoir ' : 'Facture ') . ($inv['number'] ?? 'brouillon'),
            'inv' => $inv,
            'items' => $svc->items((int) $id),
            'installments' => $svc->installments((int) $id),
            'payments' => (new PaymentService())->forInvoice((int) $id),
            'formToken' => bin2hex(random_bytes(16)),
        ]);
    }

    public function update(string $id): never
    {
        Auth::require('invoices.edit');
        $this->attempt(fn () => (new InvoiceService())->updateDraft((int) $id, $_POST), "factures/$id");
        Session::flash('success', 'Brouillon enregistré.');
        $this->redirect('factures/' . $id);
    }

    public function removeItem(string $id, string $item): never
    {
        Auth::require('invoices.edit');
        $this->attempt(fn () => (new InvoiceService())->removeItem((int) $id, (int) $item), "factures/$id");
        Session::flash('success', 'Ligne retirée : le colis redevient facturable.');
        $this->redirect('factures/' . $id);
    }

    public function validate(string $id): never
    {
        Auth::require('invoices.edit');
        $this->attempt(fn () => (new InvoiceService())->validate((int) $id), "factures/$id");
        Session::flash('success', 'Facture validée et numérotée. Elle ne peut plus être modifiée.');
        $this->redirect('factures/' . $id);
    }

    public function destroy(string $id): never
    {
        Auth::require('invoices.edit');
        $this->attempt(fn () => (new InvoiceService())->deleteDraft((int) $id), "factures/$id");
        Session::flash('success', 'Brouillon supprimé. Les colis redeviennent facturables.');
        $this->redirect('factures');
    }

    public function cancel(string $id): never
    {
        Auth::require('invoices.cancel');
        $this->attempt(fn () => (new InvoiceService())->cancel((int) $id, Request::str('reason')), "factures/$id");
        Session::flash('success', 'Facture annulée. Son numéro est conservé dans l’historique.');
        $this->redirect('factures/' . $id);
    }

    public function credit(string $id): never
    {
        Auth::require('invoices.cancel');
        $creditId = $this->attempt(fn () => (new InvoiceService())->creditNote((int) $id, Request::str('reason')), "factures/$id");
        Session::flash('success', 'Avoir émis.');
        $this->redirect('factures/' . $creditId);
    }

    public function pdf(string $id): never
    {
        Auth::require('invoices.view');
        $svc = new InvoiceService();
        $inv = $svc->find((int) $id);
        $this->notFoundUnless($inv);
        $bytes = (new PdfService())->invoice($inv, $svc->items((int) $id), (new PaymentService())->forInvoice((int) $id), $svc->installments((int) $id));
        $this->sendPdf($bytes, ($inv['number'] ?? 'brouillon-' . $inv['id']) . '.pdf', Request::query('dl') === '1');
    }

    /** Crée un lien de partage temporaire (JSON) pour le partage depuis le téléphone. */
    public function share(string $id): never
    {
        Auth::require('invoices.view');
        $inv = (new InvoiceService())->find((int) $id);
        $this->notFoundUnless($inv);
        if ($inv['status'] === 'draft') {
            $this->json(['error' => 'Un brouillon ne se partage pas : validez d’abord la facture.'], 422);
        }
        Audit::log('invoice.shared', 'invoice', (int) $id);
        $this->json(['url' => Signer::shareUrl('facture', (int) $id), 'title' => ($inv['kind'] === 'credit_note' ? 'Avoir ' : 'Facture ') . $inv['number'], 'hours' => \App\Core\Config::int('SHARE_LINK_TTL_HOURS', 72)]);
    }
}
