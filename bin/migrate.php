<?php
declare(strict_types=1);

// Usage : php bin/migrate.php
// Applique uniquement les migrations manquantes. Ne vide et ne supprime aucune donnée.

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$migrator = new App\Core\Migrator(App\Core\Db::get());
$applied = $migrator->migrate();
echo $applied ? 'Migrations appliquées : ' . implode(', ', $applied) . "\n" : "Base déjà à jour.\n";
