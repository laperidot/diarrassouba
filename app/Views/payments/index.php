<?php use App\Core\Domain; ?>
<div class="page-head"><h1>Paiements</h1><p class="lede">Pour enregistrer un paiement, ouvrez la facture du client. En cas d’erreur, annulez le paiement : il reste visible, barré.</p></div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="N° de reçu, de facture, client" aria-label="Rechercher un paiement">
  <select name="moyen" aria-label="Payé par"><option value="">Tous les moyens de paiement</option><?php foreach (Domain::PAYMENT_METHODS as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['method'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select>
  <button class="btn">Filtrer</button>
</form>
<?php if (!$payments): ?>
  <div class="panel empty"><h3>Aucun paiement</h3><p>Ouvrez une facture pour enregistrer un paiement.</p><a class="btn" href="<?= e(url('factures', ['status' => 'open'])) ?>">Factures pas encore payées</a></div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Reçu</th><th>Date</th><th>Client</th><th>Facture</th><th>Moyen</th><th class="num">Montant</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td class="primary" data-label="Reçu"><a class="row-link code" href="<?= e(url('paiements/' . $p['id'] . '/recu')) ?>" target="_blank" rel="noopener"><?= e($p['receipt_number']) ?></a><?= $p['reversed_by_id'] ? ' <span class="tag">annulé</span>' : '' ?></td>
      <td data-label="Date"><?= e(dt($p['paid_at'])) ?></td>
      <td data-label="Client"><?= e($p['client_name']) ?></td>
      <td data-label="Facture"><a href="<?= e(url('factures/' . $p['invoice_id'])) ?>"><?= e($p['invoice_number']) ?></a></td>
      <td data-label="Moyen" class="hide-sm"><?= e(Domain::PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
      <td data-label="Montant" class="num"<?= $p['amount_minor'] < 0 ? ' style="color:var(--coral-ink)"' : '' ?>><strong><?= e(money((int) $p['amount_minor'], $p['currency'])) ?></strong></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<div class="pagination"><?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('paiements', ['page' => $page - 1, 'q' => $f['q'], 'moyen' => $f['method']])) ?>">Précédent</a><?php else: ?><span></span><?php endif ?><span>Page <?= $page ?></span><?php if (count($payments) === 40): ?><a class="btn btn-sm" href="<?= e(url('paiements', ['page' => $page + 1, 'q' => $f['q'], 'moyen' => $f['method']])) ?>">Suivant</a><?php else: ?><span></span><?php endif ?></div>
<?php endif ?>
