<p class="eyebrow">Espace de l’équipe</p>
<h1 style="margin-bottom:6px">Connexion</h1>
<p class="muted" style="margin-bottom:22px">Comptoir, piste, caisse et livraison : un seul accès.</p>
<?php if ($needsInstall): ?>
  <div class="flash flash-info"><?= icon('key') ?><span>Aucun compte n’existe encore. Créez le premier administrateur avec <code>php bin/create-admin.php</code> ou la page <a href="<?= e(url('installation')) ?>">d’installation</a> (jeton requis).</span></div>
<?php endif ?>
<form method="post" action="<?= e(url('connexion')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= old('email') ?>" autofocus></div>
  <div class="field"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
  <button class="btn btn-primary btn-block btn-lg">Se connecter <?= icon('chevron') ?></button>
</form>
