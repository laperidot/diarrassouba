<?php
declare(strict_types=1);

/**
 * Amorçage commun (web + CLI) : autoload, configuration, erreurs, base de données.
 */

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_ROOT . '/app/helpers.php';

App\Core\Config::load(APP_ROOT . '/.env');

date_default_timezone_set('UTC'); // stockage en UTC, affichage dans APP_TIMEZONE

$debug = App\Core\Config::bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
$logDir = storage_path('logs');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
ini_set('error_log', $logDir . '/php-error.log');
