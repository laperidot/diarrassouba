<?php use App\Core\Domain; use App\Core\Auth; ?>
<div class="page-head"><h1><?= e($title) ?></h1><p class="lede">Les colis à donner aux clients : au dépôt ou à domicile. Pour chaque remise, on note le nom de la personne qui prend le colis.</p></div>
<nav class="chips" aria-label="Filtre" style="margin-bottom:14px">
  <?php foreach (['open' => 'À faire', 'done' => 'Remis', 'failed' => 'Pas pu livrer', '' => 'Toutes'] as $k => $l): ?>
    <a class="chip" href="<?= e(url('livraisons', ['statut' => $k ?: 'all'])) ?>"<?= ($status === $k || ($k === '' && $status === 'all')) ? ' aria-current="true"' : '' ?>><?= e($l) ?></a>
  <?php endforeach ?>
</nav>
<div class="grid <?= $ready ? 'grid-main' : '' ?>">
  <div>
  <?php if (!$deliveries): ?>
    <div class="panel empty"><h3><?= $status === 'open' ? 'Aucune remise en attente' : 'Aucune remise' ?></h3><p><?= Auth::role() === 'courier' ? 'Les livraisons qui vous sont confiées apparaîtront ici.' : 'Quand un colis est arrivé, ouvrez-le pour le remettre au client.' ?></p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table stacked">
      <thead><tr><th>Colis</th><th>Destinataire</th><th>Où</th><th>Livreur</th><th>Prévu le</th><th>Statut</th><th><span class="sr-only">Action</span></th></tr></thead>
      <tbody>
      <?php foreach ($deliveries as $d): $phone = $d['delivery_contact_phone'] ?: $d['recipient_phone']; $address = $d['address'] ?: $d['recipient_address']; ?>
        <tr>
          <td class="primary" data-label="Colis"><a class="row-link code" href="<?= e(url('livraisons/' . $d['id'])) ?>"><?= e($d['tracking_code']) ?></a><?= (int) $d['attempt_no'] > 1 ? ' <span class="tag">' . (int) $d['attempt_no'] . 'e essai</span>' : '' ?></td>
          <td data-label="Destinataire"><?= e($d['delivery_contact_name'] ?: $d['recipient_client']) ?>
            <?php if ($phone): ?><a class="tel" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $phone)) ?>"><?= e($phone) ?></a><?php endif ?>
            <?php if ($d['mode'] === 'delivery' && $address): ?><span class="addr"><?= e($address) ?></span><?php endif ?></td>
          <td data-label="Où"><?= e(Domain::DELIVERY_MODES[$d['mode']]) ?></td>
          <td data-label="Livreur" class="hide-sm"><?= e($d['agent_name'] ?? '—') ?></td>
          <td data-label="Prévu le"><?= e(d($d['planned_date'])) ?></td>
          <td data-label="Statut" class="end"><?= status_badge($d['status'], 'delivery') ?></td>
          <?php if (can('deliveries.confirm') && $d['status'] === 'planned' && $d['mode'] === 'delivery'): ?>
          <td class="row-action"><form method="post" action="<?= e(url('livraisons/' . $d['id'] . '/demarrer')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-go"><?= icon('truck') ?>Je pars livrer</button></form></td>
          <?php elseif (can('deliveries.confirm') && in_array($d['status'], ['planned', 'in_progress'], true)): ?>
          <td class="row-action"><a class="btn btn-sm btn-primary" href="<?= e(url('livraisons/' . $d['id'])) ?>#recipient_name"><?= icon('check') ?>C’est remis</a></td>
          <?php else: ?>
          <td class="row-action hide-sm"></td>
          <?php endif ?>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    </div>
  <?php endif ?>
  </div>
  <?php if ($ready): ?>
  <aside class="panel">
    <h2 style="margin-bottom:4px">Prêts à remettre</h2>
    <p class="small muted">Colis arrivés qui attendent leur client.</p>
    <?php foreach ($ready as $r): ?>
      <div class="kv-line"><a class="code" href="<?= e(url('colis/' . $r['id'])) ?>"><?= e($r['tracking_code']) ?></a><span class="small"><?= e($r['recipient_name']) ?></span><?php if (can('deliveries.confirm')): ?><a class="btn btn-sm" href="<?= e(url('colis/' . $r['id'])) ?>#remise">Remettre</a><?php else: ?><?= status_badge($r['status']) ?><?php endif ?></div>
    <?php endforeach ?>
  </aside>
  <?php endif ?>
</div>
