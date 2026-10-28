<?php use App\Core\Domain; $s = $shipment; ?>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div><strong class="brand-name" style="color:var(--ink)">DIARRASSOUBA IMPORT-EXPORT</strong><h1 style="margin-top:8px">Manifeste <?= e($s['reference']) ?></h1>
  <p class="muted"><?= e(Domain::iata($s['origin_city'], $s['origin_country'])) ?> → <?= e(Domain::iata($s['destination_city'], $s['destination_country'])) ?>, <?= e(trim(($s['airline'] ?? '') . ' ' . ($s['flight_number'] ?? ''))) ?>, LTA <?= e($s['awb_number'] ?: '—') ?>, départ <?= e(dt($s['actual_departure'] ?? $s['planned_departure'])) ?></p></div>
  <button class="btn no-print" data-print type="button">Imprimer</button>
</div>
<table class="table" style="margin-top:12px;border:1px solid var(--line)">
  <thead><tr><th>#</th><th>Code</th><th>Expéditeur</th><th>Destinataire</th><th>Contenu</th><th class="num">Pièces</th><th class="num">Poids</th></tr></thead>
  <tbody>
  <?php $tw = 0; $tp = 0; foreach ($parcels as $i => $p): $tw += (int) $p['weight_g']; $tp += (int) $p['pieces']; ?>
    <tr><td><?= $i + 1 ?></td><td class="code"><?= e($p['tracking_code']) ?></td><td><?= e($p['sender_name']) ?></td><td><?= e($p['recipient_name']) ?></td><td><?= e($p['description']) ?></td><td class="num"><?= (int) $p['pieces'] ?></td><td class="num"><?= e(kg((int) $p['weight_g'])) ?></td></tr>
  <?php endforeach ?>
  </tbody>
  <tfoot><tr><th colspan="5">Total : <?= count($parcels) ?> colis</th><th class="num"><?= $tp ?></th><th class="num"><?= e(kg($tw)) ?></th></tr></tfoot>
</table>
<p class="small muted" style="margin-top:16px">Édité le <?= e(dt(gmdate('Y-m-d H:i:s'))) ?></p>
