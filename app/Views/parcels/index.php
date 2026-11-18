<?php
use App\Core\Domain;
use App\Services\ParcelService;
$showPay = can('invoices.view');
$today = (new DateTimeImmutable('now', local_tz()))->format('Y-m-d');
/** Situation de paiement d'après la facture active du colis : [clé, ton du badge]. */
$payState = function (array $p) use ($today): array {
    return match ($p['invoice_status'] ?? null) {
        null => ['unbilled', 'muted'],
        'draft' => ['draft', 'ground'],
        'paid' => ['paid', 'done'],
        'partial', 'validated' => $p['invoice_due_date'] && $p['invoice_due_date'] < $today ? ['overdue', 'alert'] : [$p['invoice_status'] === 'partial' ? 'partial' : 'due', $p['invoice_status'] === 'partial' ? 'wait' : 'air'],
        default => ['unbilled', 'muted'],
    };
};
?>
<div class="page-head">
  <h1>Colis</h1>
  <?php if (can('parcels.create')): ?><a class="btn btn-go" href="<?= e(url('colis/nouveau')) ?>"><?= icon('plus') ?>Enregistrer un colis</a><?php endif ?>
</div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Code, client, téléphone, contenu" aria-label="Rechercher un colis">
  <select name="statut" aria-label="Statut">
    <option value="">Tous les statuts</option>
    <option value="exceptions"<?= selected($f['status'], 'exceptions') ?>>Incidents uniquement</option>
    <?php foreach (Domain::parcelStatuses() as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <select name="destination" aria-label="Destination">
    <option value="">Toutes destinations</option>
    <?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['destination'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <?php if ($showPay): ?>
  <select name="paiement" aria-label="Paiement">
    <option value="">Tous les paiements</option>
    <?php foreach (ParcelService::PAYMENT_STATES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['payment'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <?php endif ?>
  <input type="date" name="du" value="<?= e($f['from']) ?>" aria-label="Reçus à partir du">
  <input type="date" name="au" value="<?= e($f['to']) ?>" aria-label="Reçus jusqu’au">
  <button class="btn">Filtrer</button>
  <?php if (array_filter($f)): ?><a class="btn btn-quiet" href="<?= e(url('colis')) ?>">Effacer</a><?php endif ?>
</form>
<?php if (!$parcels): ?>
  <div class="panel empty">
    <?php if (array_filter($f)): ?>
      <h3>Aucun colis ne correspond à ces filtres</h3><p>Élargissez la période ou retirez un filtre.</p>
      <a class="btn" href="<?= e(url('colis')) ?>">Voir tous les colis</a>
    <?php else: ?>
      <h3>Aucun colis enregistré</h3><p>Pesez le premier colis au comptoir et enregistrez-le : son code de suivi sera créé automatiquement.</p>
      <?php if (can('parcels.create')): ?><a class="btn btn-go" href="<?= e(url('colis/nouveau')) ?>">Enregistrer un colis</a><?php endif ?>
    <?php endif ?>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Code</th><th>Expéditeur → destinataire</th><th>Contenu</th><th class="num">Poids</th><th>Vol</th><th>Où en est-il</th><?php if ($showPay): ?><th>Paiement</th><?php endif ?><th>Reçu</th></tr></thead>
  <tbody>
  <?php foreach ($parcels as $p): ?>
    <tr>
      <td class="primary" data-label="Code"><a class="row-link code" href="<?= e(url('colis/' . $p['id'])) ?>"><?= e($p['tracking_code']) ?></a></td>
      <td data-label="Clients"><?= e($p['sender_name']) ?> → <?= e($p['recipient_name']) ?></td>
      <td data-label="Contenu" class="hide-sm"><?= e($p['description']) ?></td>
      <td data-label="Poids" class="num"><?= e(kg((int) $p['weight_g'])) ?></td>
      <td data-label="Vol" class="hide-sm"><?= e($p['shipment_reference'] ?? '—') ?></td>
      <td data-label="Statut" class="end"><?= status_badge($p['status']) ?></td>
      <?php if ($showPay): [$ps, $tone] = $payState($p); ?>
      <td data-label="Paiement" class="pay-cell"><?php if ($p['invoice_id']): ?><a class="status tone-<?= $tone ?>" href="<?= e(url('factures/' . $p['invoice_id'])) ?>" title="<?= e($p['invoice_number'] ? 'Facture ' . $p['invoice_number'] : 'Brouillon de facture') ?>"><?= e(ParcelService::PAYMENT_STATES[$ps]) ?></a><?php if ($ps === 'partial' || $ps === 'overdue'): ?><span class="small muted">reste <?= e(money((int) $p['invoice_total_minor'] - (int) $p['invoice_paid_minor'], $p['currency'])) ?></span><?php endif ?><?php else: ?><span class="status tone-muted"><?= e(ParcelService::PAYMENT_STATES[$ps]) ?></span><?php endif ?></td>
      <?php endif ?>
      <td data-label="Reçu" class="hide-sm"><?= e(dt($p['received_at'], 'd/m/Y')) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 30, ['q' => $f['q'], 'statut' => $f['status'], 'destination' => $f['destination'], 'du' => $f['from'], 'au' => $f['to'], 'paiement' => $f['payment']]) ?>
<?php endif ?>
