<?php use App\Core\Domain; ?>
<div class="page-head">
  <h1>Factures</h1>
  <?php if (can('invoices.edit')): ?><a class="btn btn-primary" href="<?= e(url('factures/nouvelle')) ?>"><?= icon('plus') ?>Nouvelle facture</a><?php endif ?>
</div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Numéro, client" aria-label="Rechercher une facture">
  <select name="status" aria-label="Statut">
    <option value="">Toutes</option>
    <option value="open"<?= selected($f['status'], 'open') ?>>À encaisser</option>
    <option value="overdue"<?= selected($f['status'], 'overdue') ?>>Échues</option>
    <?php foreach (Domain::INVOICE_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <button class="btn">Filtrer</button>
</form>
<?php if (!$invoices): ?>
  <div class="panel empty"><h3>Aucune facture<?= array_filter($f) ? ' pour ces critères' : '' ?></h3><p>Une facture se crée à partir des colis d’un client : poids facturé × prix au kilogramme.</p><?php if (can('invoices.edit')): ?><a class="btn btn-primary" href="<?= e(url('factures/nouvelle')) ?>">Créer une facture</a><?php endif ?></div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Numéro</th><th>Client</th><th>Émise le</th><th>Échéance</th><th class="num">Total</th><th class="num">Reste dû</th><th>Statut</th></tr></thead>
  <tbody>
  <?php foreach ($invoices as $i): $due = in_array($i['status'], ['validated', 'partial'], true) ? (int) $i['total_minor'] - (int) $i['paid_minor'] : 0; ?>
    <tr>
      <td class="primary" data-label="Numéro"><a class="row-link" href="<?= e(url('factures/' . $i['id'])) ?>"><?= e($i['number'] ?? 'Brouillon #' . $i['id']) ?></a><?= $i['kind'] === 'credit_note' ? ' <span class="tag">avoir</span>' : '' ?></td>
      <td data-label="Client"><?= e($i['client_name']) ?></td>
      <td data-label="Émise le" class="hide-sm"><?= e(d($i['issue_date'])) ?></td>
      <td data-label="Échéance"><?= e(d($i['due_date'])) ?></td>
      <td data-label="Total" class="num"><?= e(money((int) $i['total_minor'], $i['currency'])) ?></td>
      <td data-label="Reste dû" class="num"<?= $due > 0 ? ' style="color:var(--coral-ink);font-weight:600"' : '' ?>><?= $due > 0 ? e(money($due, $i['currency'])) : '—' ?></td>
      <td data-label="Statut" class="end"><?= status_badge($i['status'], 'invoice') ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 30, ['q' => $f['q'], 'status' => $f['status']]) ?>
<?php endif ?>
