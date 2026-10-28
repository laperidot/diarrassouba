<?php
declare(strict_types=1);

/**
 * Point d'entrée Vercel (runtime communautaire vercel-php) : toutes les pages passent ici (voir vercel.json).
 * Le code est en lecture seule : les écritures vont dans /tmp, et l'application est servie à la racine du domaine.
 */

// Essai local (php -S … -t public api/index.php) : fichiers statiques servis directement, comme le font les routes Vercel.
if (PHP_SAPI === 'cli-server' && is_file(dirname(__DIR__) . '/public' . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))
    && !str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '.php')) {
    return false;
}

// Vercel exécute /api/index.php : sans cela, base_path() vaudrait « /api ».
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// Adresse du visiteur (limites de tentatives, journal d'audit) : en-tête posé par Vercel.
if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_REAL_IP'];
}
if (getenv('STORAGE_PATH') === false) {
    putenv('STORAGE_PATH=/tmp/diarrassouba');
}

require dirname(__DIR__) . '/public/index.php';
