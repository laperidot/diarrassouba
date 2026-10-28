<?php
declare(strict_types=1);

// Sauvegarde : base (fichier SQL compressé) + pièces jointes (zip) dans storage/backups/.
// Usage : php bin/backup.php            (à planifier chaque nuit dans le cron Hostinger)
// Conserve les BACKUP_KEEP_DAYS derniers jours (30 par défaut).

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

$db = Db::get();
$dir = APP_ROOT . '/storage/backups';
@mkdir($dir, 0750, true);
$stamp = gmdate('Ymd-His');
$file = "$dir/db-$stamp.sql.gz";
$gz = gzopen($file, 'wb6');
// SET NAMES : sans lui, le client mysql de restauration lit le fichier dans son propre jeu (latin1, cp850…) et corrompt les accents.
gzwrite($gz, "-- Sauvegarde DIARRASSOUBA " . gmdate('c') . " (pilote {$db->driver})\n"
    . ($db->driver === 'mysql' ? "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\n" : '') . "SET FOREIGN_KEY_CHECKS=0;\n");

$tables = $db->driver === 'mysql'
    ? array_column($db->all('SHOW TABLES'), array_key_first($db->all('SHOW TABLES')[0] ?? ['x' => 1]))
    : array_column($db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name');

foreach ($tables as $t) {
    if ($db->driver === 'mysql') {
        $create = $db->one("SHOW CREATE TABLE `$t`");
        gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n" . array_values($create)[1] . ";\n");
    }
    $stmt = $db->pdo->query("SELECT * FROM $t");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vals = array_map(fn ($v) => $v === null ? 'NULL' : $db->pdo->quote((string) $v), array_values($row));
        gzwrite($gz, "INSERT INTO `$t` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $vals) . ");\n");
    }
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
gzclose($gz);
echo "Base sauvegardée : $file (" . round(filesize($file) / 1024) . " Ko)\n";

// Pièces jointes
$uploads = APP_ROOT . '/storage/uploads';
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ATTENTION : extension PHP « zip » absente, pièces jointes NON sauvegardées (activer extension=zip dans php.ini).\n");
} elseif (is_dir($uploads)) {
    $zipFile = "$dir/fichiers-$stamp.zip";
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
    $n = 0;
    foreach ($it as $f) {
        // Séparateurs « / » dans l'archive, même sous Windows (restauration possible sur le serveur Linux).
        $zip->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($uploads) + 1)));
        $n++;
    }
    $zip->close();
    echo $n ? "Pièces jointes : $zipFile ($n fichiers)\n" : "Aucune pièce jointe.\n";
    if (!$n) {
        @unlink($zipFile);
    }
}

// Rotation
$keep = Config::int('BACKUP_KEEP_DAYS', 30);
foreach (glob("$dir/{db,fichiers}-*", GLOB_BRACE) ?: [] as $old) {
    if (filemtime($old) < time() - $keep * 86400) {
        unlink($old);
    }
}
