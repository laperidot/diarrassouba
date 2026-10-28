<?php
declare(strict_types=1);

// Données de démonstration (développement / formation uniquement).
// Usage : php bin/seed-demo.php   — refuse de s'exécuter si APP_ENV=production.
// Toutes les opérations passent par les services réels (mêmes règles qu'en production).

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Services\ClientService;
use App\Services\DeliveryService;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\ParcelService;
use App\Services\PaymentService;
use App\Services\ShipmentService;
use App\Services\UserService;

if (Config::get('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusé : APP_ENV=production.\n");
    exit(1);
}
$db = Db::get();
if ((int) $db->value('SELECT COUNT(*) FROM parcels') > 0) {
    fwrite(STDERR, "La base contient déjà des colis : démonstration non ajoutée.\n");
    exit(1);
}
$admin = $db->one("SELECT * FROM users WHERE role_code = 'admin' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "Créez d'abord un administrateur (bin/create-admin.php).\n");
    exit(1);
}
Auth::actAs($admin);
$pw = getenv('DEMO_PASSWORD') ?: 'Demo2026pass';
$users = new UserService();
foreach ([
    ['Aminata Koné', 'manager@diarrassouba.test', 'manager'],
    ['Yao Kouassi', 'comptoir@diarrassouba.test', 'registrar'],
    ['Ibrahim Traoré', 'logistique@diarrassouba.test', 'logistics'],
    ['Fatou Bamba', 'caisse@diarrassouba.test', 'cashier'],
    ['Seydou Coulibaly', 'livreur@diarrassouba.test', 'courier'],
] as [$n, $e, $r]) {
    $id = $users->save(null, ['name' => $n, 'email' => $e, 'role_code' => $r, 'is_active' => 1, 'password' => $pw]);
    $db->update('users', ['must_change_password' => 0], 'id = ?', [$id]);
}
$courier = (int) $db->value("SELECT id FROM users WHERE role_code = 'courier'");
$tariff = $users->saveTariff(null, ['name' => 'Standard Le Caire → Abidjan', 'origin_country' => 'EG', 'destination_country' => 'CI', 'currency' => 'XOF', 'rate' => '6500', 'min_kg' => '1', 'is_active' => 1]);
$users->saveTariff(null, ['name' => 'Express Le Caire → Abidjan', 'origin_country' => 'EG', 'destination_country' => 'CI', 'currency' => 'XOF', 'rate' => '8500', 'min_kg' => '0,5', 'is_active' => 1]);

$clients = new ClientService();
$people = [
    ['Mohamed El-Sayed', '+20 100 234 5678', 'Le Caire', 'EG'],
    ['Nour Hassan Trading', '+20 122 987 6543', 'Le Caire', 'EG'],
    ['Konan Aya Marie', '07 07 12 34 56', 'Abidjan', 'CI'],
    ['Ouattara Salif', '05 05 98 76 54', 'Abidjan', 'CI'],
    ['Boutique Ndiaye & Fils', '01 02 33 44 55', 'Bouaké', 'CI'],
    ['Diabaté Mariam', '07 48 11 22 33', 'Yamoussoukro', 'CI'],
];
$ids = [];
foreach ($people as [$n, $ph, $city, $c]) {
    $ids[] = $clients->create(['name' => $n, 'phone' => $ph, 'city' => $city, 'country' => $c, 'kind' => str_contains($n, 'Trading') || str_contains($n, 'Boutique') ? 'company' : 'person', 'default_payment_terms' => $c === 'CI' ? 'on_arrival' : 'before_shipping'], true);
}
$parcels = new ParcelService();
$items = [
    [0, 2, 'Vêtements et tissus', '18,4'], [1, 4, 'Pièces détachées téléphones', '7,25'], [0, 3, 'Cosmétiques', '12'],
    [1, 4, 'Ustensiles de cuisine', '23,6'], [0, 5, 'Documents et livres', '2,1'], [1, 2, 'Parfums', '5,8'],
    [0, 3, 'Chaussures', '9,9'], [1, 5, 'Accessoires de mode', '4,4'],
];
$pids = [];
foreach ($items as $i => [$s, $r, $desc, $w]) {
    $res = $parcels->create([
        'sender_id' => $ids[$s], 'recipient_id' => $ids[$r], 'description' => $desc, 'weight_kg' => $w, 'pieces' => 1 + $i % 3,
        'tariff_id' => $tariff, 'currency' => 'XOF', 'rate' => '', 'origin_country' => 'EG', 'origin_city' => 'Le Caire',
        'destination_country' => 'CI', 'destination_city' => 'Abidjan', 'public_weight' => '1',
        'received_at' => (new DateTimeImmutable('now', local_tz()))->modify('-' . (12 - $i) . ' days')->format('Y-m-d\TH:i'),
    ]);
    $pids[] = $res['id'];
}
$ships = new ShipmentService();
$s1 = $ships->create(['origin_country' => 'EG', 'origin_city' => 'Le Caire', 'destination_country' => 'CI', 'destination_city' => 'Abidjan', 'airline' => 'EgyptAir', 'flight_number' => 'MS 875', 'awb_number' => '077-1234 5675', 'freight_cost' => '450000', 'freight_currency' => 'XOF',
    'planned_departure' => (new DateTimeImmutable('-6 days', local_tz()))->format('Y-m-d\T02:30'), 'planned_arrival' => (new DateTimeImmutable('-6 days', local_tz()))->format('Y-m-d\T07:45')]);
$ships->addParcels($s1, array_slice($pids, 0, 5));
$ships->changeStatus($s1, 'departed', true, ['occurred_at' => (new DateTimeImmutable('-6 days', local_tz()))->format('Y-m-d\T02:40')]);
$ships->changeStatus($s1, 'arrived', true, ['occurred_at' => (new DateTimeImmutable('-6 days', local_tz()))->format('Y-m-d\T08:10')]);
foreach (array_slice($pids, 0, 4) as $p) {
    $parcels->changeStatus($p, 'at_depot', ['location' => 'Dépôt de Treichville, Abidjan', 'public_comment' => 'Disponible au retrait.']);
}
$parcels->changeStatus($pids[4], 'check_required', ['internal_comment' => 'Étiquette abîmée, vérifier le destinataire', 'public_comment' => 'Vérification en cours.']);

$s2 = $ships->create(['origin_country' => 'EG', 'origin_city' => 'Le Caire', 'destination_country' => 'CI', 'destination_city' => 'Abidjan', 'airline' => 'EgyptAir', 'flight_number' => 'MS 877',
    'planned_departure' => (new DateTimeImmutable('+2 days', local_tz()))->format('Y-m-d\T02:30'), 'planned_arrival' => (new DateTimeImmutable('+2 days', local_tz()))->format('Y-m-d\T07:45')]);
$ships->addParcels($s2, [$pids[5], $pids[6]]);

$inv = new InvoiceService();
$i1 = $inv->createDraft($ids[2], [$pids[0], $pids[5]], ['payment_terms' => 'on_arrival']);
$inv->validate($i1);
$pay = new PaymentService();
$pay->record($i1, ['amount' => '100000', 'method' => 'orange_money', 'reference' => 'OM2610.1234.A5', 'form_token' => bin2hex(random_bytes(16))]);
$i2 = $inv->createDraft($ids[4], [$pids[1], $pids[3]], ['payment_terms' => 'deposit']);
$inv->validate($i2);
$pay->record($i2, ['amount' => (string) ((int) $db->value('SELECT total_minor FROM invoices WHERE id = ?', [$i2])), 'method' => 'cash', 'form_token' => bin2hex(random_bytes(16))]);
$inv->createDraft($ids[5], [$pids[4]], []);

$exp = new ExpenseService();
$exp->create(['category' => 'air_freight', 'shipment_id' => $s1, 'description' => 'Fret EgyptAir LTA 077-1234 5675', 'expense_date' => (new DateTimeImmutable('-6 days'))->format('Y-m-d'), 'amount' => '438000', 'currency' => 'XOF', 'payment_status' => 'paid', 'supplier_reference' => 'EGY-FRT-8812'], null);
$exp->create(['category' => 'air_ticket', 'shipment_id' => $s1, 'description' => 'Billet accompagnateur Le Caire → Abidjan', 'ticket_reference' => '0772145559871', 'airline' => 'EgyptAir', 'beneficiary' => 'Ibrahim Traoré', 'expense_date' => (new DateTimeImmutable('-8 days'))->format('Y-m-d'), 'amount' => '385000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
$exp->create(['category' => 'customs', 'shipment_id' => $s1, 'description' => 'Dédouanement aéroport FHB', 'expense_date' => (new DateTimeImmutable('-5 days'))->format('Y-m-d'), 'amount' => '95000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
$exp->create(['category' => 'storage', 'description' => 'Loyer dépôt Treichville', 'expense_date' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'), 'amount' => '150000', 'currency' => 'XOF', 'payment_status' => 'unpaid'], null);

$del = new DeliveryService();
$d1 = $del->plan($pids[0], ['mode' => 'pickup', 'planned_date' => (new DateTimeImmutable('-2 days'))->format('Y-m-d')]);
$del->confirm($d1, ['recipient_name' => 'Konan Aya Marie', 'recipient_id_note' => 'CNI …4521', 'confirm' => '1'], null);
$d2 = $del->plan($pids[1], ['mode' => 'delivery', 'agent_id' => $courier, 'planned_date' => (new DateTimeImmutable('now'))->format('Y-m-d'), 'address' => 'Bouaké, quartier Commerce']);
$del->start($d2);
$d3 = $del->plan($pids[3], ['mode' => 'delivery', 'agent_id' => $courier, 'planned_date' => (new DateTimeImmutable('+1 day'))->format('Y-m-d')]);

echo "Démonstration créée. Comptes (mot de passe : $pw) : manager@, comptoir@, logistique@, caisse@, livreur@diarrassouba.test\n";
