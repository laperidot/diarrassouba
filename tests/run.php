<?php
declare(strict_types=1);

/**
 * Tests unitaires et d'intégration des règles métier critiques.
 * Usage : php tests/run.php
 * Utilise une base SQLite temporaire (jamais la base de production).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\Config;
use App\Core\Db;
use App\Core\Domain;
use App\Core\Migrator;
use App\Core\Money;
use App\Core\Pdf;
use App\Core\Signer;
use App\Core\ValidationException;
use App\Services\ClientService;
use App\Services\DashboardService;
use App\Services\DeliveryService;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\ParcelService;
use App\Services\PaymentService;
use App\Services\PdfService;
use App\Services\ShipmentService;

// Par défaut : SQLite temporaire. Pour vérifier MySQL/MariaDB, fournir une base DÉDIÉE et VIDE :
// TEST_DB_NAME=di_test DB_HOST=… DB_USER=… DB_PASS=… php tests/run.php
$tmp = sys_get_temp_dir() . '/di-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$testMysql = (string) getenv('TEST_DB_NAME');
if ($testMysql !== '') {
    Config::set('DB_DRIVER', 'mysql');
    Config::set('DB_NAME', $testMysql);
} else {
    Config::set('DB_DRIVER', 'sqlite');
    Config::set('DB_SQLITE_PATH', $tmp);
}
Config::set('APP_KEY', str_repeat('k', 40));
Config::set('NOTIFY_LOG_UNCONFIGURED', 'true');
Config::set('MAIL_ENABLED', 'false');
Db::reset(Db::connectFromConfig());
$db = Db::get();
if ($testMysql !== '' && (int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') > 0) {
    fwrite(STDERR, "Refusé : la base « $testMysql » n'est pas vide (utilisez une base de test dédiée).\n");
    exit(1);
}
echo 'Base : ' . $db->driver . "\n";
(new Migrator($db))->migrate();

$pass = 0;
$fail = 0;
function test(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        $pass++;
        echo "  ✔ $name\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  ✘ $name\n      " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
function ok(bool $cond, string $msg = 'assertion'): void
{
    if (!$cond) {
        throw new RuntimeException("Échec : $msg");
    }
}
function eq(mixed $a, mixed $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new RuntimeException("Attendu " . var_export($b, true) . ", obtenu " . var_export($a, true) . ($msg ? " ($msg)" : ''));
    }
}
function throws(string $class, callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException("Exception $class attendue, obtenu " . get_class($e) . ' : ' . $e->getMessage());
    }
    throw new RuntimeException("Exception $class attendue ($msg)");
}
function mkUser(string $role): array
{
    $db = Db::get();
    $id = $db->insert('users', ['name' => ucfirst($role), 'email' => $role . bin2hex(random_bytes(2)) . '@t.test', 'password_hash' => Auth::hash('Motdepasse123'), 'role_code' => $role, 'is_active' => 1, 'created_at' => Db::now(), 'updated_at' => Db::now()]);
    return $db->one('SELECT * FROM users WHERE id = ?', [$id]);
}
/** Texte lisible d'un PDF : flux décompressés (FlateDecode) mis bout à bout. */
function pdf_text(string $bytes): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m);
    return implode("\n", array_map(fn ($s) => @gzuncompress($s) ?: $s, $m[1]));
}
function token(): string
{
    return bin2hex(random_bytes(16));
}

$admin = mkUser('admin');
Auth::actAs($admin);
$clients = new ClientService();
$parcels = new ParcelService();
$ships = new ShipmentService();
$invoices = new InvoiceService();
$payments = new PaymentService();
$expenses = new ExpenseService();
$deliveries = new DeliveryService();

$sender = $clients->create(['name' => 'Ahmed Fathy', 'phone' => '+20 100 111 2222', 'country' => 'EG']);
$recipient = $clients->create(['name' => 'Kouamé Jean', 'phone' => '07 07 00 00 01', 'country' => 'CI']);
$other = $clients->create(['name' => 'Tiers Client', 'phone' => '05 05 00 00 09', 'country' => 'CI']);
$newParcel = function (string $kg = '10', string $rate = '6500', array $extra = []) use ($parcels, $sender, $recipient): int {
    return $parcels->create($extra + ['sender_id' => $sender, 'recipient_id' => $recipient, 'description' => 'Test', 'weight_kg' => $kg, 'rate' => $rate, 'currency' => 'XOF', 'origin_country' => 'EG', 'destination_country' => 'CI'])['id'];
};

echo "\nMontants et poids\n";
test('Saisie de montants (XOF sans décimale, EUR avec centimes)', function () {
    eq(Money::parse('12 500', 'XOF'), 12500);
    eq(Money::parse('1.234,50', 'EUR'), 123450);
    eq(Money::parse('12,5', 'EUR'), 1250);
    eq(Money::parse('abc', 'XOF'), null);
    eq(Money::parseKg('12,5'), 12500);
    eq(Money::parseKg('0,125'), 125);
    eq(Money::parseKg('-3'), null);
});
test('Poids facturable arrondi aux 100 g supérieurs et minimum du tarif', function () {
    eq(Money::billableWeight(12340, 0, 100), 12400);
    eq(Money::billableWeight(12300, 0, 100), 12300);
    eq(Money::billableWeight(400, 1000, 100), 1000);
});
test('Montant = poids facturable × tarif/kg, arrondi au plus proche', function () {
    eq(Money::transportAmount(12400, 6500), 80600);      // 12,4 kg × 6 500 F
    eq(Money::transportAmount(1250, 1999), 2499);        // 1,25 × 19,99 € = 24,9875 € -> 24,99 €
    eq(Money::tax(100000, 1800), 18000);
});

echo "\nColis et codes de suivi\n";
test('Création d’un colis : code unique, montant calculé côté serveur, événement initial', function () use ($newParcel, $parcels) {
    $id = $newParcel('12,34', '6500');
    $p = $parcels->get($id);
    ok((bool) preg_match('/^DI-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $p['tracking_code']), 'format du code');
    eq((int) $p['billable_weight_g'], 12400);
    eq((int) $p['amount_minor'], 80600);
    eq($p['status'], 'registered');
    eq(count($parcels->events($id)), 1);
});
test('Unicité des codes de suivi (10 000 codes générés, contrainte UNIQUE en base)', function () use ($db, $newParcel) {
    $codes = [];
    for ($i = 0; $i < 10000; $i++) {
        $codes[ParcelService::generateCode()] = true;
    }
    eq(count($codes), 10000);
    $id = $newParcel();
    $code = $db->value('SELECT tracking_code FROM parcels WHERE id = ?', [$id]);
    throws(PDOException::class, fn () => $db->run('UPDATE parcels SET tracking_code = ? WHERE id <> ? AND id = (SELECT MIN(id) FROM parcels)', [$code, $id]));
});
test('Normalisation de la saisie du code (minuscules, sans tirets, O/0)', function () {
    eq(ParcelService::normalizeCode('di 7k3m q9xa'), 'DI-7K3M-Q9XA');
    eq(ParcelService::normalizeCode('7K3MQ9XA'), 'DI-7K3M-Q9XA');
    eq(ParcelService::normalizeCode('DI-7K3M-Q9XO'), 'DI-7K3M-Q9X0');
    eq(ParcelService::normalizeCode('DI-123'), null);
});
test('Recherche par code, client, téléphone', function () use ($parcels, $newParcel) {
    $id = $newParcel();
    $p = $parcels->get($id);
    eq((int) $parcels->list(['q' => strtolower($p['tracking_code'])])[0]['id'], $id);
    ok(count($parcels->list(['q' => 'Kouamé'])) >= 1, 'par nom');
    ok(count($parcels->list(['q' => '0707000001'])) >= 1, 'par téléphone');
    eq($parcels->count(['q' => 'introuvable-xyz']), 0);
});
test('Client existant réutilisé quand le téléphone correspond (pas de doublon)', function () use ($parcels, $db, $sender) {
    $before = (int) $db->value('SELECT COUNT(*) FROM clients');
    $r = $parcels->create(['sender_name' => 'Ahmed F.', 'sender_phone' => '00201001112222', 'recipient_name' => 'Nouveau Destinataire', 'recipient_phone' => '0101010101', 'description' => 'x', 'weight_kg' => '1', 'rate' => '5000', 'currency' => 'XOF', 'origin_country' => 'EG', 'destination_country' => 'CI']);
    eq((int) $db->value('SELECT sender_id FROM parcels WHERE id = ?', [$r['id']]), $sender);
    eq((int) $db->value('SELECT COUNT(*) FROM clients'), $before + 1, 'seul le destinataire est créé');
});
test('Détection de doublon à la création d’une fiche client', function () use ($clients) {
    throws(ValidationException::class, fn () => $clients->create(['name' => 'Autre nom', 'phone' => '07 07 00 00 01']));
});
test('Transitions de statut : refus des sauts incohérents et de « livré » hors module Livraisons', function () use ($parcels, $newParcel) {
    $id = $newParcel();
    throws(BusinessException::class, fn () => $parcels->changeStatus($id, 'at_depot'));
    throws(BusinessException::class, fn () => $parcels->changeStatus($id, 'delivered'));
    $parcels->changeStatus($id, 'awaiting');
    $parcels->changeStatus($id, 'blocked', ['internal_comment' => 'Douane']);
    $parcels->changeStatus($id, 'awaiting');
    eq($parcels->get($id)['status'], 'awaiting');
    eq(count($parcels->events($id)), 4);
});

echo "\nDossiers d’expédition\n";
test('Un colis ne peut appartenir qu’à un seul dossier actif', function () use ($ships, $newParcel, $db) {
    $p = $newParcel();
    $s1 = $ships->create(['origin_country' => 'EG', 'destination_country' => 'CI', 'flight_number' => 'MS 1']);
    $s2 = $ships->create(['origin_country' => 'EG', 'destination_country' => 'CI', 'flight_number' => 'MS 2']);
    eq($ships->addParcels($s1, [$p])['added'], 1);
    $r = $ships->addParcels($s2, [$p]);
    eq($r['added'], 0);
    eq(count($r['skipped']), 1);
    throws(PDOException::class, fn () => $db->insert('shipment_parcels', ['shipment_id' => $s2, 'parcel_id' => $p, 'active_flag' => 1, 'added_at' => Db::now()]), 'contrainte UNIQUE');
    $ships->removeParcel($s1, $p);
    eq($ships->addParcels($s2, [$p])['added'], 1, 'réaffectation après retrait');
});
test('Départ du dossier : colis en incident non modifiés, les autres passent « Expédié »', function () use ($ships, $parcels, $newParcel) {
    $a = $newParcel();
    $b = $newParcel();
    $s = $ships->create(['origin_country' => 'EG', 'destination_country' => 'CI']);
    $ships->addParcels($s, [$a, $b]);
    $parcels->changeStatus($b, 'anomaly');
    $r = $ships->changeStatus($s, 'departed', true);
    eq($r['updated'], 1);
    eq($parcels->get($a)['status'], 'shipped');
    eq($parcels->get($b)['status'], 'anomaly');
    throws(BusinessException::class, fn () => $ships->changeStatus($s, 'closed', false), 'transition interdite');
    $r = $ships->changeStatus($s, 'arrived', true);
    eq($parcels->get($a)['status'], 'arrived');
});
test('Destination différente refusée, dossier hors préparation verrouillé', function () use ($ships, $newParcel) {
    $p = $newParcel('2', '5000', ['destination_country' => 'SN']);
    $s = $ships->create(['origin_country' => 'EG', 'destination_country' => 'CI']);
    eq($ships->addParcels($s, [$p])['added'], 0);
});

echo "\nFacturation\n";
test('Facture individuelle : instantané des montants, numérotation à la validation', function () use ($invoices, $newParcel, $recipient, $db) {
    $p = $newParcel('10', '6500');
    $id = $invoices->createDraft($recipient, [$p]);
    $inv = $invoices->get($id);
    eq($inv['number'], null);
    eq((int) $inv['total_minor'], 65000);
    $db->update('parcels', ['rate_per_kg_minor' => 9999], 'id = ?', [$p]); // modification ultérieure du colis
    $invoices->validate($id);
    $inv = $invoices->get($id);
    ok((bool) preg_match('/^FAC-\d{4}-\d{5}$/', $inv['number']), 'numéro');
    eq((int) $inv['total_minor'], 65000, 'la ligne garde le tarif figé');
    ok($inv['client_snapshot'] !== null, 'instantané client');
});
test('Facture groupée pour plusieurs colis d’un même client', function () use ($invoices, $newParcel, $sender) {
    $ids = [$newParcel('5'), $newParcel('7,5'), $newParcel('2,05')];
    $id = $invoices->createDraft($sender, $ids, ['fees' => [$ids[0] => ['amount' => '2000', 'label' => 'Emballage']]]);
    $inv = $invoices->get($id);
    eq(count($invoices->items($id)), 3);
    eq((int) $inv['subtotal_minor'], 32500 + 48750 + 13650); // 2,05 kg -> 2,1 kg facturé
    eq((int) $inv['fees_minor'], 2000);
    eq((int) $inv['total_minor'], 96900);
});
test('Double facturation impossible (service et contrainte en base)', function () use ($invoices, $newParcel, $recipient, $db) {
    $p = $newParcel();
    $id = $invoices->createDraft($recipient, [$p]);
    throws(BusinessException::class, fn () => $invoices->createDraft($recipient, [$p]));
    throws(PDOException::class, fn () => $db->insert('invoice_items', ['invoice_id' => $id, 'parcel_id' => $p, 'description' => 'x', 'line_total_minor' => 1, 'billing_lock' => 1]));
    $invoices->deleteDraft($id);
    $id2 = $invoices->createDraft($recipient, [$p]);
    ok($id2 > 0, 'facturable à nouveau après suppression du brouillon');
});
test('Colis d’un autre client et devises mélangées refusés', function () use ($invoices, $newParcel, $other, $recipient) {
    $p = $newParcel();
    throws(BusinessException::class, fn () => $invoices->createDraft($other, [$p]));
    $eur = $newParcel('3', '12,50', ['currency' => 'EUR']);
    throws(BusinessException::class, fn () => $invoices->createDraft($recipient, [$newParcel(), $eur]));
});
test('Facture validée immuable ; annulation libère les colis ; avoir', function () use ($invoices, $newParcel, $recipient) {
    $p = $newParcel();
    $id = $invoices->createDraft($recipient, [$p]);
    $invoices->validate($id);
    throws(BusinessException::class, fn () => $invoices->updateDraft($id, ['tax_rate' => '18']));
    $invoices->cancel($id, 'Erreur de destinataire');
    eq($invoices->get($id)['status'], 'cancelled');
    $id2 = $invoices->createDraft($recipient, [$p]);
    $invoices->validate($id2);
    $credit = $invoices->creditNote($id2, 'Geste commercial');
    eq((int) $invoices->get($credit)['total_minor'], -(int) $invoices->get($id2)['total_minor']);
    eq($invoices->get($id2)['status'], 'credited');
});
test('Taxe et échéancier : la somme des échéances doit égaler le total', function () use ($invoices, $newParcel, $recipient) {
    $id = $invoices->createDraft($recipient, [$newParcel('10', '10000')]);
    $invoices->updateDraft($id, ['payment_terms' => 'installments', 'tax_rate' => '18', 'installments' => [['due_date' => '2026-11-01', 'amount' => '50000'], ['due_date' => '2026-12-01', 'amount' => '50000']]]);
    eq((int) $invoices->get($id)['total_minor'], 118000);
    throws(BusinessException::class, fn () => $invoices->validate($id));
    $invoices->updateDraft($id, ['payment_terms' => 'installments', 'tax_rate' => '18', 'installments' => [['due_date' => '2026-11-01', 'amount' => '59000'], ['due_date' => '2026-12-01', 'amount' => '59000']]]);
    $invoices->validate($id);
    eq($invoices->get($id)['status'], 'validated');
});

echo "\nPaiements\n";
$payInvoice = function () use ($invoices, $newParcel, $recipient): int {
    $id = $invoices->createDraft($recipient, [$newParcel('10', '10000')]); // 100 000 F
    $invoices->validate($id);
    return $id;
};
test('Paiements partiels et calcul du solde', function () use ($payments, $invoices, $payInvoice) {
    $id = $payInvoice();
    $payments->record($id, ['amount' => '30000', 'method' => 'cash', 'form_token' => token()]);
    $inv = $invoices->get($id);
    eq($inv['status'], 'partial');
    eq((int) $inv['total_minor'] - (int) $inv['paid_minor'], 70000);
    $payments->record($id, ['amount' => '70000', 'method' => 'wave', 'reference' => 'WV-1', 'form_token' => token()]);
    eq($invoices->get($id)['status'], 'paid');
});
test('Trop-perçu, montant nul, double envoi et référence dupliquée refusés', function () use ($payments, $payInvoice, $db) {
    $id = $payInvoice();
    throws(ValidationException::class, fn () => $payments->record($id, ['amount' => '100001', 'method' => 'cash', 'form_token' => token()]));
    throws(ValidationException::class, fn () => $payments->record($id, ['amount' => '0', 'method' => 'cash', 'form_token' => token()]));
    throws(ValidationException::class, fn () => $payments->record($id, ['amount' => '10', 'method' => 'orange_money', 'form_token' => token()]), 'référence obligatoire');
    $t = token();
    $payments->record($id, ['amount' => '1000', 'method' => 'orange_money', 'reference' => 'OM-X', 'form_token' => $t]);
    throws(BusinessException::class, fn () => $payments->record($id, ['amount' => '1000', 'method' => 'orange_money', 'reference' => 'OM-Y', 'form_token' => $t]));
    throws(ValidationException::class, fn () => $payments->record($id, ['amount' => '1000', 'method' => 'orange_money', 'reference' => 'OM-X', 'form_token' => token()]));
    eq((int) $db->value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$id]), 1);
});
test('Paiement refusé sur brouillon ; annulation de paiement tracée et unique', function () use ($payments, $invoices, $payInvoice, $newParcel, $recipient) {
    $draft = $invoices->createDraft($recipient, [$newParcel()]);
    throws(BusinessException::class, fn () => $payments->record($draft, ['amount' => '1', 'method' => 'cash', 'form_token' => token()]));
    $id = $payInvoice();
    $pid = $payments->record($id, ['amount' => '100000', 'method' => 'cash', 'form_token' => token()]);
    eq($invoices->get($id)['status'], 'paid');
    $payments->reverse($pid, 'Billet contrefait');
    $inv = $invoices->get($id);
    eq($inv['status'], 'validated');
    eq((int) $inv['paid_minor'], 0);
    throws(BusinessException::class, fn () => $payments->reverse($pid, 'Deuxième fois'));
    eq(count($payments->forInvoice($id)), 2, 'paiement d’origine conservé + contrepassation');
});

echo "\nDépenses\n";
test('Billets d’avion distincts du fret ; référence de billet unique', function () use ($expenses) {
    throws(ValidationException::class, fn () => $expenses->create(['category' => 'air_ticket', 'description' => 'Billet', 'expense_date' => '2026-10-01', 'amount' => '300000', 'currency' => 'XOF', 'payment_status' => 'paid'], null), 'référence et compagnie obligatoires');
    $expenses->create(['category' => 'air_ticket', 'description' => 'Billet', 'ticket_reference' => 'tk-001', 'airline' => 'EgyptAir', 'expense_date' => '2026-10-01', 'amount' => '300000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
    throws(ValidationException::class, fn () => $expenses->create(['category' => 'air_ticket', 'description' => 'Billet bis', 'ticket_reference' => 'TK-001', 'airline' => 'EgyptAir', 'expense_date' => '2026-10-02', 'amount' => '1', 'currency' => 'XOF', 'payment_status' => 'paid'], null));
});
test('Alerte de double saisie, frais généraux, annulation hors totaux', function () use ($expenses, $ships) {
    $s = $ships->create(['origin_country' => 'EG', 'destination_country' => 'CI']);
    $in = ['category' => 'air_freight', 'shipment_id' => $s, 'description' => 'Fret', 'expense_date' => '2026-10-05', 'amount' => '400000', 'currency' => 'XOF', 'payment_status' => 'paid'];
    $id = $expenses->create($in, null);
    throws(ValidationException::class, fn () => $expenses->create($in, null));
    $expenses->create($in, null, true);
    $expenses->create(['category' => 'storage', 'description' => 'Loyer', 'expense_date' => '2026-10-05', 'amount' => '100000', 'currency' => 'XOF', 'payment_status' => 'unpaid'], null);
    eq(count($expenses->list(['shipment_id' => 'general', 'category' => 'storage'])), 1);
    $expenses->cancel($id, 'Saisie en double');
    eq((int) $expenses->totals(['shipment_id' => $s])[0]['total'], 400000);
});
test('Marge estimée d’un dossier = facturé − dépenses liées', function () use ($ships, $invoices, $expenses, $newParcel, $recipient) {
    $s = $ships->create(['origin_country' => 'EG', 'destination_country' => 'CI']);
    $p = $newParcel('10', '10000');
    $ships->addParcels($s, [$p]);
    $id = $invoices->createDraft($recipient, [$p]);
    $invoices->validate($id);
    $expenses->create(['category' => 'customs', 'shipment_id' => $s, 'description' => 'Douane', 'expense_date' => '2026-10-05', 'amount' => '30000', 'currency' => 'XOF', 'payment_status' => 'paid'], null);
    eq($ships->margin($s)['rows']['XOF']['margin'], 70000);
});

echo "\nLivraisons\n";
$arrived = function () use ($newParcel, $parcels): int {
    $p = $newParcel();
    foreach (['awaiting', 'shipped', 'in_transit', 'arrived'] as $s) {
        $parcels->changeStatus($p, $s, ['silent' => true]);
    }
    return $p;
};
test('Retrait au dépôt : confirmation explicite obligatoire avant « Livré »', function () use ($deliveries, $parcels, $arrived) {
    $p = $arrived();
    $d = $deliveries->plan($p, ['mode' => 'pickup']);
    throws(ValidationException::class, fn () => $deliveries->confirm($d, ['recipient_name' => 'Jean'], null));
    throws(ValidationException::class, fn () => $deliveries->confirm($d, ['confirm' => '1'], null));
    eq($parcels->get($p)['status'], 'arrived');
    $deliveries->confirm($d, ['recipient_name' => 'Kouamé Jean', 'confirm' => '1'], null);
    eq($parcels->get($p)['status'], 'delivered');
});
test('Livraison : démarrage, échec, nouvelle tentative, agent limité à ses tournées', function () use ($deliveries, $parcels, $arrived, $admin) {
    $p = $arrived();
    $courier = mkUser('courier');
    $other = mkUser('courier');
    $d = $deliveries->plan($p, ['mode' => 'delivery', 'agent_id' => $courier['id']]);
    throws(BusinessException::class, fn () => $deliveries->plan($p, ['mode' => 'pickup']), 'une seule remise ouverte');
    Auth::actAs($other);
    throws(App\Core\HttpException::class, fn () => $deliveries->start($d));
    eq(count($deliveries->list(['status' => 'open'])), 0, 'liste filtrée');
    eq($parcels->count([]), 0, 'colis des autres tournées invisibles');
    ok(!$parcels->visibleToCurrentUser($p), 'fiche et étiquette refusées hors tournée');
    Auth::actAs($courier);
    ok($parcels->count([]) >= 1 && $parcels->visibleToCurrentUser($p), 'colis de sa tournée visible');
    $deliveries->start($d);
    eq($parcels->get($p)['status'], 'out_for_delivery');
    $deliveries->fail($d, 'Destinataire absent');
    eq($parcels->get($p)['status'], 'delivery_failed');
    Auth::actAs($admin);
    $d2 = $deliveries->plan($p, ['mode' => 'delivery', 'agent_id' => $courier['id']]);
    eq((int) $deliveries->get($d2)['attempt_no'], 2);
});

echo "\nPermissions, notifications, tableau de bord, documents\n";
test('Matrice des permissions côté serveur', function () {
    ok(Domain::roleCan('admin', 'users.manage'));
    ok(!Domain::roleCan('manager', 'users.manage'));
    ok(!Domain::roleCan('courier', 'invoices.view'));
    ok(!Domain::roleCan('registrar', 'payments.record'));
    ok(Domain::roleCan('cashier', 'payments.record'));
    ok(!Domain::roleCan('cashier', 'payments.reverse'));
    ok(!Domain::roleCan('logistics', 'invoices.view'));
    $courier = mkUser('courier');
    Auth::actAs($courier);
    throws(App\Core\HttpException::class, fn () => Auth::require('invoices.view'));
    Auth::actAs(null);
    throws(App\Core\HttpException::class, fn () => Auth::require('dashboard.view'));
});
test('Aucune notification externe simulée : statut réel enregistré', function () use ($db) {
    $rows = $db->all("SELECT DISTINCT status FROM notifications WHERE channel IN ('sms','whatsapp','email')");
    ok($rows !== [], 'tentatives journalisées');
    foreach ($rows as $r) {
        ok($r['status'] === 'not_configured', 'jamais « sent » sans prestataire');
    }
});
test('Indicateurs calculés depuis les données', function () use ($db, $admin) {
    Auth::actAs($admin);
    $dash = new DashboardService();
    $counts = $dash->statusCounts();
    eq(array_sum($counts), (int) $db->value('SELECT COUNT(*) FROM parcels'));
    $f = $dash->finance(null, null)['XOF'];
    eq($f['collected'], (int) $db->value("SELECT SUM(amount_minor) FROM payments WHERE currency = 'XOF'"));
    eq($f['receivable'], (int) $db->value("SELECT SUM(total_minor - paid_minor) FROM invoices WHERE kind='invoice' AND status IN ('validated','partial')"));
});
test('Liens de partage signés et expirables', function () {
    $sig = Signer::sign('facture', 5, time() + 60);
    ok(Signer::verify('facture', 5, time() + 60, $sig));
    ok(!Signer::verify('facture', 6, time() + 60, $sig), 'autre document');
    ok(!Signer::verify('facture', 5, time() - 1, Signer::sign('facture', 5, time() - 1)), 'expiré');
});
test('Génération PDF : facture, reçu, étiquette', function () use ($invoices, $payments, $payInvoice, $parcels, $newParcel) {
    $id = $payInvoice();
    $pid = $payments->record($id, ['amount' => '1000', 'method' => 'cash', 'form_token' => token()]);
    $pdf = new PdfService();
    foreach ([
        $pdf->invoice($invoices->get($id), $invoices->items($id), $payments->forInvoice($id), []),
        $pdf->receipt($payments->get($pid), $invoices->get($id)),
        $pdf->label($parcels->get($newParcel())),
    ] as $bytes) {
        ok(str_starts_with($bytes, '%PDF-1.4') && str_ends_with($bytes, '%%EOF'), 'structure PDF');
        ok(strlen($bytes) > 800, 'contenu');
    }
});
test('Journal d’audit des opérations sensibles', function () use ($db) {
    foreach (['parcel.created', 'invoice.validated', 'payment.recorded', 'payment.reversed', 'delivery.confirmed', 'expense.cancelled'] as $a) {
        ok((int) $db->value('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$a]) > 0, $a);
    }
    $details = implode(' ', array_column($db->all('SELECT details FROM audit_logs WHERE details IS NOT NULL'), 'details'));
    ok(!str_contains($details, '07 07 00 00 01'), 'pas de téléphone dans le journal');
});

echo "\nParamètres et droits de chargement\n";
test('Paramètres : validation, priorité sur .env, utilisés dans les PDF, journalisés', function () use ($db, $admin) {
    Auth::actAs($admin);
    Config::set('COMPANY_PHONE', '+225 11 11 11 11 11');
    App\Core\Settings::reset();
    eq(App\Core\Settings::get('COMPANY_PHONE'), '+225 11 11 11 11 11', 'sans valeur enregistrée : .env');
    throws(ValidationException::class, fn () => App\Core\Settings::save(['COMPANY_PHONE' => 'appelez-nous', 'COMPANY_DEPOT_INFO' => ''], (int) $admin['id']), 'téléphone invalide');
    throws(ValidationException::class, fn () => App\Core\Settings::save(['COMPANY_PHONE' => '', 'COMPANY_DEPOT_INFO' => str_repeat('x', 301)], (int) $admin['id']), 'texte trop long');
    $changed = App\Core\Settings::save(['COMPANY_PHONE' => ' +225 07 08 09 10 11 ', 'COMPANY_DEPOT_INFO' => "Lundi-samedi 8 h - 18 h\nTreichville"], (int) $admin['id']);
    eq(count($changed), 2, 'deux clés modifiées');
    eq(App\Core\Settings::get('COMPANY_PHONE'), '+225 07 08 09 10 11', 'valeur enregistrée prioritaire, espaces retirés');
    eq(App\Core\Settings::get('COMPANY_DEPOT_INFO'), "Lundi-samedi 8 h - 18 h\nTreichville", 'retour à la ligne conservé');
    eq(App\Core\Settings::save(['COMPANY_PHONE' => '+225 07 08 09 10 11', 'COMPANY_DEPOT_INFO' => "Lundi-samedi 8 h - 18 h\nTreichville"], (int) $admin['id']), [], 'aucune modification');
    $pid = (int) $db->value('SELECT id FROM parcels ORDER BY id LIMIT 1');
    $pdf = (new PdfService())->label((new ParcelService())->get($pid));
    ok(str_contains(pdf_text($pdf), '+225 07 08 09 10 11'), 'téléphone sur l’étiquette PDF');
    ok((int) $db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.updated'") === 1, 'journalisé une fois');
    eq(App\Core\Settings::save(['COMPANY_PHONE' => '', 'COMPANY_DEPOT_INFO' => ''], (int) $admin['id']) !== [], true);
    eq(App\Core\Settings::get('COMPANY_PHONE'), '', 'vidé volontairement : masqué (ne revient pas à .env)');
    ok(Domain::roleCan('admin', 'settings.manage') && !Domain::roleCan('manager', 'settings.manage'), 'réservé à l’administrateur');
});

test('Droits : le comptoir charge un vol en préparation, sans en changer le statut', function () use ($admin) {
    ok(Domain::roleCan('registrar', 'shipments.load') && !Domain::roleCan('registrar', 'shipments.edit'), 'comptoir');
    ok(!Domain::roleCan('cashier', 'shipments.load') && !Domain::roleCan('courier', 'shipments.load'), 'caisse et livreur exclus');
    ok(Domain::roleCan('logistics', 'shipments.load') && Domain::roleCan('logistics', 'shipments.edit'), 'logistique');
    eq(Domain::SHIPMENT_NEXT['preparation'], 'departed');
    foreach (Domain::SHIPMENT_TRANSITIONS as $from => $tos) {
        foreach ($tos as $to) {
            ok(isset(Domain::SHIPMENT_ACTIONS[$to]), "libellé d’action pour « $to »");
        }
        if (isset(Domain::SHIPMENT_NEXT[$from])) {
            ok(in_array(Domain::SHIPMENT_NEXT[$from], $tos, true), "étape suivante cohérente depuis « $from »");
        }
    }
    Auth::actAs($admin);
});

@unlink($tmp);
echo "\n$pass réussi(s), $fail échec(s)\n";
exit($fail ? 1 : 0);
