<h1 style="margin-bottom:6px">Premier administrateur</h1>
<p class="muted small">Cette page disparaît dès qu’un compte existe. Retirez ensuite <code>INSTALL_TOKEN</code> du fichier <code>.env</code>.</p>
<form method="post" action="<?= e(url('installation')) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="install_token">Jeton d’installation (INSTALL_TOKEN)</label><input id="install_token" name="install_token" type="password" required></div>
  <div class="field"><label for="name">Nom complet</label><input id="name" name="name" required value="<?= old('name') ?>"<?= invalid('name') ?>><?= field_error('name') ?></div>
  <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" required value="<?= old('email') ?>"<?= invalid('email') ?>><?= field_error('email') ?></div>
  <div class="field"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password" required<?= invalid('password') ?>><span class="hint">10 caractères minimum, lettres et chiffres.</span><?= field_error('password') ?></div>
  <button class="btn btn-primary btn-block">Créer l’administrateur</button>
</form>
