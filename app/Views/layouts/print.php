<!doctype html>
<html lang="fr" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<style>body{background:#fff}.sheet{max-width:960px;margin:0 auto;padding:24px}</style>
</head>
<body><div class="sheet"><?= $content ?></div></body>
</html>
