<?php
use App\Core\Domain;
use App\Services\DashboardService;

$flow = array_keys(Domain::PARCEL_FLOW);
$exceptions = array_sum(array_intersect_key($counts, Domain::PARCEL_EXCEPTIONS));
$inProgress = array_sum(array_intersect_key($counts, array_flip(array_slice($flow, 0, 7))));
$maxWeek = max(1, ...array_column($weekly, 'parcels'));
$today = class_exists('IntlDateFormatter')
    ? (new IntlDateFormatter('fr_FR', IntlDateFormatter::FULL, IntlDateFormatter::NONE, local_tz(), null, 'EEEE d MMMM'))->format(time())
    : date('d/m/Y');
?>
<div class="board-head">
  <div style="margin-right:auto">
    <p class="eyebrow"><?= e(ucfirst((string) $today)) ?></p>
    <h1>Tableau de bord</h1>
  </div>
  <nav class="chips" aria-label="Période">
    <?php foreach (DashboardService::PERIODS as $k => $l): ?>
      <a class="chip" href="<?= e(url('tableau-de-bord', ['periode' => $k])) ?>"<?= $period === $k ? ' aria-current="true"' : '' ?>><?= e($l) ?></a>
    <?php endforeach ?>
  </nav>
  <details class="inline-form desk-only">
    <summary class="chip"<?= $period === 'custom' ? ' aria-current="true"' : '' ?>>Dates…</summary>
    <form class="panel" method="get" action="<?= e(url('tableau-de-bord')) ?>" style="position:absolute;right:32px;z-index:10;display:flex;gap:8px;align-items:flex-end">
      <input type="hidden" name="periode" value="custom">
      <div class="field" style="margin:0"><label for="du">Du</label><input type="date" id="du" name="du" value="<?= e($du) ?>" required></div>
      <div class="field" style="margin:0"><label for="au">Au</label><input type="date" id="au" name="au" value="<?= e($au) ?>" required></div>
      <button class="btn btn-primary">Afficher</button>
    </form>
  </details>
</div>

<nav class="quick" aria-label="Actions rapides">
  <?php if (can('parcels.create')): ?><a class="quick-go" href="<?= e(url('colis/nouveau')) ?>"><?= icon('plus') ?><span><strong>Enregistrer un colis</strong><small>Pesée et étiquette</small></span></a><?php endif ?>
  <?php if (can('parcels.view')): ?><a href="<?= e(url('scanner')) ?>"><?= icon('scan') ?><span><strong>Scanner</strong><small>Code-barres</small></span></a><?php endif ?>
  <?php if (can('shipments.load')): ?>
    <?php if ($loading): ?><a href="<?= e(url('dossiers/' . $loading['id']) . '#charger') ?>"><?= icon('plane') ?><span><strong>Charger le vol</strong><small><?= e($loading['reference']) ?> · <?= pluralize((int) $loading['parcel_count'], 'colis', 'colis') ?></small></span></a>
    <?php elseif (can('shipments.edit')): ?><a href="<?= e(url('dossiers/nouveau')) ?>"><?= icon('plane') ?><span><strong>Préparer un vol</strong><small>Nouveau dossier</small></span></a><?php endif ?>
  <?php endif ?>
  <?php if (can('payments.record')): ?><a href="<?= e(url('factures', ['status' => 'open'])) ?>"><?= icon('cash') ?><span><strong>Encaisser</strong><small>Factures à régler</small></span></a><?php endif ?>
  <?php if (can('deliveries.manage')): ?><a href="<?= e(url('livraisons')) ?>"><?= icon('truck') ?><span><strong>Remettre</strong><small>Retraits, livraisons</small></span></a><?php endif ?>
</nav>

<section class="panel flow-board" aria-labelledby="flow-title">
  <div class="panel-head">
    <h2 id="flow-title">Où sont les colis en ce moment</h2>
    <?php if (can('parcels.create')): ?><a class="btn btn-go btn-sm" href="<?= e(url('colis/nouveau')) ?>"><?= icon('plus') ?>Enregistrer un colis</a><?php endif ?>
  </div>
  <div class="rail board" role="list">
    <?php foreach ($flow as $i => $s): $n = $counts[$s]; ?>
      <a class="rail-stop<?= in_array($s, ['in_transit', 'arrived'], true) ? ' leg-air' : '' ?><?= $n > 0 && $s !== 'delivered' ? ' passed' : '' ?>" role="listitem" href="<?= e(url('colis', ['statut' => $s])) ?>">
        <span class="rail-dot"></span>
        <span class="rail-count<?= $n === 0 ? ' zero' : '' ?>"><?= $n ?></span>
        <span class="rail-label"><?= e(Domain::PARCEL_SHORT[$s]) ?></span>
      </a>
    <?php endforeach ?>
  </div>
  <div class="flow-foot">
    <span><strong><?= $inProgress ?></strong> colis en cours</span>
    <?php if ($exceptions): ?>
      <a class="exception-link" href="<?= e(url('colis', ['statut' => 'exceptions'])) ?>"><?= pluralize($exceptions, 'incident à traiter', 'incidents à traiter') ?></a>
    <?php else: ?>
      <span class="muted">Aucun incident ouvert</span>
    <?php endif ?>
    <span class="muted">Tronçon en pointillés : en vol</span>
  </div>
</section>

<h2 class="sr-only">Activité sur la période</h2>
<div class="figures" style="margin-top:16px">
  <div class="figure"><div class="label">Colis enregistrés</div><div class="value"><?= $ops['parcels'] ?></div><div class="hint"><?= e($periodLabel) ?></div></div>
  <div class="figure"><div class="label">Poids enregistré</div><div class="value"><?= e(kg($ops['registered_weight_g'])) ?></div><div class="hint">au comptoir</div></div>
  <div class="figure"><div class="label">Vols partis</div><div class="value"><?= $ops['flights'] ?></div><div class="hint"><?= pluralize($ops['shipments'], 'dossier ouvert', 'dossiers ouverts') ?></div></div>
  <div class="figure"><div class="label">Poids transporté</div><div class="value"><?= e(kg($ops['flown_weight_g'])) ?></div><div class="hint">sur les vols partis</div></div>
</div>

<div class="grid grid-main" style="margin-top:16px">
  <div class="stack">
    <?php if (can('shipments.view')): ?>
    <section class="panel" aria-labelledby="flights-title">
      <div class="panel-head"><h2 id="flights-title">Vols en cours</h2><a href="<?= e(url('dossiers')) ?>">Tous les dossiers</a></div>
      <?php if (!$flights): ?>
        <p class="muted" style="margin:0">Aucun vol en cours.<?php if (can('shipments.edit')): ?> <a href="<?= e(url('dossiers/nouveau')) ?>">Préparer un vol</a><?php endif ?></p>
      <?php else: ?>
        <ul class="flights">
          <?php foreach ($flights as $fl): $tr = Domain::SHIPMENT_TRANSITIONS[$fl['status']] ?? []; ?>
            <li>
              <div class="flight-head">
                <a class="code" href="<?= e(url('dossiers/' . $fl['id'])) ?>"><?= e($fl['reference']) ?></a>
                <span class="flight-route code"><?= e(Domain::iata($fl['origin_city'], $fl['origin_country'])) ?> → <?= e(Domain::iata($fl['destination_city'], $fl['destination_country'])) ?></span>
                <?= status_badge($fl['status'], 'shipment') ?>
              </div>
              <p class="flight-meta"><?= e(trim(($fl['airline'] ?? '') . ' ' . ($fl['flight_number'] ?? '')) ?: 'Vol à préciser') ?> · <?= pluralize((int) $fl['parcel_count'], 'colis', 'colis') ?>, <?= e(kg((int) $fl['total_weight_g'])) ?><?= $fl['planned_departure'] ? ' · départ ' . e(dt($fl['planned_departure'], 'd/m H:i')) : '' ?></p>
              <?php if (can('shipments.edit') && $tr): ?>
                <?= App\Core\View::partial('partials/shipment-status', ['sh' => $fl, 'transitions' => $tr, 'suffix' => '-' . $fl['id'], 'retour' => 'tableau-de-bord']) ?>
              <?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>
    <?php endif ?>

    <section class="panel" aria-labelledby="att-title">
      <div class="panel-head"><h2 id="att-title">À traiter</h2><span class="muted small"><?= count($attention) ?></span></div>
      <?php if (!$attention): ?>
        <p class="muted" style="margin:0">Rien d’urgent : aucun incident, aucun retard de vol, aucune facture échue.</p>
      <?php else: ?>
        <ul class="attention">
          <?php foreach ($attention as $a): ?>
            <li><a href="<?= e($a['url']) ?>"><span class="bar tone-<?= e($a['tone']) ?>"></span><span class="t"><?= e($a['title']) ?></span><span class="d"><?= e($a['detail']) ?></span></a></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>

    <section class="panel" aria-labelledby="week-title">
      <div class="panel-head"><h2 id="week-title">Colis enregistrés par semaine</h2><span class="muted small">10 semaines</span></div>
      <?php if (array_sum(array_column($weekly, 'parcels')) === 0): ?>
        <p class="muted" style="margin:0">Aucun colis enregistré sur les 10 dernières semaines.</p>
      <?php else: ?>
        <div class="bars" role="img" aria-label="Histogramme des colis enregistrés par semaine">
          <?php foreach ($weekly as $i => $w): ?>
            <div class="b<?= $i === count($weekly) - 1 ? ' now' : '' ?>" style="height:<?= round(100 * $w['parcels'] / $maxWeek) ?>%" title="Semaine du <?= e($w['label']) ?> : <?= $w['parcels'] ?> colis, <?= e(kg($w['weight_g'])) ?>"><?php if ($w['parcels']): ?><span><?= $w['parcels'] ?></span><?php endif ?></div>
          <?php endforeach ?>
        </div>
        <div class="bars-x" aria-hidden="true"><?php foreach ($weekly as $w): ?><span><?= e($w['label']) ?></span><?php endforeach ?></div>
      <?php endif ?>
    </section>

    <section class="panel" aria-labelledby="act-title">
      <div class="panel-head"><h2 id="act-title">Derniers mouvements</h2></div>
      <?php if (!$activity): ?>
        <p class="muted" style="margin:0">Les changements de statut des colis apparaîtront ici.</p>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($activity as $a): ?>
            <li><?= status_badge($a['status']) ?><a class="code" href="<?= e(url('colis/' . $a['parcel_id'])) ?>"><?= e($a['tracking_code']) ?></a><span class="when"><?= e(ago($a['occurred_at'])) ?></span></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>
  </div>

  <div class="stack">
    <?php if ($finance !== null): ?>
    <section class="panel" aria-labelledby="money-title">
      <div class="panel-head"><h2 id="money-title">Argent</h2><span class="muted small"><?= e($periodLabel) ?></span></div>
      <?php if (!$finance): ?>
        <p class="muted" style="margin:0">Aucune facture validée, aucun paiement ni dépense sur la période.</p>
      <?php else: foreach ($finance as $cur => $f): ?>
        <table class="ledger" aria-label="Synthèse financière en <?= e($cur) ?>">
          <thead><tr><th scope="col"><?= e(Domain::CURRENCIES[$cur]['label'] ?? $cur) ?></th><th scope="col">Montant</th></tr></thead>
          <tbody>
            <tr><th scope="row">Facturé</th><td><?= e(money($f['billed'], $cur)) ?></td></tr>
            <tr><th scope="row">Encaissé</th><td class="pos"><?= e(money($f['collected'], $cur)) ?></td></tr>
            <tr><th scope="row">Reste à encaisser <span class="muted small">(toutes périodes)</span></th><td class="<?= $f['receivable'] > 0 ? 'neg' : '' ?>"><?= e(money($f['receivable'], $cur)) ?></td></tr>
            <tr><th scope="row">Dépenses</th><td><?= e(money($f['expenses'], $cur)) ?></td></tr>
          </tbody>
        </table>
      <?php endforeach ?>
        <p class="small muted" style="margin:10px 0 0">Facturé n’est pas encaissé, et aucune de ces lignes n’est un bénéfice : les montants sont présentés par devise, sans conversion.</p>
      <?php endif ?>
    </section>
    <?php endif ?>

    <section class="panel" aria-labelledby="del-title">
      <div class="panel-head"><h2 id="del-title">Remises</h2><a href="<?= e(url('livraisons')) ?>">Voir</a></div>
      <?php if ($delivery['done'] + $delivery['failed'] === 0): ?>
        <p class="muted" style="margin:0">Aucune remise clôturée sur la période.</p>
      <?php else: ?>
        <div class="kv-line"><span>Remises confirmées</span><strong class="num"><?= $delivery['done'] ?></strong></div>
        <div class="kv-line"><span>Tentatives échouées</span><strong class="num"><?= $delivery['failed'] ?></strong></div>
        <div class="kv-line"><span>Livrées au premier passage</span><strong class="num"><?= $delivery['first_attempt_rate'] === null ? '—' : $delivery['first_attempt_rate'] . ' %' ?></strong></div>
        <div class="kv-line"><span>Délai moyen, comptoir à remise</span><strong class="num"><?= $delivery['avg_days'] === null ? '—' : e(str_replace('.', ',', (string) $delivery['avg_days'])) . ' j' ?></strong></div>
      <?php endif ?>
    </section>

    <?php if ($finance !== null): ?>
    <section class="panel" aria-labelledby="pay-title">
      <div class="panel-head"><h2 id="pay-title">Derniers paiements</h2><a href="<?= e(url('paiements')) ?>">Tous</a></div>
      <?php if (!$payments): ?>
        <p class="muted" style="margin:0">Aucun paiement enregistré pour l’instant.</p>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($payments as $p): ?>
            <li><a href="<?= e(url('factures/' . $p['invoice_id'])) ?>"><?= e($p['client_name']) ?></a><span class="when num <?= $p['amount_minor'] < 0 ? 'neg' : '' ?>"><?= e(money((int) $p['amount_minor'], $p['currency'])) ?></span></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>
    <?php endif ?>
  </div>
</div>
