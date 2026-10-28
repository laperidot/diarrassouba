<?php use App\Core\Domain; ?>
<div class="page-head">
  <h1>Dépenses</h1>
  <?php if (can('expenses.edit')): ?><a class="btn btn-primary" href="<?= e(url('depenses/nouvelle')) ?>"><?= icon('plus') ?>Nouvelle dépense</a><?php endif ?>
</div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Libellé, n° de billet, bénéficiaire" aria-label="Rechercher une dépense">
  <select name="categorie" aria-label="Catégorie"><option value="">Toutes les catégories</option><?php foreach (Domain::EXPENSE_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['category'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select>
  <select name="dossier" aria-label="Dossier"><option value="">Tous dossiers</option><option value="general"<?= selected($f['shipment_id'], 'general') ?>>Frais généraux</option><?php foreach ($shipments as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($f['shipment_id'], $s['id']) ?>><?= e($s['reference']) ?></option><?php endforeach ?></select>
  <input type="date" name="du" value="<?= e($f['from']) ?>" aria-label="Du">
  <input type="date" name="au" value="<?= e($f['to']) ?>" aria-label="Au">
  <label class="check" style="margin:0;align-self:center"><input type="checkbox" name="annulees" value="1"<?= checked($f['with_cancelled']) ?>> Annulées</label>
  <button class="btn">Filtrer</button>
</form>
<?php if ($totals): ?>
<div class="panel" style="margin-bottom:14px;display:flex;flex-wrap:wrap;gap:8px 24px;align-items:baseline">
  <span class="muted small">Total des dépenses filtrées</span>
  <?php foreach ($totals as $t): ?><strong class="num" style="font-size:1.15rem"><?= e(money((int) $t['total'], $t['currency'])) ?></strong><?php endforeach ?>
</div>
<?php endif ?>
<?php if (!$expenses): ?>
  <div class="panel empty"><h3>Aucune dépense</h3><p>Billets d’avion, fret aérien, douane, manutention : enregistrez chaque dépense avec son justificatif.</p><?php if (can('expenses.edit')): ?><a class="btn btn-primary" href="<?= e(url('depenses/nouvelle')) ?>">Enregistrer une dépense</a><?php endif ?></div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Libellé</th><th>Catégorie</th><th>Date</th><th>Dossier</th><th>Paiement</th><th class="num">Montant</th></tr></thead>
  <tbody>
  <?php foreach ($expenses as $x): ?>
    <tr<?= $x['status'] === 'cancelled' ? ' style="opacity:.55"' : '' ?>>
      <td class="primary" data-label="Libellé"><a class="row-link" href="<?= e(url('depenses/' . $x['id'])) ?>"><?= e($x['description']) ?></a><?= $x['ticket_reference'] ? '<br><span class="small muted code">' . e($x['ticket_reference']) . '</span>' : '' ?><?= $x['status'] === 'cancelled' ? ' <span class="tag">annulée</span>' : '' ?><?= $x['attachment_count'] ? ' <span class="tag">justificatif</span>' : '' ?></td>
      <td data-label="Catégorie"><?= e(Domain::EXPENSE_CATEGORIES[$x['category']] ?? $x['category']) ?></td>
      <td data-label="Date"><?= e(d($x['expense_date'])) ?></td>
      <td data-label="Dossier"><?= $x['shipment_reference'] ? '<a href="' . e(url('dossiers/' . $x['shipment_id'])) . '">' . e($x['shipment_reference']) . '</a>' : '<span class="muted">Frais généraux</span>' ?></td>
      <td data-label="Paiement" class="hide-sm"><?= e(Domain::EXPENSE_PAYMENT_STATUS[$x['payment_status']] ?? '') ?></td>
      <td data-label="Montant" class="num"><strong><?= e(money((int) $x['amount_minor'], $x['currency'])) ?></strong></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 40, ['q' => $f['q'], 'categorie' => $f['category'], 'dossier' => $f['shipment_id'], 'du' => $f['from'], 'au' => $f['to']]) ?>
<?php endif ?>
