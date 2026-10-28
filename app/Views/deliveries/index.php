<?php use App\Core\Domain; use App\Core\Auth; ?>
<div class="page-head"><h1><?= e($title) ?></h1><p class="lede">Retraits au dépôt et livraisons à domicile. La remise n’est confirmée qu’avec le nom de la personne qui reçoit le colis.</p></div>
<nav class="chips" aria-label="Filtre" style="margin-bottom:14px">
  <?php foreach (['open' => 'À faire', 'done' => 'Remises confirmées', 'failed' => 'Échecs', '' => 'Toutes'] as $k => $l): ?>
    <a class="chip" href="<?= e(url('livraisons', ['statut' => $k ?: 'all'])) ?>"<?= ($status === $k || ($k === '' && $status === 'all')) ? ' aria-current="true"' : '' ?>><?= e($l) ?></a>
  <?php endforeach ?>
</nav>
<div class="grid <?= $ready ? 'grid-main' : '' ?>">
  <div>
  <?php if (!$deliveries): ?>
    <div class="panel empty"><h3><?= $status === 'open' ? 'Aucune remise en attente' : 'Aucune remise' ?></h3><p><?= Auth::role() === 'courier' ? 'Les livraisons qui vous sont confiées apparaîtront ici.' : 'Planifiez une remise depuis la fiche d’un colis arrivé à destination.' ?></p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table stacked">
      <thead><tr><th>Colis</th><th>Destinataire</th><th>Mode</th><th>Responsable</th><th>Date prévue</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($deliveries as $d): ?>
        <tr>
          <td class="primary" data-label="Colis"><a class="row-link code" href="<?= e(url('livraisons/' . $d['id'])) ?>"><?= e($d['tracking_code']) ?></a><?= (int) $d['attempt_no'] > 1 ? ' <span class="tag">tentative ' . (int) $d['attempt_no'] . '</span>' : '' ?></td>
          <td data-label="Destinataire"><?= e($d['delivery_contact_name'] ?: $d['recipient_client']) ?></td>
          <td data-label="Mode"><?= e(Domain::DELIVERY_MODES[$d['mode']]) ?></td>
          <td data-label="Responsable" class="hide-sm"><?= e($d['agent_name'] ?? '—') ?></td>
          <td data-label="Date prévue"><?= e(d($d['planned_date'])) ?></td>
          <td data-label="Statut" class="end"><?= status_badge($d['status'], 'delivery') ?></td>
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
    <p class="small muted">Arrivés ou au dépôt, sans remise planifiée.</p>
    <?php foreach ($ready as $r): ?>
      <div class="kv-line"><a class="code" href="<?= e(url('colis/' . $r['id'])) ?>"><?= e($r['tracking_code']) ?></a><span class="small"><?= e($r['recipient_name']) ?></span><?= status_badge($r['status']) ?></div>
    <?php endforeach ?>
  </aside>
  <?php endif ?>
</div>
