<?php use App\Core\Domain; $today = (new DateTimeImmutable('now', local_tz()))->format('Y-m-d'); ?>
<div class="page-head">
  <h1>Factures</h1>
  <?php if (can('invoices.edit')): ?><a class="btn btn-primary" href="<?= e(url('factures/nouvelle')) ?>"><?= icon('plus') ?>Nouvelle facture</a><?php endif ?>
</div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Numéro, client" aria-label="Rechercher une facture">
  <select name="status" aria-label="Statut">
    <option value="">Toutes</option>
    <option value="open"<?= selected($f['status'], 'open') ?>>Pas encore payées</option>
    <option value="overdue"<?= selected($f['status'], 'overdue') ?>>En retard</option>
    <?php foreach (Domain::INVOICE_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <button class="btn">Filtrer</button>
</form>
<nav class="chips" aria-label="Filtres rapides" style="margin-bottom:14px">
  <?php foreach (['open' => 'Pas encore payées', 'overdue' => 'En retard', 'draft' => 'À terminer', '' => 'Toutes'] as $k => $l): ?>
    <a class="chip" href="<?= e(url('factures', array_filter(['status' => $k, 'q' => $f['q']]))) ?>"<?= (string) $f['status'] === $k ? ' aria-current="true"' : '' ?>><?= e($l) ?><?php if ($k !== '' && $counts[$k] > 0): ?> <span class="chip-count"><?= (int) $counts[$k] ?></span><?php endif ?></a>
  <?php endforeach ?>
</nav>
<?php if (!$invoices): ?>
  <div class="panel empty"><h3>Aucune facture<?= array_filter($f) ? ' pour ces critères' : '' ?></h3><p>Une facture se fait à partir des colis d’un client : poids × prix au kilo.</p><?php if (can('invoices.edit')): ?><a class="btn btn-primary" href="<?= e(url('factures/nouvelle')) ?>">Créer une facture</a><?php endif ?></div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Numéro</th><th>Client</th><th>Émise le</th><th>À payer avant le</th><th class="num">Total</th><th class="num">Reste à payer</th><th>Statut</th><th><span class="sr-only">Action</span></th></tr></thead>
  <tbody>
  <?php foreach ($invoices as $i): $due = in_array($i['status'], ['validated', 'partial'], true) ? (int) $i['total_minor'] - (int) $i['paid_minor'] : 0; $late = $due > 0 && $i['due_date'] && $i['due_date'] < $today; ?>
    <tr>
      <td class="primary" data-label="Numéro"><a class="row-link" href="<?= e(url('factures/' . $i['id'])) ?>"><?= e($i['number'] ?? 'À terminer #' . $i['id']) ?></a><?= $i['kind'] === 'credit_note' ? ' <span class="tag">annulation</span>' : '' ?></td>
      <td data-label="Client"><?= e($i['client_name']) ?></td>
      <td data-label="Émise le" class="hide-sm"><?= e(d($i['issue_date'])) ?></td>
      <td data-label="À payer avant le"<?= $late ? ' class="late"' : '' ?>><?= e(d($i['due_date'])) ?><?= $late ? ' <span class="tag tag-late">en retard</span>' : '' ?></td>
      <td data-label="Total" class="num"><?= e(money((int) $i['total_minor'], $i['currency'])) ?></td>
      <td data-label="Reste à payer" class="num"<?= $due > 0 ? ' style="color:var(--coral-ink);font-weight:600"' : '' ?>><?= $due > 0 ? e(money($due, $i['currency'])) : '—' ?></td>
      <td data-label="Statut" class="end"><?= status_badge($i['status'], 'invoice') ?></td>
      <?php if ($due > 0 && $i['kind'] === 'invoice' && can('payments.record')): ?>
      <td class="row-action"><a class="btn btn-sm btn-primary" href="<?= e(url('factures/' . $i['id'])) ?>#amount"><?= icon('cash') ?>Encaisser</a></td>
      <?php else: ?>
      <td class="row-action hide-sm"></td>
      <?php endif ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 30, ['q' => $f['q'], 'status' => $f['status']]) ?>
<?php endif ?>
