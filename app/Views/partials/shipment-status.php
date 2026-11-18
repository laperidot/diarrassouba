<?php
/*
 * Changement de statut d'un dossier — même composant sur la page du dossier et sur le tableau de bord.
 * Variables : $sh (dossier), $transitions (statuts possibles), $suffix (identifiants uniques), $retour ('' ou 'tableau-de-bord').
 */
use App\Core\Domain;

$suffix = $suffix ?? '';
$retour = $retour ?? '';
$next = Domain::SHIPMENT_NEXT[$sh['status']] ?? null;
$default = in_array($next, $transitions, true) ? $next : ($transitions[0] ?? null);
?>
<form class="status-form" method="post" action="<?= e(url('dossiers/' . $sh['id'] . '/statut')) ?>">
  <?= csrf_field() ?>
  <?php if ($retour !== ''): ?><input type="hidden" name="retour" value="<?= e($retour) ?>"><?php endif ?>
  <label class="label" for="ship-status<?= e($suffix) ?>">Où en est le vol<?= $retour !== '' ? ' ' . e($sh['reference']) : '' ?> ?</label>
  <div class="status-row">
    <select id="ship-status<?= e($suffix) ?>" name="status">
      <?php foreach ($transitions as $t): ?><option value="<?= e($t) ?>"<?= $t === $default ? ' selected' : '' ?>><?= e(Domain::SHIPMENT_ACTIONS[$t] ?? Domain::SHIPMENT_STATUSES[$t]) ?></option><?php endforeach ?>
    </select>
    <button class="btn btn-primary">Valider</button>
  </div>
  <details class="status-more">
    <summary>Changer la date ou les options</summary>
    <div class="field" style="margin-top:10px"><label for="ship-when<?= e($suffix) ?>">Date et heure</label><input id="ship-when<?= e($suffix) ?>" name="occurred_at" type="datetime-local"><span class="hint">Laissez vide pour maintenant.</span></div>
    <label class="check"><input type="checkbox" name="apply_parcels" value="1" checked> Changer aussi l’étape de tous les colis du vol (conseillé). Les colis qui ont un problème ou qui sont déjà plus loin ne bougent pas.</label>
    <?php if (in_array('closed', $transitions, true)): ?><label class="check"><input type="checkbox" name="force_close" value="1"> Terminer quand même, même si des colis ne sont pas encore remis</label><?php endif ?>
  </details>
</form>
