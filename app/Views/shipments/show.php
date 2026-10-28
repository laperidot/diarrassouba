<?php
use App\Core\Domain;
$s = $shipment;
$from = Domain::iata($s['origin_city'], $s['origin_country']);
$to = Domain::iata($s['destination_city'], $s['destination_country']);
$toParcel = Domain::SHIPMENT_TO_PARCEL;
?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url('dossiers')) ?>">← Dossiers</a><h1 class="code" style="font-size:1.9rem"><?= e($s['reference']) ?></h1></div>
  <div class="actions">
    <a class="btn" href="<?= e(url('dossiers/' . $s['id'] . '/manifeste')) ?>" target="_blank" rel="noopener"><?= icon('print') ?>Manifeste</a>
    <?php if (can('shipments.edit') && !in_array($s['status'], ['closed', 'cancelled'], true)): ?><a class="btn" href="<?= e(url('dossiers/' . $s['id'] . '/modifier')) ?>"><?= icon('edit') ?>Modifier</a><?php endif ?>
  </div>
</div>
<div class="grid grid-main">
  <div class="stack">
    <section class="hero-card">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div><div class="small muted">Vol</div><div class="code" style="font-size:1.1rem"><?= e(trim(($s['airline'] ?? '') . ' ' . ($s['flight_number'] ?? '')) ?: 'Non renseigné') ?></div></div>
        <?= status_badge($s['status'], 'shipment') ?>
      </div>
      <div class="route" style="margin-top:16px"><span class="iata"><?= e($from) ?></span><span class="leg"><?= icon('plane') ?></span><span class="iata"><?= e($to) ?></span></div>
      <div class="route-cities"><span><?= e($s['actual_departure'] ? 'Parti le ' . dt($s['actual_departure'], 'd/m H:i') : 'Départ prévu ' . dt($s['planned_departure'], 'd/m H:i')) ?></span><span><?= e($s['actual_arrival'] ? 'Arrivé le ' . dt($s['actual_arrival'], 'd/m H:i') : 'Arrivée prévue ' . dt($s['planned_arrival'], 'd/m H:i')) ?></span></div>
    </section>

    <?php
    $steps = ['preparation' => 'Préparation', 'departed' => 'Parti', 'in_transit' => 'En vol', 'arrived' => 'Arrivé', 'closed' => 'Clôturé'];
    $stepKeys = array_keys($steps);
    $cur = array_search($s['status'], $stepKeys, true);
    $loadable = can('shipments.load') && $s['status'] === 'preparation';   // ajouter des colis (comptoir compris)
    $editable = can('shipments.edit') && $s['status'] === 'preparation';   // retirer des colis
    $nextHelp = [
        'preparation' => 'Quand l’avion a décollé, confirmez le départ : les colis passent « Expédié » et les clients le voient sur le suivi.',
        'departed' => 'Mettez à jour quand le vol est en transit ou dès son arrivée.',
        'in_transit' => 'Confirmez l’arrivée à destination : les colis passent « Arrivé ».',
        'arrived' => 'Clôturez le dossier quand tous les colis sont remis.',
    ][$s['status']] ?? '';
    ?>
    <?php if ($s['status'] !== 'cancelled'): ?>
    <ol class="steps" aria-label="Étapes du dossier">
      <?php foreach ($steps as $k => $label): $i = array_search($k, $stepKeys, true); ?>
        <li class="<?= $i < $cur ? 'done' : ($i === $cur ? 'now' : '') ?>"<?= $i === $cur ? ' aria-current="step"' : '' ?>><span class="dot"></span><?= e($label) ?></li>
      <?php endforeach ?>
    </ol>
    <?php endif ?>

    <?php if (can('shipments.edit') && $transitions): ?>
    <section class="panel next-step" aria-labelledby="next-title">
      <div class="panel-head"><h2 id="next-title">Étape suivante</h2><?= status_badge($s['status'], 'shipment') ?></div>
      <?php if ($nextHelp): ?><p class="muted small" style="margin-top:-6px"><?= e($nextHelp) ?></p><?php endif ?>
      <?php if ($s['status'] === 'preparation' && !$parcels): ?><p class="small" style="color:var(--amber-ink)">Le dossier est vide : chargez d’abord les colis ci-dessous.</p><?php endif ?>
      <?= App\Core\View::partial('partials/shipment-status', ['sh' => $s, 'transitions' => $transitions]) ?>
    </section>
    <?php endif ?>

    <?php if ($loadable): ?>
    <section class="panel load-zone" id="charger" aria-labelledby="load-title">
      <div class="panel-head"><h2 id="load-title">Charger le vol</h2><span class="muted small"><?= pluralize(count($parcels), 'colis', 'colis') ?> · <?= e(kg((int) $s['total_weight_g'])) ?></span></div>
      <form class="scan-add" method="post" action="<?= e(url('dossiers/' . $s['id'] . '/colis')) ?>">
        <?= csrf_field() ?>
        <label for="scan-code"><?= icon('scan') ?><span>Scannez l’étiquette ou tapez le code, puis Entrée</span></label>
        <div class="scan-add-row">
          <input id="scan-code" name="code" class="code" placeholder="DI-XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" required<?= $scanFocus ? ' autofocus' : '' ?>>
          <button class="btn btn-go"><?= icon('plus') ?>Ajouter</button>
        </div>
      </form>

      <?php if ($candidates): ?>
      <form method="post" action="<?= e(url('dossiers/' . $s['id'] . '/colis')) ?>" data-select-all-scope data-pick-list>
        <?= csrf_field() ?>
        <div class="pick-head">
          <p class="label">Ou cochez parmi les colis en attente pour <?= e(country_name($s['destination_country'])) ?> (<?= count($candidates) ?>)</p>
          <?php if (count($candidates) > 6): ?><input type="search" data-pick-filter placeholder="Filtrer : code, client…" aria-label="Filtrer les colis en attente"><?php endif ?>
          <label class="check"><input type="checkbox" data-select-all> <strong>Tout sélectionner</strong></label>
        </div>
        <div class="pick-items">
        <?php foreach ($candidates as $c): ?>
          <label class="check pick" data-weight="<?= (int) $c['weight_g'] ?>"><input type="checkbox" name="parcel_ids[]" value="<?= (int) $c['id'] ?>"><span><span class="code"><?= e($c['tracking_code']) ?></span> <span class="num"><?= e(kg((int) $c['weight_g'])) ?></span><br><span class="small muted"><?= e($c['sender_name']) ?> → <?= e($c['recipient_name']) ?>, reçu <?= e(dt($c['received_at'], 'd/m')) ?></span></span></label>
        <?php endforeach ?>
        </div>
        <div class="pick-foot">
          <span class="small" data-pick-count aria-live="polite">Aucun colis sélectionné</span>
          <button class="btn btn-primary">Ajouter au dossier</button>
        </div>
      </form>
      <?php else: ?>
        <p class="muted small" style="margin:14px 0 0">Aucun autre colis en attente pour <?= e(country_name($s['destination_country'])) ?>. Les colis apparaissent ici dès leur enregistrement au comptoir.<?php if (can('parcels.create')): ?> <a href="<?= e(url('colis/nouveau')) ?>">Enregistrer un colis</a><?php endif ?></p>
      <?php endif ?>
    </section>
    <?php elseif (can('shipments.edit') && in_array($s['status'], ['departed', 'in_transit', 'arrived'], true)): ?>
      <p class="notice"><?= icon('alert') ?><span>Le vol est parti : le chargement est verrouillé.<?= in_array('preparation', $transitions, true) ? ' Pour corriger un oubli, repassez le dossier « En préparation » dans l’étape suivante.' : '' ?></span></p>
    <?php endif ?>

    <section class="panel flush">
      <div class="panel-head"><h2>Colis du dossier</h2><span class="muted small"><?= count($parcels) ?> colis, <?= e(kg((int) $s['total_weight_g'])) ?></span></div>
      <?php if (!$parcels): ?>
        <div class="empty"><p><?= $loadable ? 'Aucun colis pour l’instant : scannez-les ou cochez-les dans « Charger le vol » ci-dessus.' : 'Aucun colis dans ce dossier.' ?></p></div>
      <?php else: ?>
      <table class="table stacked">
        <thead><tr><th>Code</th><th>Destinataire</th><th class="num">Poids</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($parcels as $p): ?>
          <tr>
            <td class="primary" data-label="Code"><a class="row-link code" href="<?= e(url('colis/' . $p['id'])) ?>"><?= e($p['tracking_code']) ?></a></td>
            <td data-label="Destinataire"><?= e($p['recipient_name']) ?></td>
            <td data-label="Poids" class="num"><?= e(kg((int) $p['weight_g'])) ?></td>
            <td data-label="Statut"><?= status_badge($p['status']) ?></td>
            <td class="num">
              <?php if ($editable): ?>
              <form method="post" action="<?= e(url('dossiers/' . $s['id'] . '/colis/' . $p['id'] . '/retirer')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-quiet" data-confirm="Retirer ce colis du dossier ?">Retirer</button></form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </section>

  </div>

  <aside class="stack">
    <section class="panel">
      <dl class="dl">
        <dt>LTA / AWB</dt><dd class="code"><?= e($s['awb_number'] ?: '—') ?></dd>
        <dt>Réf. transporteur</dt><dd><?= e($s['carrier_reference'] ?: '—') ?></dd>
        <dt>Départ prévu</dt><dd><?= e(dt($s['planned_departure'])) ?></dd>
        <dt>Départ réel</dt><dd><?= e(dt($s['actual_departure'])) ?></dd>
        <dt>Arrivée prévue</dt><dd><?= e(dt($s['planned_arrival'])) ?></dd>
        <dt>Arrivée réelle</dt><dd><?= e(dt($s['actual_arrival'])) ?></dd>
        <?php if (can('dashboard.finance') && $s['freight_cost_minor'] !== null): ?><dt>Fret prévu (devis)</dt><dd class="num"><?= e(money((int) $s['freight_cost_minor'], $s['freight_currency'])) ?></dd><?php endif ?>
      </dl>
      <?php if ($s['notes']): ?><hr><p class="small"><?= nl2br(e($s['notes'])) ?></p><?php endif ?>
    </section>
    <?php if ($margin !== null): ?>
    <section class="panel">
      <div class="panel-head"><h2>Marge estimée</h2><?php if (can('expenses.edit')): ?><a href="<?= e(url('depenses/nouvelle', ['dossier' => $s['id']])) ?>">+ Dépense</a><?php endif ?></div>
      <?php if (!$margin['rows']): ?>
        <p class="muted small" style="margin:0">Pas encore de facture validée ni de dépense liée à ce dossier.</p>
      <?php else: foreach ($margin['rows'] as $cur => $m): ?>
        <div class="kv-line"><span>Facturé (<?= e($cur) ?>)</span><span class="num"><?= e(money($m['billed'], $cur)) ?></span></div>
        <div class="kv-line"><span>Dépenses liées</span><span class="num">− <?= e(money($m['expenses'], $cur)) ?></span></div>
        <div class="kv-line"><strong>Marge estimée</strong><strong class="num" style="color:<?= $m['margin'] < 0 ? 'var(--coral-ink)' : 'var(--teal-ink)' ?>"><?= e(money($m['margin'], $cur)) ?></strong></div>
      <?php endforeach ?>
      <?php endif ?>
      <p class="small muted" style="margin:10px 0 0">Estimation de gestion, pas un résultat comptable : frais généraux et conversions de devises exclus.<?= $margin['unbilled'] ? ' ' . pluralize($margin['unbilled'], 'colis non facturé', 'colis non facturés') . '.' : '' ?></p>
      <a class="btn btn-sm" style="margin-top:10px" href="<?= e(url('depenses', ['dossier' => $s['id']])) ?>">Voir les dépenses du dossier</a>
    </section>
    <?php endif ?>
  </aside>
</div>
