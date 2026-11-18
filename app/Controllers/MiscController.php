<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Signer;
use App\Services\InvoiceService;
use App\Services\NotificationService;
use App\Services\ParcelService;
use App\Services\PaymentService;
use App\Services\PdfService;

final class MiscController extends Controller
{
    public function menu(): string
    {
        Auth::require('dashboard.view');
        return $this->view('misc/menu', ['title' => 'Menu']);
    }

    public function scanner(): string
    {
        Auth::require('parcels.view');
        return $this->view('misc/scanner', ['title' => 'Scanner']);
    }

    /** Résolution d'un code scanné ou saisi -> fiche colis (interne). */
    public function codeLookup(): never
    {
        Auth::require('parcels.view');
        $svc = new ParcelService();
        $parcel = $svc->findByCode(Request::query('code'));
        if (!$parcel || !$svc->visibleToCurrentUser((int) $parcel['id'])) {
            $this->json(['found' => false, 'message' => 'Aucun colis ne porte ce code.'], 404);
        }
        $this->json(['found' => true, 'url' => url('colis/' . $parcel['id']), 'code' => $parcel['tracking_code']]);
    }

    /**
     * Règles de préchargement (Chrome, Edge) : la page visée est demandée dès le survol
     * ou l'appui sur un lien, son ouverture paraît instantanée. Seules des pages sans effet
     * sont concernées : ni fichiers, ni PDF, ni liens publics limités en nombre de visites.
     */
    public function speculationRules(): never
    {
        header('Content-Type: application/speculationrules+json');
        header('Cache-Control: public, max-age=3600');
        $skip = ['/suivi', '/partage', '/fichiers', '/pdf', '/etiquette', '/manifeste', '/releve', '/recu', '/api/', '/deconnexion', '/connexion'];
        echo json_encode(['prefetch' => [[
            'where' => ['and' => [
                ['href_matches' => '/*'],
                ['not' => ['selector_matches' => '[target], [download], [data-no-prefetch], ' . implode(', ', array_map(fn ($s) => "[href*='$s']", $skip))]],
            ]],
            'eagerness' => 'moderate',
        ]]], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function notifications(): string
    {
        Auth::require('dashboard.view');
        $svc = new NotificationService();
        $items = $svc->forUser((int) Auth::id());
        $external = Auth::can('audit.view') ? $svc->externalLog() : [];
        return $this->view('notifications/index', ['title' => 'Notifications', 'items' => $items, 'external' => $external]);
    }

    public function markRead(): never
    {
        Auth::require('dashboard.view');
        (new NotificationService())->markAllRead((int) Auth::id());
        Session::flash('success', 'Notifications marquées comme lues.');
        $this->redirect('notifications');
    }

    /** Sert à vérifier la connexion au serveur (bandeau hors ligne). */
    public function ping(): never
    {
        $this->json(['ok' => true, 'auth' => Auth::user() !== null]);
    }

    /** Lien de partage signé : PDF en lecture seule, sans connexion, jusqu'à expiration. */
    public function shared(string $type, string $id, string $exp, string $sig): never
    {
        if (!RateLimiter::hit('share', Request::ip(), 60, 600)) {
            throw new HttpException(429);
        }
        if (!ctype_digit($id) || !ctype_digit($exp) || !in_array($type, ['facture', 'recu', 'releve'], true)
            || !Signer::verify($type, (int) $id, (int) $exp, $sig)) {
            throw new HttpException(404, 'Ce lien de partage est invalide ou a expiré. Demandez un nouveau lien à l’agence.');
        }
        $pdf = new PdfService();
        if ($type === 'releve') {
            $client = (new \App\Services\ClientService())->find((int) $id) ?? throw new HttpException(404);
            $st = (new InvoiceService())->statement((int) $id);
            if (Request::query('pdf') === '1') {
                $this->sendPdf($pdf->statement($client, $st), 'releve-' . $client['code'] . '.pdf');
            }
            header('Cache-Control: private, no-store');
            header('X-Robots-Tag: noindex');
            echo \App\Core\View::render('tracking/statement', [
                'client' => $client, 'st' => $st, 'pdfUrl' => url(Request::path(), ['pdf' => '1']),
                'payments' => (new \App\Services\ClientService())->statementPayments((int) $id),
                'invoices' => (new \App\Services\ClientService())->statementInvoices((int) $id),
                'role' => (new \App\Services\ClientService())->role((int) $id),
            ], null);
            exit;
        }
        if ($type === 'facture') {
            $svc = new InvoiceService();
            $inv = $svc->find((int) $id) ?? throw new HttpException(404);
            if ($inv['status'] === 'draft') {
                throw new HttpException(404);
            }
            $this->sendPdf($pdf->invoice($inv, $svc->items((int) $id), (new PaymentService())->forInvoice((int) $id), $svc->installments((int) $id)), ($inv['number'] ?? 'facture') . '.pdf');
        }
        $pay = (new PaymentService())->find((int) $id) ?? throw new HttpException(404);
        $inv = (new InvoiceService())->get((int) $pay['invoice_id']);
        $this->sendPdf($pdf->receipt($pay, $inv), $pay['receipt_number'] . '.pdf');
    }
}
