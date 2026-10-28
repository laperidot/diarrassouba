<?php if (empty($user)): ?>
<div class="public-top"><div class="inner" style="padding-bottom:28px"><div class="public-bar"><a class="brand" href="<?= e(url('suivi')) ?>"><span class="brand-name">DIARRASSOUBA</span><span class="brand-sub">Import-Export</span></a><?= theme_toggle() ?></div></div></div>
<div class="public-main" style="margin-top:24px">
<?php endif ?>
<div class="panel empty" style="max-width:560px;margin:24px auto">
  <p class="code muted" style="font-size:2.4rem;margin:0"><?= (int) $code ?></p>
  <h1 style="margin:6px 0 10px"><?= e($title) ?></h1>
  <p><?= e($text) ?></p>
  <a class="btn btn-primary" href="<?= e(url(empty($user) ? 'suivi' : 'tableau-de-bord')) ?>"><?= empty($user) ? 'Suivre un colis' : 'Retour au tableau de bord' ?></a>
</div>
<?php if (empty($user)): ?></div><?php endif ?>
