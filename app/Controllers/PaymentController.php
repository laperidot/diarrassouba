<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Core\Signer;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PdfService;

final class PaymentController extends Controller
{
    public function index(): string
    {
        Auth::require('invoices.view');
        $f = ['q' => Request::query('q'), 'method' => Request::query('moyen')];
        $page = $this->page();
        return $this->view('payments/index', [
            'title' => 'Paiements',
            'payments' => (new PaymentService())->list($f, 40, ($page - 1) * 40),
            'f' => $f, 'page' => $page,
        ]);
    }

    public function store(string $id): never
    {
        Auth::require('payments.record');
        $payId = $this->attempt(fn () => (new PaymentService())->record((int) $id, Request::all()), "factures/$id#paiement");
        Session::flash('success', 'Paiement enregistré.');
        $this->redirect('factures/' . $id, ['recu' => $payId]);
    }

    public function receipt(string $id): never
    {
        Auth::require('invoices.view');
        $pay = (new PaymentService())->find((int) $id);
        $this->notFoundUnless($pay);
        $inv = (new InvoiceService())->get((int) $pay['invoice_id']);
        $this->sendPdf((new PdfService())->receipt($pay, $inv), $pay['receipt_number'] . '.pdf', Request::query('dl') === '1');
    }

    public function reverse(string $id): never
    {
        Auth::require('payments.reverse');
        $pay = (new PaymentService())->find((int) $id);
        $this->notFoundUnless($pay);
        $this->attempt(fn () => (new PaymentService())->reverse((int) $id, Request::str('reason')), 'factures/' . $pay['invoice_id']);
        Session::flash('success', 'Paiement annulé par une écriture de contrepassation. Le solde a été recalculé.');
        $this->redirect('factures/' . $pay['invoice_id']);
    }

    public function share(string $id): never
    {
        Auth::require('invoices.view');
        $pay = (new PaymentService())->find((int) $id);
        $this->notFoundUnless($pay);
        Audit::log('payment.shared', 'payment', (int) $id);
        $this->json(['url' => Signer::shareUrl('recu', (int) $id), 'title' => 'Reçu ' . $pay['receipt_number'], 'hours' => Config::int('SHARE_LINK_TTL_HOURS', 72)]);
    }
}
