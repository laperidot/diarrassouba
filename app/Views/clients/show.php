<?php use App\Core\Domain; $c = $client; ?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url('clients')) ?>">← Clients</a><h1><?= e($c['name']) ?></h1></div>
  <div class="actions">
    <?php if (can('parcels.create') && !$c['archived_at']): ?><a class="btn btn-go" href="<?= e(url('colis/nouveau', ['expediteur' => $c['id']])) ?>"><?= icon('plus') ?>Nouveau colis</a><?php endif ?>
    <?php if (can('invoices.edit')): ?><a class="btn" href="<?= e(url('factures/nouvelle', ['client' => $c['id']])) ?>"><?= icon('invoice') ?>Facturer</a><?php endif ?>
    <?php if (can('clients.edit')): ?><a class="btn" href="<?= e(url('clients/' . $c['id'] . '/modifier')) ?>"><?= icon('edit') ?>Modifier</a><?php endif ?>
  </div>
</div>
<?php if ($c['archived_at']): ?><div class="flash flash-warning"><?= icon('alert') ?><span>Client archivé le <?= e(dt($c['archived_at'], 'd/m/Y')) ?> : il n’apparaît plus dans les recherches.</span></div><?php endif ?>
<?php if ($duplicates): ?>
  <div class="flash flash-warning"><?= icon('alert') ?><div>Doublon possible :
    <?php foreach ($duplicates as $i => $d): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('clients/' . $d['id'])) ?>"><?= e($d['name']) ?> (<?= e($d['code']) ?>)</a><?php endforeach ?>. Même nom ou même téléphone.</div></div>
<?php endif ?>
<div class="grid grid-main">
  <div class="stack">
    <section class="panel flush">
      <div class="panel-head"><h2>Colis</h2><span class="muted small"><?= count($parcels) ?></span></div>
      <?php if (!$parcels): ?>
        <div class="empty"><p>Aucun colis pour ce client.</p></div>
      <?php else: ?>
      <table class="table stacked">
        <thead><tr><th>Code</th><th>Rôle</th><th>Contenu</th><th>Statut</th><th>Reçu le</th></tr></thead>
        <tbody>
        <?php foreach ($parcels as $p): ?>
          <tr>
            <td class="primary" data-label="Code"><a class="row-link code" href="<?= e(url('colis/' . $p['id'])) ?>"><?= e($p['tracking_code']) ?></a></td>
            <td data-label="Rôle"><?= $p['role_in_parcel'] === 'sender' ? 'Expéditeur' : 'Destinataire' ?></td>
            <td data-label="Contenu"><?= e($p['description']) ?></td>
            <td data-label="Statut" class="end"><?= status_badge($p['status']) ?></td>
            <td data-label="Reçu le" class="hide-sm"><?= e(dt($p['received_at'], 'd/m/Y')) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </section>
    <?php if (can('invoices.view')): ?>
    <section class="panel flush">
      <div class="panel-head"><h2>Factures</h2></div>
      <?php if (!$invoices): ?>
        <div class="empty"><p>Aucune facture.</p></div>
      <?php else: ?>
      <table class="table stacked">
        <thead><tr><th>Numéro</th><th>Date</th><th class="num">Total</th><th class="num">Reste</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $i): ?>
          <tr>
            <td class="primary" data-label="Numéro"><a class="row-link" href="<?= e(url('factures/' . $i['id'])) ?>"><?= e($i['number'] ?? 'Brouillon #' . $i['id']) ?></a></td>
            <td data-label="Date"><?= e(d($i['issue_date'] ?? $i['created_at'])) ?></td>
            <td data-label="Total" class="num"><?= e(money((int) $i['total_minor'], $i['currency'])) ?></td>
            <td data-label="Reste" class="num"><?= in_array($i['status'], ['validated', 'partial'], true) ? e(money((int) $i['total_minor'] - (int) $i['paid_minor'], $i['currency'])) : '—' ?></td>
            <td data-label="Statut" class="end"><?= status_badge($i['status'], 'invoice') ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </section>
    <?php endif ?>
  </div>
  <aside class="stack">
    <section class="panel">
      <dl class="dl">
        <dt>Code client</dt><dd class="code"><?= e($c['code']) ?></dd>
        <dt>Type</dt><dd><?= $c['kind'] === 'company' ? 'Entreprise' : 'Particulier' ?></dd>
        <?php if (can('clients.contacts')): ?>
        <dt>Téléphone</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $c['phone'])) ?>"><?= e($c['phone']) ?></a><?= $c['phone_alt'] ? '<br>' . e($c['phone_alt']) : '' ?></dd>
        <dt>E-mail</dt><dd><?= e($c['email'] ?: '—') ?></dd>
        <dt>Adresse</dt><dd><?= e(trim(($c['address'] ?? '') . ' ' . ($c['city'] ?? '')) ?: '—') ?><?= $c['country'] ? ', ' . e(country_name($c['country'])) : '' ?></dd>
        <?php endif ?>
        <dt>Paiement habituel</dt><dd><?= e(Domain::PAYMENT_TERMS[$c['default_payment_terms'] ?? ''] ?? 'Non précisé') ?></dd>
        <dt>Client depuis</dt><dd><?= e(dt($c['created_at'], 'd/m/Y')) ?></dd>
      </dl>
      <?php if ($c['notes'] && can('clients.contacts')): ?><hr><p class="small"><?= nl2br(e($c['notes'])) ?></p><?php endif ?>
    </section>
    <?php if ($balances): ?>
    <section class="panel">
      <h2 style="margin-bottom:8px">Reste à payer</h2>
      <?php foreach ($balances as $b): if ((int) $b['due'] === 0) continue; ?>
        <div class="kv-line"><span><?= e($b['currency']) ?></span><strong class="num" style="color:var(--coral-ink)"><?= e(money((int) $b['due'], $b['currency'])) ?></strong></div>
      <?php endforeach ?>
    </section>
    <?php endif ?>
    <?php if (can('clients.edit')): ?>
    <form method="post" action="<?= e(url('clients/' . $c['id'] . '/archiver')) ?>"><?= csrf_field() ?>
      <?php if ($c['archived_at']): ?><input type="hidden" name="restore" value="1"><button class="btn btn-block">Réactiver le client</button>
      <?php else: ?><button class="btn btn-quiet btn-block" data-confirm="Archiver ce client ? Son historique est conservé.">Archiver le client</button><?php endif ?>
    </form>
    <?php endif ?>
  </aside>
</div>
