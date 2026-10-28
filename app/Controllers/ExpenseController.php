<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Session;
use App\Core\Upload;
use App\Services\ExpenseService;
use App\Services\ShipmentService;

final class ExpenseController extends Controller
{
    public function index(): string
    {
        Auth::require('expenses.view');
        $svc = new ExpenseService();
        $f = [
            'category' => Request::query('categorie'), 'shipment_id' => Request::query('dossier'),
            'from' => Request::query('du'), 'to' => Request::query('au'), 'q' => Request::query('q'),
            'with_cancelled' => Request::query('annulees') === '1',
        ];
        $page = $this->page();
        return $this->view('expenses/index', [
            'title' => 'Dépenses',
            'expenses' => $svc->list($f, 40, ($page - 1) * 40),
            'total' => $svc->count($f),
            'totals' => $svc->totals($f),
            'shipments' => (new ShipmentService())->list([], 60),
            'f' => $f, 'page' => $page,
        ]);
    }

    public function create(): string
    {
        Auth::require('expenses.edit');
        return $this->view('expenses/form', [
            'title' => 'Nouvelle dépense', 'expense' => null, 'attachments' => [],
            'shipments' => (new ShipmentService())->list([], 60),
            'preset' => ['category' => Request::query('categorie'), 'shipment_id' => Request::query('dossier')],
        ]);
    }

    public function store(): never
    {
        Auth::require('expenses.edit');
        $id = $this->attempt(fn () => (new ExpenseService())->create(Request::all(), Request::file('attachment'), Request::bool('confirm_similar')), 'depenses/nouvelle');
        Session::flash('success', 'Dépense enregistrée.');
        $this->redirect('depenses/' . $id);
    }

    public function show(string $id): string
    {
        Auth::require('expenses.view');
        $svc = new ExpenseService();
        $e = $svc->find((int) $id);
        $this->notFoundUnless($e);
        return $this->view('expenses/form', [
            'title' => 'Dépense', 'expense' => $e, 'attachments' => $svc->attachments((int) $id),
            'shipments' => (new ShipmentService())->list([], 60), 'preset' => [],
        ]);
    }

    public function update(string $id): never
    {
        Auth::require('expenses.edit');
        $this->attempt(fn () => (new ExpenseService())->update((int) $id, Request::all(), Request::file('attachment')), "depenses/$id");
        Session::flash('success', 'Dépense mise à jour.');
        $this->redirect('depenses/' . $id);
    }

    public function cancel(string $id): never
    {
        Auth::require('expenses.cancel');
        $this->attempt(fn () => (new ExpenseService())->cancel((int) $id, Request::str('reason')), "depenses/$id");
        Session::flash('success', 'Dépense annulée. Elle reste visible dans l’historique.');
        $this->redirect('depenses/' . $id);
    }

    public function file(string $id): never
    {
        Auth::require('expenses.view');
        $att = Db::get()->one('SELECT * FROM expense_attachments WHERE id = ?', [(int) $id]);
        $this->notFoundUnless($att);
        Upload::send($att['path'], $att['mime'], $att['original_name']);
    }
}
