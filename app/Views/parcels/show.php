<?php
use App\Core\Domain;
$p = $parcel;
$from = Domain::iata($p['origin_city'], $p['origin_country']);
$to = Domain::iata($p['destination_city'], $p['destination_country']);
$openDelivery = array_values(array_filter($deliveries, fn ($d) => in_array($d['status'], ['planned', 'in_progress'], true)))[0] ?? null;
?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url('colis')) ?>">← Colis</a><h1 class="code" style="font-size:1.9rem"><?= e($p['tracking_code']) ?></h1></div>
  <div class="actions">
    <a class="btn btn-primary" href="<?= e(url('colis/' . $p['id'] . '/etiquette')) ?>" target="_blank" rel="noopener"><?= icon('print') ?>Étiquette</a>
    <button class="btn" type="button" data-share-url="<?= e(absolute_url('suivi/' . $p['tracking_code'])) ?>" data-share-title="Suivi du colis <?= e($p['tracking_code']) ?>"><?= icon('share') ?>Lien de suivi</button>
    <?php if (can('parcels.edit')): ?><a class="btn" href="<?= e(url('colis/' . $p['id'] . '/modifier')) ?>"><?= icon('edit') ?>Modifier</a><?php endif ?>
  </div>
</div>
<?php if (($_GET['suite'] ?? '') === '1' && can('parcels.create')): ?>
<section class="panel next-parcel" aria-labelledby="next-parcel-title">
  <h2 id="next-parcel-title">Et maintenant ?</h2>
  <div class="next-parcel-actions">
    <a class="btn btn-go btn-lg" href="<?= e(url('colis/' . $p['id'] . '/etiquette')) ?>" target="_blank" rel="noopener" autofocus><?= icon('print') ?>1. Imprimer l’étiquette</a>
    <a class="btn btn-lg" href="<?= e(url('colis/nouveau', ['expediteur' => $p['sender_id'], 'destination' => $p['destination_country'], 'ville' => $p['destination_city']])) ?>"><?= icon('plus') ?>2. Autre colis de <?= e($p['sender_name']) ?></a>
    <a class="btn btn-quiet btn-lg" href="<?= e(url('colis/nouveau')) ?>"><?= icon('plus') ?>Colis d’un autre client</a>
  </div>
</section>
<?php endif ?>

<div class="grid grid-main">
  <div class="stack">
    <section class="hero-card">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap">
        <div><div class="small muted">Contenu</div><div style="font-weight:600"><?= e($p['description']) ?></div></div>
        <?= status_badge($p['status']) ?>
      </div>
      <div class="route" style="margin-top:16px"><span class="iata"><?= e($from) ?></span><span class="leg"><?= icon('plane') ?></span><span class="iata"><?= e($to) ?></span></div>
      <div class="route-cities"><span><?= e($p['origin_city'] ?: country_name($p['origin_country'])) ?></span><span><?= e($p['destination_city'] ?: country_name($p['destination_country'])) ?></span></div>
    </section>
    <section class="panel">
      <?= parcel_rail($p['status']) ?>
    </section>

    <?php if (can('parcels.status') && $transitions): ?>
    <section class="panel">
      <details class="inline-form"<?= App\Core\Session::errors() ? ' open' : '' ?>>
        <summary class="btn btn-block"><?= icon('route') ?>Dire où en est le colis</summary>
        <form method="post" action="<?= e(url('colis/' . $p['id'] . '/statut')) ?>">
          <?= csrf_field() ?>
          <div class="field"><label for="status">Le colis est maintenant…</label>
            <select id="status" name="status" required>
              <?php foreach ($transitions as $t): ?><option value="<?= $t ?>"><?= e(Domain::parcelLabel($t)) ?></option><?php endforeach ?>
            </select>
            <span class="hint">Seules les étapes possibles sont proposées. Pour la remise au client, utilisez le bloc « Remise au destinataire ».</span>
          </div>
          <div class="row row-2">
            <div class="field"><label for="location">Lieu</label><input id="location" name="location" placeholder="Ex. : Aéroport FHB, Abidjan"></div>
            <div class="field"><label for="occurred_at">Date et heure</label><input id="occurred_at" name="occurred_at" type="datetime-local"><span class="hint">Laissez vide pour maintenant.</span></div>
          </div>
          <div class="field"><label for="public_comment">Message visible par le client</label><input id="public_comment" name="public_comment" maxlength="255"></div>
          <div class="field"><label for="internal_comment">Note pour l’équipe</label><input id="internal_comment" name="internal_comment" maxlength="500"></div>
          <button class="btn btn-primary">Enregistrer</button>
        </form>
      </details>
    </section>
    <?php endif ?>

    <section class="panel">
      <h2 style="margin-bottom:14px">Historique</h2>
      <ol class="timeline">
        <?php foreach ($events as $ev): ?>
          <li class="<?= isset(Domain::PARCEL_EXCEPTIONS[$ev['status']]) ? 'alert' : '' ?>">
            <div class="t"><?= e(Domain::parcelLabel($ev['status'])) ?></div>
            <div class="m"><?= e(dt($ev['occurred_at'], 'd/m/Y H:i')) ?><?= $ev['location'] ? ', ' . e($ev['location']) : '' ?><?= $ev['user_name'] ? ', par ' . e($ev['user_name']) : '' ?></div>
            <?php if ($ev['public_comment']): ?><div class="c"><?= e($ev['public_comment']) ?></div><?php endif ?>
            <?php if ($ev['internal_comment'] && can('parcels.internal')): ?><div class="i">Note interne : <?= e($ev['internal_comment']) ?></div><?php endif ?>
          </li>
        <?php endforeach ?>
      </ol>
    </section>
  </div>

  <aside class="stack">
    <?php if ($loadable): ?>
    <section class="panel load-panel" aria-labelledby="load-title">
      <div class="panel-head"><h2 id="load-title">Mettre dans un vol</h2></div>
      <?php if ($openShipments): ?>
        <p class="muted small" style="margin-top:-6px">Vols en préparation vers <?= e(country_name($p['destination_country'])) ?> : un appui suffit.</p>
        <div class="load-list">
        <?php foreach ($openShipments as $os): ?>
          <form method="post" action="<?= e(url('dossiers/' . $os['id'] . '/colis')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="parcel_ids[]" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="retour" value="colis">
            <button class="load-choice">
              <span class="code"><?= e($os['reference']) ?></span>
              <span class="small muted"><?= e(trim(($os['airline'] ?? '') . ' ' . ($os['flight_number'] ?? '')) ?: 'Vol à préciser') ?> · <?= $os['planned_departure'] ? 'départ ' . e(dt($os['planned_departure'], 'd/m H:i')) : 'date à préciser' ?> · <?= pluralize((int) $os['parcel_count'], 'colis', 'colis') ?></span>
              <span class="go"><?= icon('plane') ?>Mettre dans ce vol</span>
            </button>
          </form>
        <?php endforeach ?>
        </div>
      <?php else: ?>
        <p class="muted small" style="margin-top:-6px">Aucun vol en préparation vers <?= e(country_name($p['destination_country'])) ?>. Créez le prochain vol : la destination est déjà remplie.</p>
        <a class="btn btn-go btn-block" href="<?= e(url('dossiers/nouveau', ['destination' => $p['destination_country']])) ?>"><?= icon('plus') ?>Créer un vol</a>
      <?php endif ?>
    </section>
    <?php endif ?>
    <section class="panel">
      <dl class="dl">
        <dt>Expéditeur</dt><dd><?php if (can('clients.view')): ?><a href="<?= e(url('clients/' . $p['sender_id'])) ?>"><?= e($p['sender_name']) ?></a><?php else: ?><?= e($p['sender_name']) ?><?php endif ?></dd>
        <dt>Destinataire</dt><dd><?php if (can('clients.view')): ?><a href="<?= e(url('clients/' . $p['recipient_id'])) ?>"><?= e($p['recipient_name']) ?></a><?php else: ?><?= e($p['recipient_name']) ?><?php endif ?>
          <?php if (can('clients.contacts') || App\Core\Auth::role() === 'courier'): ?><br><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) ($p['delivery_contact_phone'] ?: $p['recipient_phone']))) ?>"><?= e($p['delivery_contact_phone'] ?: $p['recipient_phone']) ?></a><?php endif ?></dd>
        <?php if ($p['delivery_contact_name']): ?><dt>Contact à l’arrivée</dt><dd><?= e($p['delivery_contact_name']) ?></dd><?php endif ?>
        <dt>Poids pesé</dt><dd class="num"><?= e(kg((int) $p['weight_g'])) ?>, <?= (int) $p['pieces'] ?> pièce<?= $p['pieces'] > 1 ? 's' : '' ?></dd>
        <?php if (can('parcels.finance') || can('invoices.view')): ?>
        <dt>Poids compté</dt><dd class="num"><?= e(kg((int) $p['billable_weight_g'])) ?></dd>
        <dt>Prix au kilo</dt><dd class="num"><?= e(money((int) $p['rate_per_kg_minor'], $p['currency'])) ?> / kg</dd>
        <dt>Prix du transport</dt><dd class="num"><strong><?= e(money((int) $p['amount_minor'], $p['currency'])) ?></strong></dd>
        <?php endif ?>
        <dt>Paiement</dt><dd><?= e(Domain::PAYMENT_TERMS[$p['payment_terms'] ?? ''] ?? 'Comme d’habitude pour ce client') ?></dd>
        <dt>Reçu le</dt><dd><?= e(dt($p['received_at'])) ?></dd>
        <dt>Vol</dt><dd><?php if ($p['shipment_id']): ?><a href="<?= e(url('dossiers/' . $p['shipment_id'])) ?>"><?= e($p['shipment_reference']) ?></a><?= $p['flight_number'] ? ', vol ' . e($p['flight_number']) : '' ?><?php else: ?><span class="muted">Pas encore affecté à un vol</span><?php endif ?></dd>
        <dt>Page de suivi</dt><dd><?= $p['public_weight'] ? 'Le client voit le poids' : 'Poids caché au client' ?></dd>
      </dl>
      <?php if ($p['internal_notes'] && can('parcels.internal')): ?><hr><p class="small"><strong>Note pour l’équipe :</strong> <?= nl2br(e($p['internal_notes'])) ?></p><?php endif ?>
    </section>

    <?php if (can('invoices.view')): ?>
    <section class="panel">
      <h2 style="margin-bottom:8px">Facture</h2>
      <?php if ($invoice): ?>
        <p style="margin:0">Sur la facture <a href="<?= e(url('factures/' . $invoice['id'])) ?>"><?= e($invoice['number'] ?? 'brouillon #' . $invoice['id']) ?></a> <?= status_badge($invoice['status'], 'invoice') ?></p>
      <?php else: ?>
        <p class="muted" style="margin:0 0 10px">Ce colis n’est sur aucune facture.</p>
        <?php if (can('invoices.edit')): ?>
          <div class="actions">
            <a class="btn btn-sm" href="<?= e(url('factures/nouvelle', ['client' => $p['sender_id']])) ?>">Facturer l’expéditeur</a>
            <a class="btn btn-sm" href="<?= e(url('factures/nouvelle', ['client' => $p['recipient_id']])) ?>">Facturer le destinataire</a>
          </div>
        <?php endif ?>
      <?php endif ?>
    </section>
    <?php endif ?>

    <section class="panel" id="remise">
      <h2 style="margin-bottom:8px">Remise au destinataire</h2>
      <?php if ($openDelivery): ?>
        <p style="margin:0 0 10px"><?= e(Domain::DELIVERY_MODES[$openDelivery['mode']]) ?> <?= status_badge($openDelivery['status'], 'delivery') ?></p>
        <a class="btn btn-primary btn-block" href="<?= e(url('livraisons/' . $openDelivery['id'])) ?>">Ouvrir la remise</a>
      <?php elseif (can('deliveries.manage') && in_array($p['status'], ['arrived', 'at_depot', 'delivery_failed'], true)): ?>
        <?php if (can('deliveries.confirm')): ?>
        <p class="small muted" style="margin:0 0 10px">Le destinataire est au comptoir ? Remettez le colis en une seule étape.</p>
        <form method="post" enctype="multipart/form-data" action="<?= e(url('colis/' . $p['id'] . '/remise')) ?>" class="handover" data-once>
          <?= csrf_field() ?>
          <div class="field"><label for="ho_name">Nom de la personne qui reçoit</label><input id="ho_name" name="recipient_name" required value="<?= old('recipient_name', $p['delivery_contact_name'] ?: $p['recipient_name']) ?>"<?= invalid('recipient_name') ?>><?= field_error('recipient_name') ?></div>
          <div class="field"><label for="ho_id">Pièce d’identité vue <span class="muted">(facultatif : type et 4 derniers chiffres)</span></label><input id="ho_id" name="recipient_id_note" maxlength="120" placeholder="CNI …1234"></div>
          <label class="check"><input type="checkbox" name="confirm" value="1" required<?= invalid('confirm') ?>> J’ai bien donné le colis <?= e($p['tracking_code']) ?> à cette personne.</label>
          <?= field_error('confirm') ?>
          <button class="btn btn-go btn-block"><?= icon('check') ?>Remettre le colis maintenant</button>
        </form>
        <details class="inline-form" style="margin-top:12px"><summary class="btn btn-block">Prévoir une livraison à domicile…</summary>
        <?php endif ?>
        <form method="post" action="<?= e(url('colis/' . $p['id'] . '/livraison')) ?>">
          <?= csrf_field() ?>
          <div class="field"><label for="mode">Comment</label><select id="mode" name="mode"><?php foreach (Domain::DELIVERY_MODES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="agent_id">Livreur</label><select id="agent_id" name="agent_id"<?= invalid('agent_id') ?>><option value="">—</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach ?></select><?= field_error('agent_id') ?></div>
          <div class="field"><label for="planned_date">Date prévue</label><input id="planned_date" name="planned_date" type="date"></div>
          <div class="field"><label for="address">Adresse de livraison</label><input id="address" name="address" value="<?= e($p['recipient_address'] ?? '') ?>"></div>
          <button class="btn btn-primary btn-block">Prévoir</button>
        </form>
        <?php if (can('deliveries.confirm')): ?></details><?php endif ?>
      <?php elseif ($p['status'] === 'delivered'): ?>
        <p class="muted" style="margin:0">Colis remis.</p>
      <?php else: ?>
        <p class="muted" style="margin:0">Vous pourrez remettre le colis quand il sera arrivé.</p>
      <?php endif ?>
      <?php foreach ($deliveries as $d): if ($openDelivery && $d['id'] === $openDelivery['id']) continue; ?>
        <div class="kv-line"><a href="<?= e(url('livraisons/' . $d['id'])) ?>">Tentative <?= (int) $d['attempt_no'] ?></a><?= status_badge($d['status'], 'delivery') ?></div>
      <?php endforeach ?>
    </section>

    <?php if (can('parcels.internal')): ?>
    <section class="panel">
      <h2 style="margin-bottom:8px">Photos et documents</h2>
      <?php foreach ($attachments as $a): ?>
        <div class="kv-line"><a href="<?= e(url('fichiers/colis/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= e($a['original_name']) ?></a><span class="small muted"><?= e(dt($a['created_at'], 'd/m/Y')) ?></span></div>
      <?php endforeach ?>
      <?php if (!$attachments): ?><p class="muted small">Aucune photo ni document.</p><?php endif ?>
      <?php if (can('parcels.edit')): ?>
      <form method="post" enctype="multipart/form-data" action="<?= e(url('colis/' . $p['id'] . '/pieces')) ?>" style="margin-top:10px">
        <?= csrf_field() ?>
        <div class="field"><label for="attachment">Ajouter une photo ou un PDF</label><input id="attachment" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" required></div>
        <button class="btn btn-sm">Ajouter</button>
      </form>
      <?php endif ?>
    </section>
    <?php endif ?>
  </aside>
</div>
