<?php use App\Core\Domain; ?>
<div class="page-head"><div><a class="crumb" href="<?= e(url('factures')) ?>">← Factures</a><h1>Nouvelle facture</h1></div></div>
<?php if (!$client): ?>
  <section class="panel form">
    <h2 style="margin-bottom:6px">Qui paie ?</h2>
    <p class="muted small">L’expéditeur ou le destinataire, selon la modalité convenue (paiement au départ ou à l’arrivée).</p>
    <div class="picker" data-picker="client" data-search="<?= e(url('api/clients')) ?>" data-navigate="<?= e(url('factures/nouvelle')) ?>?client=">
      <input type="hidden" name="client_id" value="">
      <div class="picker-chosen" hidden><span class="who"><strong data-who-name></strong><br><span class="small muted" data-who-meta></span></span></div>
      <div data-search-box>
        <div class="field"><label for="client-search">Client</label><input id="client-search" type="search" autocomplete="off" placeholder="Nom, téléphone ou code client" data-search-input autofocus><div class="picker-results" hidden role="listbox"></div></div>
      </div>
    </div>
    <noscript><p class="small">Ouvrez la fiche du client puis cliquez sur « Facturer ».</p></noscript>
  </section>
<?php else: ?>
  <form method="post" action="<?= e(url('factures')) ?>" class="form" data-select-all-scope>
    <?= csrf_field() ?>
    <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
    <section class="panel">
      <div class="panel-head"><h2>Client : <?= e($client['name']) ?></h2><a href="<?= e(url('factures/nouvelle')) ?>">Changer</a></div>
      <div class="row row-2">
        <div class="field"><label for="shipment-filter">Limiter à un dossier</label>
          <select id="shipment-filter" data-nav-select="<?= e(url('factures/nouvelle', ['client' => $client['id']])) ?>&amp;dossier=">
            <option value="">Tous les colis non facturés</option>
            <?php foreach ($shipments as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($shipmentId, $s['id']) ?>><?= e($s['reference'] . ' ' . ($s['flight_number'] ?? '')) ?></option><?php endforeach ?>
          </select>
          <span class="hint">Facture groupée par vol : choisissez le dossier.</span>
        </div>
      </div>
    </section>
    <section class="panel">
      <h2 style="margin-bottom:10px">Colis à facturer</h2>
      <?php if (!$parcels): ?>
        <p class="muted">Aucun colis non facturé pour ce client<?= $shipmentId ? ' dans ce dossier' : '' ?>. Un colis déjà présent sur une facture (même en brouillon) ne peut pas être facturé une seconde fois.</p>
      <?php else: ?>
        <label class="check"><input type="checkbox" data-select-all> <strong>Tout sélectionner</strong></label>
        <?php foreach ($parcels as $p): ?>
          <label class="check"><input type="checkbox" name="parcel_ids[]" value="<?= (int) $p['id'] ?>"<?= count($parcels) === 1 ? ' checked' : '' ?>>
            <span><span class="code"><?= e($p['tracking_code']) ?></span> <?= e($p['description']) ?><br>
            <span class="small muted num"><?= e(kg((int) $p['billable_weight_g'])) ?> × <?= e(money((int) $p['rate_per_kg_minor'], $p['currency'])) ?> = <strong><?= e(money((int) $p['amount_minor'], $p['currency'])) ?></strong>, <?= $p['client_role'] === 'sender' ? 'expédié' : 'reçu' ?> par ce client<?= $p['shipment_reference'] ? ', ' . e($p['shipment_reference']) : '' ?></span></span>
          </label>
        <?php endforeach ?>
        <?= field_error('parcel_ids') ?>
      <?php endif ?>
    </section>
    <?php if ($parcels): ?>
    <section class="panel">
      <div class="row row-2">
        <div class="field"><label for="payment_terms">Modalité de paiement</label><select id="payment_terms" name="payment_terms"><option value="">Selon la fiche client</option><?php foreach (Domain::PAYMENT_TERMS as $k => $l): ?><option value="<?= $k ?>"<?= selected($client['default_payment_terms'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="due_date">Échéance</label><input id="due_date" name="due_date" type="date"><span class="hint">Vide = <?= App\Core\Config::int('DEFAULT_DUE_DAYS', 15) ?> jours après validation.</span></div>
      </div>
      <div class="field"><label for="notes">Mention sur la facture</label><input id="notes" name="notes" maxlength="500"></div>
      <p class="small muted">Les colis doivent être dans la même devise. Le brouillon reste modifiable (frais, échéancier, taxe) jusqu’à sa validation.</p>
      <button class="btn btn-primary">Créer le brouillon</button>
    </section>
    <?php endif ?>
  </form>
<?php endif ?>
