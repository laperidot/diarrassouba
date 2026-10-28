<?php
declare(strict_types=1);

/**
 * Point d'entrée unique de l'application.
 * Sur Hostinger : placer le contenu de public/ dans public_html/ et le reste du projet
 * un niveau au-dessus (voir README), ou définir APP_ROOT_PATH.
 */

// Serveur de développement PHP : laisser passer les fichiers statiques.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($f) && !str_ends_with($f, '.php')) {
        return false;
    }
}

$root = getenv('APP_ROOT_PATH') ?: dirname(__DIR__);
if (!is_file($root . '/app/bootstrap.php') && is_file(__DIR__ . '/../diarrassouba/app/bootstrap.php')) {
    $root = __DIR__ . '/../diarrassouba';
}
require $root . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

// En-têtes de sécurité
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (Config::bool('FORCE_HTTPS', false)) {
    header('Strict-Transport-Security: max-age=31536000');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$https) {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
}

Session::start();

$router = require APP_ROOT . '/app/routes.php';
$method = Request::method();
$path = Request::path();

try {
    if ($method === 'POST' && !str_starts_with($path, '/api/public/')) {
        if (!Session::verifyCsrf($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            throw new HttpException(419);
        }
    }
    // Utilisateur devant changer son mot de passe provisoire
    $u = Auth::user();
    if ($u && (int) $u['must_change_password'] === 1 && !in_array($path, ['/mot-de-passe', '/deconnexion'], true) && !str_starts_with($path, '/assets')) {
        Session::flash('info', 'Choisissez votre mot de passe personnel avant de continuer.');
        header('Location: ' . url('mot-de-passe'), true, 303);
        exit;
    }
    $output = $router->dispatch($method, $path);
    if (is_string($output)) {
        header('Content-Type: text/html; charset=utf-8');
        if (Auth::user()) {
            header('Cache-Control: private, no-store');
        }
        echo $output;
    }
    Session::clearOld();
} catch (HttpException $e) {
    if ($e->status === 401) {
        if (Request::wantsJson()) {
            http_response_code(401);
            echo json_encode(['error' => 'Session expirée']);
            exit;
        }
        $_SESSION['intended'] = $path;
        header('Location: ' . url('connexion'), true, 303);
        exit;
    }
    // 419 n'est pas un code HTTP standard : Apache et LiteSpeed le transforment en 500. On envoie 403.
    http_response_code($e->status === 419 ? 403 : $e->status);
    $messages = [
        403 => ['Accès non autorisé', 'Votre rôle ne permet pas d’ouvrir cette page. Demandez l’accès à un administrateur.'],
        404 => ['Page introuvable', 'L’adresse ne correspond à aucune page, ou l’élément a été déplacé.'],
        405 => ['Action non permise', 'Cette adresse ne s’utilise pas de cette façon.'],
        419 => ['Formulaire expiré', 'La page est restée ouverte trop longtemps. Revenez en arrière, rechargez la page et renvoyez le formulaire.'],
        429 => ['Trop de tentatives', 'Patientez quelques minutes avant de réessayer.'],
    ];
    [$title, $text] = $messages[$e->status] ?? ['Erreur', $e->getMessage()];
    if (Request::wantsJson()) {
        echo json_encode(['error' => $title]);
        exit;
    }
    echo View::render('misc/error', ['title' => $title, 'text' => $e->getMessage() ?: $text, 'code' => $e->status, 'user' => Auth::user()], Auth::user() ? 'app' : 'public');
} catch (Throwable $e) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log("[$ref] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if (Config::bool('APP_DEBUG', false)) {
        echo '<pre>' . e((string) $e) . '</pre>';
        exit;
    }
    echo View::render('misc/error', [
        'title' => 'Erreur temporaire',
        'text' => "L’opération n’a pas abouti et rien n’a été enregistré à moitié. Réessayez ; si le problème continue, communiquez la référence $ref à l’administrateur.",
        'code' => 500, 'user' => null,
    ], 'public');
}
