<?php
use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Session;

$path = Request::path();
$isActive = fn (string ...$prefixes) => array_filter($prefixes, fn ($p) => $path === $p || str_starts_with($path, $p . '/')) ? ' aria-current="page"' : '';
$courier = Auth::role() === 'courier';
$unread = Auth::user() ? (new App\Services\NotificationService())->unreadCount((int) Auth::id()) : 0;
$appName = Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT');
$initials = implode('', array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/', trim($user['name'] ?? '?')) ?: ['?'], 0, 2)));
// Heures des deux escales (affichage ; mises à jour par app.js)
$clock = fn (string $tz) => (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('H:i');
$link = fn (string $href, string $icon, string $label, string ...$prefixes) => '<a class="side-link" href="' . e(url($href)) . '" title="' . e($label) . '"' . $isActive(...($prefixes ?: ['/' . $href])) . '>' . icon($icon) . '<span>' . e($label) . '</span></a>';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title ?? 'Accueil') ?> | <?= e($appName) ?></title>
<meta name="theme-color" content="#121419">
<meta name="csrf-token" content="<?= e(Session::csrfToken()) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<?= App\Core\View::partial('partials/pwa-head') ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="has-shell" data-base="<?= e(base_path()) ?>" data-sw="<?= e(url('sw.js')) ?>">
<a class="sr-only" href="#contenu">Aller au contenu</a>

<aside class="sidebar" aria-label="Navigation principale">
  <a class="side-brand" href="<?= e(url($courier ? 'livraisons' : 'tableau-de-bord')) ?>" aria-label="Accueil <?= e($appName) ?>">
    <span class="mark" aria-hidden="true">DI</span>
    <span class="side-brand-text"><span class="brand-name">DIARRASSOUBA</span><span class="brand-sub">Import-Export</span></span>
  </a>
  <div class="side-route" aria-hidden="true"><span>CAI</span><i></i><?= icon('plane') ?><i></i><span>ABJ</span></div>

  <nav class="side-nav">
    <p class="side-group">Opérations</p>
    <?php if (!$courier): ?><?= $link('tableau-de-bord', 'home', 'Tableau de bord') ?><?php endif ?>
    <?php if (can('parcels.view') && !$courier): ?><?= $link('colis', 'box', 'Colis') ?><?php endif ?>
    <?php if (can('parcels.view')): ?><?= $link('scanner', 'scan', 'Scanner') ?><?php endif ?>
    <?php if (can('shipments.view')): ?><?= $link('dossiers', 'plane', 'Dossiers de vol') ?><?php endif ?>
    <?php if (can('deliveries.view')): ?><?= $link('livraisons', 'truck', $courier ? 'Mes livraisons' : 'Livraisons') ?><?php endif ?>
    <?php if (can('clients.view')): ?><?= $link('clients', 'users', 'Clients') ?><?php endif ?>

    <?php if (can('invoices.view') || can('expenses.view')): ?>
    <p class="side-group">Finances</p>
    <?php if (can('invoices.view')): ?><?= $link('factures', 'invoice', 'Factures') ?><?= $link('paiements', 'cash', 'Paiements') ?><?php endif ?>
    <?php if (can('expenses.view')): ?><?= $link('depenses', 'wallet', 'Dépenses') ?><?php endif ?>
    <?php endif ?>

    <p class="side-group">Station</p>
    <a class="side-link" href="<?= e(url('notifications')) ?>" title="Notifications"<?= $isActive('/notifications') ?>><?= icon('bell') ?><span>Notifications</span><?php if ($unread): ?><b class="side-count"><?= $unread > 99 ? '99+' : $unread ?></b><?php endif ?></a>
    <?php if (can('users.manage')): ?><?= $link('utilisateurs', 'users', 'Utilisateurs') ?><?php endif ?>
    <?php if (can('tariffs.manage')): ?><?= $link('tarifs', 'tag', 'Tarifs au kilo') ?><?php endif ?>
    <?php if (can('audit.view')): ?><?= $link('journal', 'shield', 'Journal') ?><?php endif ?>
    <?php if (can('settings.manage')): ?><?= $link('parametres', 'settings', 'Paramètres') ?><?php endif ?>
    <a class="side-link" href="<?= e(url('suivi')) ?>" title="Suivi public" target="_blank" rel="noopener"><?= icon('route') ?><span>Suivi public</span></a>
  </nav>

  <div class="side-foot">
    <button type="button" class="side-link side-install" data-install hidden title="Installer l’application"><?= icon('download') ?><span>Installer l’application</span></button>
    <div class="user-card">
      <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
      <span class="who"><strong><?= e($user['name'] ?? '') ?></strong><span><?= e(App\Core\Domain::ROLES[$user['role_code'] ?? ''] ?? '') ?></span></span>
      <a class="icon-btn" href="<?= e(url('mot-de-passe')) ?>" title="Changer mon mot de passe"><?= icon('key') ?><span class="sr-only">Changer mon mot de passe</span></a>
      <form method="post" action="<?= e(url('deconnexion')) ?>"><?= csrf_field() ?><button class="icon-btn" title="Se déconnecter"><?= icon('logout') ?><span class="sr-only">Se déconnecter</span></button></form>
    </div>
  </div>
</aside>

<div class="shell-main">
  <header class="topbar">
    <a class="mark mark-sm mob-only" href="<?= e(url($courier ? 'livraisons' : 'tableau-de-bord')) ?>" aria-label="Accueil">DI</a>
    <span class="page-title-mobile"><?= e($title ?? '') ?></span>
    <?php if (can('parcels.view') && !$courier): ?>
    <form class="top-search desk-only" method="get" action="<?= e(url('colis')) ?>" role="search">
      <?= icon('search') ?>
      <label class="sr-only" for="top-q">Rechercher un colis</label>
      <input id="top-q" name="q" type="search" placeholder="Code DI-…, client ou téléphone" autocomplete="off">
    </form>
    <?php endif ?>
    <div class="clocks desk-only" aria-label="Heure locale des escales">
      <span class="clock"><b>ABJ</b><time data-clock="Africa/Abidjan"><?= $clock('Africa/Abidjan') ?></time></span>
      <span class="clock"><b>CAI</b><time data-clock="Africa/Cairo"><?= $clock('Africa/Cairo') ?></time></span>
    </div>
    <a class="icon-btn desk-only" href="<?= e(url('scanner')) ?>" title="Scanner un colis"><?= icon('scan') ?><span class="sr-only">Scanner un colis</span></a>
    <?= theme_toggle() ?>
    <a class="icon-btn" href="<?= e(url('notifications')) ?>" title="Notifications"><?= icon('bell') ?><span class="sr-only">Notifications</span><?php if ($unread): ?><span class="badge-dot"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif ?></a>
  </header>

  <main class="app-main" id="contenu">
    <?= App\Core\View::partial('partials/flash') ?>
    <?= $content ?>
  </main>
</div>

<nav class="tabbar" aria-label="Navigation mobile">
  <?php if ($courier): ?>
    <a href="<?= e(url('livraisons')) ?>"<?= $isActive('/livraisons') ?>><?= icon('truck') ?>Livraisons</a>
  <?php else: ?>
    <a href="<?= e(url('tableau-de-bord')) ?>"<?= $isActive('/tableau-de-bord') ?>><?= icon('home') ?>Accueil</a>
  <?php endif ?>
  <a href="<?= e(url($courier ? 'notifications' : 'colis')) ?>"<?= $isActive($courier ? '/notifications' : '/colis') ?>><?= icon($courier ? 'bell' : 'box') ?><?= $courier ? 'Alertes' : 'Colis' ?></a>
  <a class="scan-tab" href="<?= e(url('scanner')) ?>"<?= $isActive('/scanner') ?>><span class="disc"><?= icon('scan') ?></span>Scanner</a>
  <?php if (can('shipments.view')): ?>
    <a href="<?= e(url('dossiers')) ?>"<?= $isActive('/dossiers') ?>><?= icon('plane') ?>Vols</a>
  <?php else: ?>
    <a href="<?= e(url('livraisons')) ?>"<?= $isActive('/livraisons') ?>><?= icon('truck') ?>Remises</a>
  <?php endif ?>
  <a href="<?= e(url('menu')) ?>"<?= $isActive('/menu') ?>><?= icon('menu') ?>Menu</a>
</nav>

<div class="offline-banner" role="status" aria-live="polite" id="offline-banner">Pas de connexion. Les informations affichées peuvent être anciennes ; aucun enregistrement n’est possible tant que la connexion n’est pas revenue.</div>
<div class="update-banner" role="status" id="update-banner"><span class="small">Une nouvelle version de l’application est prête.</span><button class="btn btn-sm btn-go" id="update-btn">Mettre à jour</button></div>
</body>
</html>
