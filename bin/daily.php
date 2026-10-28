<?php
declare(strict_types=1);

// Tâche quotidienne (cron) : avertit l'équipe des factures arrivant à échéance
// et nettoie les traces techniques anciennes. Usage : php bin/daily.php

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Services\InvoiceService;
use App\Services\NotificationService;

$db = Db::get();
$notif = new NotificationService();
$count = 0;
foreach ((new InvoiceService())->dueSoon(3) as $inv) {
    $already = $db->value("SELECT id FROM notifications WHERE event = 'invoice.due_soon' AND entity_id = ? AND created_at >= ?", [$inv['id'], gmdate('Y-m-d 00:00:00')]);
    if ($already) {
        continue;
    }
    $due = (int) $inv['total_minor'] - (int) $inv['paid_minor'];
    $notif->staff(['manager', 'cashier'], 'invoice.due_soon', 'Échéance proche : ' . $inv['number'], $inv['client_name'] . ', reste ' . money($due, $inv['currency']) . ' au ' . d($inv['due_date']), 'invoice', (int) $inv['id']);
    $count++;
}
$db->run('DELETE FROM rate_limits WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
$db->run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
echo "Rappels d'échéance envoyés : $count\n";
