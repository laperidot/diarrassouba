<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(): string
    {
        Auth::require('dashboard.view');
        if (Auth::role() === 'courier') {
            $this->redirect('livraisons');
        }
        $svc = new DashboardService();
        $period = Request::query('periode', '30d');
        if (!isset(DashboardService::PERIODS[$period]) && $period !== 'custom') {
            $period = '30d';
        }
        [$from, $to, $label] = $svc->range($period, Request::query('du'), Request::query('au'));
        $finance = Auth::can('dashboard.finance');
        return $this->view('dashboard/index', [
            'title' => 'Tableau de bord',
            'period' => $period,
            'periodLabel' => $label,
            'du' => Request::query('du'),
            'au' => Request::query('au'),
            'counts' => $svc->statusCounts(),
            'ops' => $svc->operations($from, $to),
            'finance' => $finance ? $svc->finance($from, $to) : null,
            'delivery' => $svc->deliveryPerformance($from, $to),
            'weekly' => $svc->weeklyParcels(10),
            'activity' => $svc->recentActivity(8),
            'payments' => $finance ? $svc->recentPayments(6) : [],
            'attention' => $svc->attention($finance),
            // Raccourci « Charger le vol » : le prochain dossier en préparation
            'loading' => Auth::can('shipments.load') ? ((new \App\Services\ShipmentService())->list(['status' => 'preparation'], 1)[0] ?? null) : null,
            // Vols en cours : suivi et changement de statut sans quitter le tableau de bord
            'flights' => Auth::can('shipments.view') ? (new \App\Services\ShipmentService())->list(['status' => 'active'], 6) : [],
        ]);
    }
}
