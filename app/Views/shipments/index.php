<?php use App\Core\Domain; ?>
<div class="page-head">
  <h1>Vols</h1>
  <?php if (can('shipments.edit')): ?><a class="btn btn-primary" href="<?= e(url('dossiers/nouveau')) ?>"><?= icon('plus') ?>Nouveau vol</a><?php endif ?>
  <p class="lede">Chaque vol regroupe les colis qui partent ensemble dans le même avion.</p>
</div>
<ol class="how" aria-label="Comment ça marche">
  <li><b>1</b><span><strong>Créer le vol</strong>Compagnie, numéro de vol, destination.</span></li>
  <li><b>2</b><span><strong>Mettre les colis dedans</strong>Scannez les étiquettes ou cochez-les sur la page du vol. Ou, depuis un colis : « Mettre dans un vol ».</span></li>
  <li><b>3</b><span><strong>Confirmer le départ</strong>Les colis passent « Parti » et les clients le voient sur leur page de suivi.</span></li>
</ol>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="N° du vol, compagnie, référence" aria-label="Rechercher un vol">
  <select name="statut" aria-label="Statut">
    <option value="active"<?= selected($f['status'], 'active') ?>>Vols en cours</option>
    <option value=""<?= selected($f['status'], '') ?>>Tous</option>
    <?php foreach (Domain::SHIPMENT_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <button class="btn">Filtrer</button>
</form>
<?php if (!$shipments): ?>
  <div class="panel empty"><h3>Aucun vol <?= $f['status'] === 'active' ? 'en cours' : '' ?></h3><p>Créez un vol, puis mettez-y les colis qui attendent.</p><?php if (can('shipments.edit')): ?><a class="btn btn-primary" href="<?= e(url('dossiers/nouveau')) ?>">Créer un vol</a><?php endif ?></div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Référence</th><th>Trajet</th><th>Compagnie et n°</th><th>Départ prévu</th><th class="num">Colis</th><th class="num">Poids</th><th>Statut</th></tr></thead>
  <tbody>
  <?php foreach ($shipments as $s): ?>
    <tr>
      <td class="primary" data-label="Référence"><a class="row-link code" href="<?= e(url('dossiers/' . $s['id'])) ?>"><?= e($s['reference']) ?></a></td>
      <td data-label="Trajet" class="code"><?= e(Domain::iata($s['origin_city'], $s['origin_country'])) ?> → <?= e(Domain::iata($s['destination_city'], $s['destination_country'])) ?></td>
      <td data-label="Compagnie et n°"><?= e(trim(($s['airline'] ?? '') . ' ' . ($s['flight_number'] ?? '')) ?: '—') ?></td>
      <td data-label="Départ prévu"><?= e(dt($s['planned_departure'], 'd/m/Y H:i')) ?></td>
      <td data-label="Colis" class="num"><?= (int) $s['parcel_count'] ?></td>
      <td data-label="Poids" class="num"><?= e(kg((int) $s['total_weight_g'])) ?></td>
      <td data-label="Statut" class="end"><?= status_badge($s['status'], 'shipment') ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 30, ['q' => $f['q'], 'statut' => $f['status']]) ?>
<?php endif ?>
