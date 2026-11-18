<?php use App\Core\Domain; $open = in_array($d['status'], ['planned', 'in_progress'], true); $phone = $d['delivery_contact_phone'] ?: $d['recipient_phone']; ?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url('livraisons')) ?>">← Remises</a><h1><?= e(Domain::DELIVERY_MODES[$d['mode']]) ?></h1></div>
  <?= status_badge($d['status'], 'delivery') ?>
</div>
<div class="grid grid-main">
  <div class="stack">
    <section class="hero-card">
      <div class="small muted">Colis</div>
      <div class="tracking-big"><?= e($d['tracking_code']) ?></div>
      <p style="margin:8px 0 0"><?= e($d['description']) ?>, <?= e(kg((int) $d['weight_g'])) ?>, <?= (int) $d['pieces'] ?> pièce<?= $d['pieces'] > 1 ? 's' : '' ?></p>
    </section>
    <section class="panel">
      <dl class="dl">
        <dt>Remettre à</dt><dd><strong><?= e($d['delivery_contact_name'] ?: $d['recipient_client']) ?></strong></dd>
        <dt>Téléphone</dt><dd><a class="btn btn-sm" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $phone)) ?>"><?= icon('user') ?><?= e($phone) ?></a></dd>
        <?php if ($d['mode'] === 'delivery'): ?><dt>Adresse</dt><dd><?= e($d['address'] ?: ($d['recipient_address'] ?: 'Non précisée : appeler le destinataire')) ?></dd><?php endif ?>
        <dt>Livreur</dt><dd><?= e($d['agent_name'] ?? '—') ?></dd>
        <dt>Prévu le</dt><dd><?= e(d($d['planned_date'])) ?></dd>
        <dt>Essai</dt><dd><?= (int) $d['attempt_no'] ?><?= (int) $d['attempt_no'] === 1 ? 'er' : 'e' ?> essai</dd>
        <?php if ($d['status'] === 'done'): ?>
          <dt>Remis à</dt><dd><?= e($d['recipient_name']) ?><?= $d['recipient_id_note'] ? ' (' . e($d['recipient_id_note']) . ')' : '' ?></dd>
          <dt>Noté le</dt><dd><?= e(dt($d['confirmed_at'])) ?> par <?= e($d['confirmed_by_name'] ?? '—') ?></dd>
        <?php endif ?>
        <?php if ($d['incident']): ?><dt>Problème</dt><dd><?= e($d['incident']) ?></dd><?php endif ?>
      </dl>
      <?php if ($proofs): ?><hr><p class="label">Photos de la remise</p><?php foreach ($proofs as $pr): ?><div class="kv-line"><a href="<?= e(url('fichiers/preuves/' . $pr['id'])) ?>" target="_blank" rel="noopener"><?= e($pr['original_name']) ?></a><span class="small muted"><?= e(dt($pr['created_at'])) ?></span></div><?php endforeach ?><?php endif ?>
      <p style="margin:12px 0 0"><a href="<?= e(url('colis/' . $d['parcel_id'])) ?>">Voir la fiche du colis</a></p>
    </section>
  </div>
  <?php if ($open && can('deliveries.confirm')): ?>
  <aside class="stack">
    <?php if ($d['mode'] === 'delivery' && $d['status'] === 'planned'): ?>
      <section class="panel">
        <h2 style="margin-bottom:8px">Vous partez livrer ?</h2>
        <p class="small muted">Le client verra que son colis est en route.</p>
        <form method="post" action="<?= e(url('livraisons/' . $d['id'] . '/demarrer')) ?>"><?= csrf_field() ?><button class="btn btn-go btn-block"><?= icon('truck') ?>Je pars livrer</button></form>
      </section>
    <?php else: ?>
      <section class="panel">
        <h2 style="margin-bottom:12px">Le colis est remis ?</h2>
        <form method="post" enctype="multipart/form-data" action="<?= e(url('livraisons/' . $d['id'] . '/confirmer')) ?>" data-once>
          <?= csrf_field() ?>
          <div class="field"><label for="recipient_name">Nom de la personne qui reçoit</label><input id="recipient_name" name="recipient_name" required value="<?= old('recipient_name') ?>"<?= invalid('recipient_name') ?>><?= field_error('recipient_name') ?></div>
          <div class="field"><label for="recipient_id_note">Pièce d’identité vue <span class="muted">(facultatif : type et 4 derniers chiffres)</span></label><input id="recipient_id_note" name="recipient_id_note" maxlength="120" placeholder="CNI …1234"></div>
          <div class="field"><label for="proof">Photo du colis remis ou de la signature <span class="muted">(facultatif)</span></label><input id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></div>
          <label class="check"><input type="checkbox" name="confirm" value="1" required<?= invalid('confirm') ?>> J’ai bien donné le colis <?= e($d['tracking_code']) ?> à cette personne.</label>
          <?= field_error('confirm') ?>
          <button class="btn btn-go btn-block"><?= icon('check') ?>C’est remis</button>
        </form>
      </section>
    <?php endif ?>
    <section class="panel">
      <details class="inline-form"><summary class="btn btn-block">Je n’ai pas pu livrer…</summary>
        <form method="post" action="<?= e(url('livraisons/' . $d['id'] . '/echec')) ?>"><?= csrf_field() ?>
          <div class="field"><label for="incident">Que s’est-il passé ?</label><textarea id="incident" name="incident" required placeholder="Destinataire absent, injoignable, adresse introuvable…"></textarea></div>
          <button class="btn btn-danger btn-block">Enregistrer</button>
        </form>
      </details>
      <?php if ($d['status'] === 'planned' && can('deliveries.manage')): ?>
      <form method="post" action="<?= e(url('livraisons/' . $d['id'] . '/annuler')) ?>" style="margin-top:8px"><?= csrf_field() ?><button class="btn btn-quiet btn-block" data-confirm="Annuler cette livraison prévue ?">Annuler cette livraison</button></form>
      <?php endif ?>
    </section>
  </aside>
  <?php endif ?>
</div>
