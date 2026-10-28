<?php $cfg = fn ($k, $d = '') => App\Core\Config::get($k, $d); ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Connexion') ?> | <?= e($cfg('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT')) ?></title>
<meta name="theme-color" content="#121419">
<meta name="robots" content="noindex">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<?= App\Core\View::partial('partials/pwa-head') ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-split">
  <section class="auth-show" aria-hidden="true">
    <div class="auth-show-top"><span class="mark">DI</span><span><span class="brand-name">DIARRASSOUBA</span><span class="brand-sub">Import · Export · Fret aérien</span></span></div>

    <svg class="route-map" viewBox="0 0 520 300" fill="none">
      <defs><pattern id="dots" width="14" height="14" patternUnits="userSpaceOnUse"><circle cx="1.5" cy="1.5" r="1.2" fill="currentColor" opacity=".18"/></pattern></defs>
      <rect width="520" height="300" fill="url(#dots)"/>
      <path class="arc" d="M440 70 C 360 40, 200 60, 120 210"/>
      <path class="arc arc-2" d="M440 70 C 370 70, 230 110, 70 170"/>
      <path class="arc arc-2" d="M440 70 C 380 90, 300 150, 210 225"/>
      <circle class="hub" cx="440" cy="70" r="7"/><circle class="hub-ring" cx="440" cy="70" r="16"/>
      <circle class="stop" cx="120" cy="210" r="6"/><circle class="stop dim" cx="70" cy="170" r="4.5"/><circle class="stop dim" cx="210" cy="225" r="4.5"/>
      <text x="456" y="58">CAI</text><text x="96" y="238">ABJ</text><text x="30" y="160" class="dim">DKR</text><text x="196" y="252" class="dim">BKO</text>
    </svg>

    <div class="mini-board">
      <div class="mb-head"><span>Vol</span><span>Destination</span><span>Statut</span></div>
      <div class="mb-row"><span>FRET 01</span><span>Abidjan</span><span class="ok">Embarquement</span></div>
      <div class="mb-row"><span>FRET 02</span><span>Dakar</span><span>À l’heure</span></div>
      <div class="mb-row"><span>FRET 03</span><span>Bamako</span><span>À l’heure</span></div>
    </div>
    <p class="auth-tagline"><?= e($cfg('COMPANY_TAGLINE', 'Transport aérien de colis : Égypte, Côte d’Ivoire, Afrique de l’Ouest')) ?></p>
  </section>

  <section class="auth-pane">
    <div class="auth-tools"><?= theme_toggle() ?></div>
    <div class="auth-card">
      <?= App\Core\View::partial('partials/flash') ?>
      <?= $content ?>
    </div>
    <p class="auth-foot">Un client veut suivre son colis ? <a href="<?= e(url('suivi')) ?>">Page de suivi publique</a></p>
  </section>
</div>
</body>
</html>
