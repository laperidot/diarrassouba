<?php use App\Core\Settings; ?>
<div class="page-head">
  <h1>Paramètres</h1>
  <p class="lede">Coordonnées montrées aux clients. Une modification s’applique immédiatement, sans toucher au serveur.</p>
</div>
<div class="grid grid-main">
  <form class="panel form" method="post" action="<?= e(url('parametres')) ?>">
    <?= csrf_field() ?>
    <fieldset style="margin-bottom:10px">
      <legend>Contact et retrait</legend>
      <?php foreach (Settings::EDITABLE as $key => $def): $id = strtolower($key); ?>
        <div class="field">
          <label for="<?= $id ?>"><?= e($def['label']) ?></label>
          <?php if ($def['multiline']): ?>
            <textarea id="<?= $id ?>" name="<?= $key ?>" maxlength="<?= $def['max'] ?>" rows="3"<?= invalid($key) ?>><?= old($key, $values[$key]) ?></textarea>
          <?php else: ?>
            <input id="<?= $id ?>" name="<?= $key ?>" maxlength="<?= $def['max'] ?>"<?= $key === 'COMPANY_PHONE' ? ' type="tel" inputmode="tel" placeholder="+225 07 00 00 00 00"' : '' ?> value="<?= old($key, $values[$key]) ?>"<?= invalid($key) ?>>
          <?php endif ?>
          <?= field_error($key) ?>
          <span class="hint"><?= e($def['hint']) ?><?= array_key_exists($key, $saved) ? '' : ' Valeur actuelle : fichier de configuration du serveur.' ?></span>
        </div>
      <?php endforeach ?>
    </fieldset>
    <button class="btn btn-primary">Enregistrer</button>
  </form>

  <aside class="panel">
    <div class="panel-head"><h2>Aperçu client</h2></div>
    <p class="small muted" style="margin-top:-6px">Ce que voit le client sur la page de suivi quand son colis est au dépôt.</p>
    <div class="settings-preview">
      <p class="label">Retrait au dépôt</p>
      <p data-preview="COMPANY_DEPOT_INFO"><?= nl2br(e($values['COMPANY_DEPOT_INFO'])) ?></p>
      <p class="label">Besoin d’aide</p>
      <p class="code" data-preview="COMPANY_PHONE"><?= e($values['COMPANY_PHONE']) ?></p>
    </div>
    <p class="small muted" style="margin:12px 0 0">Les factures, reçus et étiquettes déjà téléchargés ne changent pas ; les prochains utilisent la nouvelle valeur.</p>
  </aside>
</div>
