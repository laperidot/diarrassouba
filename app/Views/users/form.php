<?php use App\Core\Domain; $x = $u ?? []; $v = fn ($k, $d = '') => old($k, $x[$k] ?? $d); ?>
<div class="page-head"><div><a class="crumb" href="<?= e(url('utilisateurs')) ?>">← Utilisateurs</a><h1><?= e($title) ?></h1></div></div>
<form class="panel form" method="post" action="<?= e(url($u ? 'utilisateurs/' . $u['id'] : 'utilisateurs')) ?>" style="max-width:560px">
  <?= csrf_field() ?>
  <div class="field"><label for="name">Nom complet</label><input id="name" name="name" required value="<?= $v('name') ?>"<?= invalid('name') ?>><?= field_error('name') ?></div>
  <div class="field"><label for="email">E-mail de connexion</label><input id="email" name="email" type="email" required value="<?= $v('email') ?>"<?= invalid('email') ?>><?= field_error('email') ?></div>
  <div class="field"><label for="phone">Téléphone</label><input id="phone" name="phone" type="tel" value="<?= $v('phone') ?>"<?= invalid('phone') ?>><?= field_error('phone') ?></div>
  <div class="field"><label for="role_code">Rôle</label><select id="role_code" name="role_code"<?= invalid('role_code') ?>><?php foreach (Domain::ROLES as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('role_code', 'registrar'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select><?= field_error('role_code') ?></div>
  <div class="field"><label for="password"><?= $u ? 'Nouveau mot de passe provisoire' : 'Mot de passe provisoire' ?></label><input id="password" name="password" type="password" autocomplete="new-password"<?= $u ? '' : ' required' ?><?= invalid('password') ?>><?= field_error('password') ?><span class="hint"><?= $u ? 'Laissez vide pour ne pas le changer. ' : '' ?>L’utilisateur devra le remplacer à sa prochaine connexion.</span></div>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked((bool) old('is_active', $u ? (string) $x['is_active'] : '1')) ?>> Compte actif</label>
  <button class="btn btn-primary"><?= $u ? 'Enregistrer' : 'Créer l’utilisateur' ?></button>
</form>
