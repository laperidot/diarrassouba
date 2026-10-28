<div class="page-head"><h1>Changer mon mot de passe</h1></div>
<form class="panel form" method="post" action="<?= e(url('mot-de-passe')) ?>" style="max-width:480px">
  <?= csrf_field() ?>
  <div class="field"><label for="current_password">Mot de passe actuel</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required<?= invalid('current_password') ?>><?= field_error('current_password') ?></div>
  <div class="field"><label for="password">Nouveau mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password" required<?= invalid('password') ?>><span class="hint">10 caractères minimum, avec des lettres et au moins un chiffre.</span><?= field_error('password') ?></div>
  <div class="field"><label for="password_confirm">Confirmez le nouveau mot de passe</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required<?= invalid('password_confirm') ?>><?= field_error('password_confirm') ?></div>
  <button class="btn btn-primary">Enregistrer le mot de passe</button>
</form>
