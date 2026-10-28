<?php $cfg = fn ($k, $d = '') => App\Core\Config::get($k, $d); ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Suivi de colis') ?> | <?= e($cfg('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT')) ?></title>
<meta name="description" content="Suivez votre colis Égypte – Afrique de l'Ouest avec votre code de suivi.">
<meta name="theme-color" content="#121419">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<?= App\Core\View::partial('partials/pwa-head') ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/public.js')) ?>" defer></script>
</head>
<body class="public-body">
<?= $content ?>
<footer class="public-foot">
  <strong><?= e($cfg('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT')) ?></strong><br>
  <?= e(trim($cfg('COMPANY_ADDRESS') . ' ' . App\Core\Settings::get('COMPANY_PHONE'))) ?>
  <?php if ($cfg('COMPANY_EMAIL')): ?><br><a href="mailto:<?= e($cfg('COMPANY_EMAIL')) ?>"><?= e($cfg('COMPANY_EMAIL')) ?></a><?php endif ?>
</footer>
</body>
</html>
