<?php
use App\Core\Domain;
$c = $client ?? [];
$v = fn ($k, $d = '') => old($k, $c[$k] ?? $d);
$errors = App\Core\Session::errors();
?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url($client ? 'clients/' . $client['id'] : 'clients')) ?>">← <?= $client ? e($client['name']) : 'Clients' ?></a><h1><?= e($title) ?></h1></div>
</div>
<form class="panel form" method="post" action="<?= e(url($client ? 'clients/' . $client['id'] : 'clients')) ?>">
  <?= csrf_field() ?>
  <?php if (isset($errors['duplicate'])): ?>
    <label class="check flash flash-warning"><input type="checkbox" name="confirm_duplicate" value="1"> Je confirme qu’il s’agit d’une autre personne : créer la fiche quand même.</label>
  <?php endif ?>
  <fieldset>
    <legend>Identité</legend>
    <div class="row row-2">
      <div class="field"><label for="name">Nom complet ou raison sociale</label><input id="name" name="name" required value="<?= $v('name') ?>"<?= invalid('name') ?>><?= field_error('name') ?></div>
      <div class="field"><label for="kind">Type</label><select id="kind" name="kind"><option value="person"<?= selected($v('kind', 'person'), 'person') ?>>Particulier</option><option value="company"<?= selected($v('kind'), 'company') ?>>Entreprise</option></select></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>Coordonnées</legend>
    <div class="row row-2">
      <div class="field"><label for="phone">Téléphone</label><input id="phone" name="phone" type="tel" inputmode="tel" required value="<?= $v('phone') ?>"<?= invalid('phone') ?>><span class="hint">Avec l’indicatif pour l’étranger : +20 pour l’Égypte.</span><?= field_error('phone') ?></div>
      <div class="field"><label for="phone_alt">Second téléphone <span class="muted">(facultatif)</span></label><input id="phone_alt" name="phone_alt" type="tel" inputmode="tel" value="<?= $v('phone_alt') ?>"<?= invalid('phone_alt') ?>><?= field_error('phone_alt') ?></div>
    </div>
    <div class="field"><label for="email">E-mail <span class="muted">(facultatif, pour recevoir factures et avis)</span></label><input id="email" name="email" type="email" value="<?= $v('email') ?>"<?= invalid('email') ?>><?= field_error('email') ?></div>
    <div class="row row-2">
      <div class="field"><label for="country">Pays</label><select id="country" name="country"><option value="">—</option><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('country', 'CI'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="city">Ville</label><input id="city" name="city" value="<?= $v('city') ?>"></div>
    </div>
    <div class="field"><label for="address">Adresse ou repère de livraison</label><input id="address" name="address" value="<?= $v('address') ?>" placeholder="Quartier, rue, repère"></div>
  </fieldset>
  <fieldset>
    <legend>Facturation</legend>
    <div class="field"><label for="default_payment_terms">Modalité de paiement habituelle</label><select id="default_payment_terms" name="default_payment_terms"><option value="">À préciser à chaque facture</option><?php foreach (Domain::PAYMENT_TERMS as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('default_payment_terms'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="notes">Notes internes</label><textarea id="notes" name="notes"><?= $v('notes') ?></textarea><span class="hint">Jamais visibles par le client.</span></div>
  </fieldset>
  <div class="form-actions sticky"><button class="btn btn-primary"><?= $client ? 'Enregistrer les modifications' : 'Créer le client' ?></button><a class="btn btn-quiet" href="<?= e(url($client ? 'clients/' . $client['id'] : 'clients')) ?>">Annuler</a></div>
</form>
