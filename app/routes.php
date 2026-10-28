<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveryController;
use App\Controllers\ExpenseController;
use App\Controllers\InvoiceController;
use App\Controllers\MiscController;
use App\Controllers\ParcelController;
use App\Controllers\PaymentController;
use App\Controllers\ShipmentController;
use App\Controllers\TrackingController;
use App\Core\Router;

$r = new Router();

// ---- Public ----
$r->get('/', [AuthController::class, 'home']);
$r->get('/connexion', [AuthController::class, 'loginForm']);
$r->post('/connexion', [AuthController::class, 'login']);
$r->post('/deconnexion', [AuthController::class, 'logout']);
$r->get('/installation', [AuthController::class, 'installForm']);
$r->post('/installation', [AuthController::class, 'install']);
$r->get('/suivi', [TrackingController::class, 'form']);
$r->get('/suivi/{code}', [TrackingController::class, 'show']);
$r->get('/partage/{type}/{id}/{exp}/{sig}', [MiscController::class, 'shared']);

// ---- Compte ----
$r->get('/mot-de-passe', [AuthController::class, 'passwordForm']);
$r->post('/mot-de-passe', [AuthController::class, 'changePassword']);

// ---- Navigation ----
$r->get('/tableau-de-bord', [DashboardController::class, 'index']);
$r->get('/menu', [MiscController::class, 'menu']);
$r->get('/scanner', [MiscController::class, 'scanner']);
$r->get('/api/colis/recherche-code', [MiscController::class, 'codeLookup']);
$r->get('/notifications', [MiscController::class, 'notifications']);
$r->post('/notifications/lues', [MiscController::class, 'markRead']);
$r->get('/api/ping', [MiscController::class, 'ping']);

// ---- Clients ----
$r->get('/clients', [ClientController::class, 'index']);
$r->get('/clients/nouveau', [ClientController::class, 'create']);
$r->post('/clients', [ClientController::class, 'store']);
$r->get('/api/clients', [ClientController::class, 'search']);
$r->get('/clients/{id}', [ClientController::class, 'show']);
$r->get('/clients/{id}/modifier', [ClientController::class, 'edit']);
$r->post('/clients/{id}', [ClientController::class, 'update']);
$r->post('/clients/{id}/archiver', [ClientController::class, 'archive']);

// ---- Colis ----
$r->get('/colis', [ParcelController::class, 'index']);
$r->get('/colis/nouveau', [ParcelController::class, 'create']);
$r->post('/colis', [ParcelController::class, 'store']);
$r->get('/colis/{id}', [ParcelController::class, 'show']);
$r->get('/colis/{id}/modifier', [ParcelController::class, 'edit']);
$r->post('/colis/{id}', [ParcelController::class, 'update']);
$r->post('/colis/{id}/statut', [ParcelController::class, 'status']);
$r->post('/colis/{id}/pieces', [ParcelController::class, 'attach']);
$r->get('/colis/{id}/etiquette', [ParcelController::class, 'label']);
$r->post('/colis/{id}/livraison', [DeliveryController::class, 'plan']);
$r->get('/fichiers/colis/{id}', [ParcelController::class, 'file']);

// ---- Dossiers d'expédition ----
$r->get('/dossiers', [ShipmentController::class, 'index']);
$r->get('/dossiers/nouveau', [ShipmentController::class, 'create']);
$r->post('/dossiers', [ShipmentController::class, 'store']);
$r->get('/dossiers/{id}', [ShipmentController::class, 'show']);
$r->get('/dossiers/{id}/modifier', [ShipmentController::class, 'edit']);
$r->post('/dossiers/{id}', [ShipmentController::class, 'update']);
$r->post('/dossiers/{id}/colis', [ShipmentController::class, 'addParcels']);
$r->post('/dossiers/{id}/colis/{pid}/retirer', [ShipmentController::class, 'removeParcel']);
$r->post('/dossiers/{id}/statut', [ShipmentController::class, 'status']);
$r->get('/dossiers/{id}/manifeste', [ShipmentController::class, 'manifest']);

// ---- Factures et paiements ----
$r->get('/factures', [InvoiceController::class, 'index']);
$r->get('/factures/nouvelle', [InvoiceController::class, 'create']);
$r->post('/factures', [InvoiceController::class, 'store']);
$r->get('/factures/{id}', [InvoiceController::class, 'show']);
$r->post('/factures/{id}', [InvoiceController::class, 'update']);
$r->post('/factures/{id}/lignes/{item}/retirer', [InvoiceController::class, 'removeItem']);
$r->post('/factures/{id}/valider', [InvoiceController::class, 'validate']);
$r->post('/factures/{id}/supprimer', [InvoiceController::class, 'destroy']);
$r->post('/factures/{id}/annuler', [InvoiceController::class, 'cancel']);
$r->post('/factures/{id}/avoir', [InvoiceController::class, 'credit']);
$r->get('/factures/{id}/pdf', [InvoiceController::class, 'pdf']);
$r->post('/factures/{id}/partage', [InvoiceController::class, 'share']);
$r->post('/factures/{id}/paiements', [PaymentController::class, 'store']);
$r->get('/paiements', [PaymentController::class, 'index']);
$r->get('/paiements/{id}/recu', [PaymentController::class, 'receipt']);
$r->post('/paiements/{id}/annuler', [PaymentController::class, 'reverse']);
$r->post('/paiements/{id}/partage', [PaymentController::class, 'share']);

// ---- Dépenses ----
$r->get('/depenses', [ExpenseController::class, 'index']);
$r->get('/depenses/nouvelle', [ExpenseController::class, 'create']);
$r->post('/depenses', [ExpenseController::class, 'store']);
$r->get('/depenses/{id}', [ExpenseController::class, 'show']);
$r->post('/depenses/{id}', [ExpenseController::class, 'update']);
$r->post('/depenses/{id}/annuler', [ExpenseController::class, 'cancel']);
$r->get('/fichiers/depenses/{id}', [ExpenseController::class, 'file']);

// ---- Livraisons ----
$r->get('/livraisons', [DeliveryController::class, 'index']);
$r->get('/livraisons/{id}', [DeliveryController::class, 'show']);
$r->post('/livraisons/{id}/demarrer', [DeliveryController::class, 'start']);
$r->post('/livraisons/{id}/confirmer', [DeliveryController::class, 'confirm']);
$r->post('/livraisons/{id}/echec', [DeliveryController::class, 'fail']);
$r->post('/livraisons/{id}/annuler', [DeliveryController::class, 'cancel']);
$r->get('/fichiers/preuves/{id}', [DeliveryController::class, 'file']);

// ---- Administration ----
$r->get('/utilisateurs', [AdminController::class, 'users']);
$r->get('/utilisateurs/nouveau', [AdminController::class, 'userForm']);
$r->post('/utilisateurs', [AdminController::class, 'userStore']);
$r->get('/utilisateurs/{id}', [AdminController::class, 'userForm']);
$r->post('/utilisateurs/{id}', [AdminController::class, 'userUpdate']);
$r->get('/tarifs', [AdminController::class, 'tariffs']);
$r->post('/tarifs', [AdminController::class, 'tariffStore']);
$r->post('/tarifs/{id}', [AdminController::class, 'tariffUpdate']);
$r->get('/journal', [AdminController::class, 'audit']);
$r->get('/parametres', [AdminController::class, 'settings']);
$r->post('/parametres', [AdminController::class, 'settingsSave']);

return $r;
