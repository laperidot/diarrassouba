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
use App\Core\Domain;
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
// Additif : les données déjà saisies sont conservées ; on refuse seulement un second ajout de la démonstration.
if ($db->value("SELECT id FROM users WHERE email = 'manager@diarrassouba.test'")) {
    fwrite(STDERR, "La démonstration est déjà présente dans cette base : rien n'a été ajouté.\n");
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
$pay->record($i1, ['amount' => '100000', 'method' => 'orange_money', 'reference' => 'OM' . strtoupper(bin2hex(random_bytes(4))), 'form_token' => bin2hex(random_bytes(16))]);
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

// ---------- Activité sur deux mois : plusieurs destinations, vols à toutes les étapes ----------
$at = fn (string $rel, string $time = '') => (new DateTimeImmutable($rel, local_tz()))->format('Y-m-d\T' . ($time ?: 'H:i'));
$tariffs = ['CI' => $tariff];
foreach (['SN' => ['Dakar', '7500'], 'ML' => ['Bamako', '7000'], 'BF' => ['Ouagadougou', '7000']] as $c => [$city, $rate]) {
    $tariffs[$c] = $users->saveTariff(null, ['name' => "Standard Le Caire → $city", 'origin_country' => 'EG', 'destination_country' => $c, 'currency' => 'XOF', 'rate' => $rate, 'min_kg' => '1', 'is_active' => 1]);
}
$more = [
    ['Ahmed Farouk Export', '+20 111 456 7788', 'Le Caire', 'EG'], ['Salma Mostafa', '+20 106 222 3141', 'Alexandrie', 'EG'],
    ['Khaled Textiles', '+20 127 555 0909', 'Le Caire', 'EG'], ['Yasmine Adel', '+20 100 777 1212', 'Gizeh', 'EG'],
    ['Kouamé Jean-Baptiste', '07 58 44 10 20', 'Abidjan', 'CI'], ['Bakayoko Awa', '05 46 77 88 01', 'San-Pédro', 'CI'],
    ['Pharmacie du Plateau', '27 20 21 45 67', 'Abidjan', 'CI'], ['Touré Moussa', '01 40 50 60 70', 'Daloa', 'CI'],
    ['Diop Aïssatou', '77 123 45 67', 'Dakar', 'SN'], ['Sall Commerce', '78 555 66 77', 'Thiès', 'SN'],
    ['Ndiaye Cheikh', '76 400 11 22', 'Dakar', 'SN'], ['Keïta Oumar', '76 12 34 56', 'Bamako', 'ML'],
    ['Sidibé Kadiatou', '66 77 88 99', 'Bamako', 'ML'], ['Ouédraogo Rasmané', '70 11 22 33', 'Ouagadougou', 'BF'],
];
$c = [];
foreach ($more as [$n, $ph, $city, $cc]) {
    $company = (bool) preg_match('/Export|Textiles|Pharmacie|Commerce/', $n);
    $c[$n] = $clients->create(['name' => $n, 'phone' => $ph, 'city' => $city, 'country' => $cc, 'kind' => $company ? 'company' : 'person', 'default_payment_terms' => $cc === 'EG' ? 'before_shipping' : 'on_arrival'], true);
}
$egypt = [$ids[0], $ids[1], $c['Ahmed Farouk Export'], $c['Salma Mostafa'], $c['Khaled Textiles'], $c['Yasmine Adel']];
$to = [
    'CI' => [$ids[2], $ids[3], $ids[4], $ids[5], $c['Kouamé Jean-Baptiste'], $c['Bakayoko Awa'], $c['Pharmacie du Plateau'], $c['Touré Moussa']],
    'SN' => [$c['Diop Aïssatou'], $c['Sall Commerce'], $c['Ndiaye Cheikh']],
    'ML' => [$c['Keïta Oumar'], $c['Sidibé Kadiatou']],
    'BF' => [$c['Ouédraogo Rasmané']],
];
$goods = ['Tissus wax et bazin', 'Pièces détachées auto', 'Téléphones et accessoires', 'Parfums et encens', 'Médicaments (ordonnance jointe)',
    'Vêtements enfants', 'Électroménager petit format', 'Livres scolaires', 'Sacs et maroquinerie', 'Épices et dattes', 'Matériel de coiffure', 'Lampes et décoration'];
$n = 0;
$parcel = function (string $dest, string $daysAgo) use (&$n, $parcels, $egypt, $to, $goods, $tariffs, $at): int {
    $n++;
    $city = Domain::COUNTRY_CITY[$dest];
    $res = $parcels->create([
        'sender_id' => $egypt[$n % count($egypt)], 'recipient_id' => $to[$dest][$n % count($to[$dest])],
        'description' => $goods[$n % count($goods)], 'weight_kg' => number_format(1.5 + fmod($n * 7.3, 28), 1, ',', ''), 'pieces' => 1 + $n % 4,
        'tariff_id' => $tariffs[$dest], 'currency' => 'XOF', 'rate' => '', 'origin_country' => 'EG', 'origin_city' => 'Le Caire',
        'destination_country' => $dest, 'destination_city' => $city, 'public_weight' => '1', 'received_at' => $at("-$daysAgo days", sprintf('%02d:%02d', 9 + $n % 8, ($n * 13) % 60)),
    ]);
    return $res['id'];
};
$batch = fn (string $dest, int $count, int $from) => array_map(fn ($i) => $parcel($dest, (string) ($from - $i)), range(0, $count - 1));

// Vol clôturé il y a un mois : tout est livré, facturé et payé
$old = $batch('CI', 7, 42);
$s3 = $ships->create(['origin_country' => 'EG', 'origin_city' => 'Le Caire', 'destination_country' => 'CI', 'destination_city' => 'Abidjan', 'airline' => 'EgyptAir', 'flight_number' => 'MS 871', 'awb_number' => '077-1180 2231', 'freight_cost' => '520000', 'freight_currency' => 'XOF',
    'planned_departure' => $at('-34 days', '02:30'), 'planned_arrival' => $at('-34 days', '07:45')]);
$ships->addParcels($s3, $old);
$ships->changeStatus($s3, 'departed', true, ['occurred_at' => $at('-34 days', '02:35')]);
$ships->changeStatus($s3, 'arrived', true, ['occurred_at' => $at('-34 days', '07:50')]);
foreach ($old as $i => $p) {
    $parcels->changeStatus($p, 'at_depot', ['location' => 'Dépôt de Treichville, Abidjan', 'public_comment' => 'Disponible au retrait.']);
    $row = $db->one('SELECT recipient_id FROM parcels WHERE id = ?', [$p]);
    $name = (string) $db->value('SELECT name FROM clients WHERE id = ?', [$row['recipient_id']]);
    $d = $del->plan($p, $i % 2 ? ['mode' => 'delivery', 'agent_id' => $courier, 'planned_date' => (new DateTimeImmutable('-31 days'))->format('Y-m-d'), 'address' => 'Abidjan'] : ['mode' => 'pickup']);
    if ($i % 2) {
        $del->start($d);
    }
    $del->confirm($d, ['recipient_name' => $name, 'recipient_id_note' => 'CNI …' . (4100 + $i * 37), 'confirm' => '1'], null);
    $invId = $inv->createDraft((int) $row['recipient_id'], [$p], ['payment_terms' => 'on_arrival']);
    $inv->validate($invId);
    $db->update('invoices', ['issue_date' => (new DateTimeImmutable('-33 days'))->format('Y-m-d'), 'due_date' => (new DateTimeImmutable('-18 days'))->format('Y-m-d')], 'id = ?', [$invId]);
    $total = (int) $db->value('SELECT total_minor FROM invoices WHERE id = ?', [$invId]);
    $method = ['cash', 'orange_money', 'wave', 'mtn_momo'][$i % 4];
    $pay->record($invId, ['amount' => (string) $total, 'method' => $method, 'reference' => $method === 'cash' ? '' : strtoupper(substr($method, 0, 2)) . bin2hex(random_bytes(4)),
        'paid_at' => $at('-' . (32 - $i) . ' days', '11:20'), 'form_token' => bin2hex(random_bytes(16))]);
}
$ships->changeStatus($s3, 'closed', false, []);
$exp->create(['category' => 'air_freight', 'shipment_id' => $s3, 'description' => 'Fret EgyptAir LTA 077-1180 2231', 'expense_date' => (new DateTimeImmutable('-34 days'))->format('Y-m-d'), 'amount' => '505000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
$exp->create(['category' => 'handling', 'shipment_id' => $s3, 'description' => 'Manutention aéroport du Caire', 'expense_date' => (new DateTimeImmutable('-35 days'))->format('Y-m-d'), 'amount' => '42000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);

// Vol vers Dakar parti hier (en route)
$sn = $batch('SN', 5, 9);
$s4 = $ships->create(['origin_country' => 'EG', 'origin_city' => 'Le Caire', 'destination_country' => 'SN', 'destination_city' => 'Dakar', 'airline' => 'Air Sénégal', 'flight_number' => 'HC 302', 'awb_number' => '687-5520 1194', 'freight_cost' => '380000', 'freight_currency' => 'XOF',
    'planned_departure' => $at('-1 day', '23:10'), 'planned_arrival' => $at('now', '05:40')]);
$ships->addParcels($s4, $sn);
$ships->changeStatus($s4, 'departed', true, ['occurred_at' => $at('-1 day', '23:20')]);
$exp->create(['category' => 'air_freight', 'shipment_id' => $s4, 'description' => 'Fret Air Sénégal LTA 687-5520 1194', 'expense_date' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'), 'amount' => '372000', 'currency' => 'XOF', 'payment_status' => 'unpaid'], null);
$sender = fn (int $p) => (int) $db->value('SELECT sender_id FROM parcels WHERE id = ?', [$p]);
$recipient = fn (int $p) => (int) $db->value('SELECT recipient_id FROM parcels WHERE id = ?', [$p]);
$early = $inv->createDraft($sender($sn[0]), [$sn[0]], ['payment_terms' => 'before_shipping']);
$inv->validate($early);
$pay->record($early, ['amount' => (string) $db->value('SELECT total_minor FROM invoices WHERE id = ?', [$early]), 'method' => 'cash', 'paid_at' => $at('-5 days', '10:00'), 'form_token' => bin2hex(random_bytes(16))]);

// Vols en préparation : Dakar (avec des colis), Bamako (vide, à charger)
$s5 = $ships->create(['origin_country' => 'EG', 'origin_city' => 'Le Caire', 'destination_country' => 'SN', 'destination_city' => 'Dakar', 'airline' => 'Air Sénégal', 'flight_number' => 'HC 304',
    'planned_departure' => $at('+5 days', '23:10'), 'planned_arrival' => $at('+6 days', '05:40')]);
$ships->addParcels($s5, $batch('SN', 2, 4));
$ships->create(['origin_country' => 'EG', 'origin_city' => 'Le Caire', 'destination_country' => 'ML', 'destination_city' => 'Bamako', 'airline' => 'Royal Air Maroc', 'flight_number' => 'AT 525',
    'planned_departure' => $at('+7 days', '14:00'), 'planned_arrival' => $at('+7 days', '21:30')]);

// Colis au comptoir, pas encore dans un vol (à charger)
$waiting = array_merge($batch('CI', 4, 3), $batch('SN', 2, 2), $batch('ML', 3, 3), $batch('BF', 1, 1));
foreach (array_slice($waiting, 0, 3) as $p) {
    $parcels->changeStatus($p, 'awaiting', ['public_comment' => 'Prêt pour le prochain vol.']);
}

// Factures à suivre : une en retard (paiement partiel), une échéance proche, un brouillon
$late = $inv->createDraft($recipient($waiting[0]), [$waiting[0]], ['payment_terms' => 'on_arrival', 'due_date' => (new DateTimeImmutable('-6 days'))->format('Y-m-d')]);
$inv->validate($late);
$db->update('invoices', ['issue_date' => (new DateTimeImmutable('-21 days'))->format('Y-m-d')], 'id = ?', [$late]);
$pay->record($late, ['amount' => '20000', 'method' => 'wave', 'reference' => 'WV' . bin2hex(random_bytes(4)), 'paid_at' => $at('-8 days', '16:05'), 'form_token' => bin2hex(random_bytes(16))]);
$soon = $inv->createDraft($sender($sn[1]), [$sn[1]], ['payment_terms' => 'before_shipping', 'due_date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d')]);
$inv->validate($soon);
$inv->createDraft($recipient($waiting[6]), [$waiting[6]], []);
$exp->create(['category' => 'packaging', 'description' => 'Cartons, film et ruban adhésif', 'expense_date' => (new DateTimeImmutable('-3 days'))->format('Y-m-d'), 'amount' => '38500', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
$exp->create(['category' => 'local_transport', 'description' => 'Navette aéroport FHB → dépôt', 'expense_date' => (new DateTimeImmutable('-6 days'))->format('Y-m-d'), 'amount' => '25000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
$pids = array_merge($pids, $old, $sn, $waiting);

echo "Démonstration créée (" . $db->value("SELECT COUNT(*) FROM parcels") . " colis, " . $db->value("SELECT COUNT(*) FROM shipments") . " vols au total). Comptes (mot de passe : $pw) : manager@, comptoir@, logistique@, caisse@, livreur@diarrassouba.test\n";
