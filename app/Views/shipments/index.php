<?php use App\Core\Domain; ?>
<div class="page-head">
  <h1>Dossiers d’expédition</h1>
  <?php if (can('shipments.edit')): ?><a class="btn btn-primary" href="<?= e(url('dossiers/nouveau')) ?>"><?= icon('plus') ?>Nouveau dossier</a><?php endif ?>
  <p class="lede">Un dossier regroupe les colis d’un même vol, sous une même lettre de transport aérien.</p>
</div>
<ol class="how" aria-label="Comment ça marche">
  <li><b>1</b><span><strong>Créer le dossier du vol</strong>Compagnie, numéro de vol, destination.</span></li>
  <li><b>2</b><span><strong>Charger les colis</strong>Scannez les étiquettes ou cochez-les dans le dossier. Depuis la fiche d’un colis : « Mettre dans ce vol ».</span></li>
  <li><b>3</b><span><strong>Confirmer le départ</strong>Les colis passent « Expédié » et les clients le voient sur le suivi.</span></li>
</ol>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Référence, vol, LTA, compagnie" aria-label="Rechercher un dossier">
  <select name="statut" aria-label="Statut">
    <option value="active"<?= selected($f['status'], 'active') ?>>Dossiers en cours</option>
    <option value=""<?= selected($f['status'], '') ?>>Tous</option>
    <?php foreach (Domain::SHIPMENT_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach ?>
  </select>
  <button class="btn">Filtrer</button>
</form>
<?php if (!$shipments): ?>
  <div class="panel empty"><h3>Aucun dossier <?= $f['status'] === 'active' ? 'en cours' : '' ?></h3><p>Créez un dossier pour chaque vol, puis affectez-lui les colis en attente.</p><?php if (can('shipments.edit')): ?><a class="btn btn-primary" href="<?= e(url('dossiers/nouveau')) ?>">Créer un dossier</a><?php endif ?></div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Dossier</th><th>Trajet</th><th>Vol</th><th>Départ prévu</th><th class="num">Colis</th><th class="num">Poids</th><th>Statut</th></tr></thead>
  <tbody>
  <?php foreach ($shipments as $s): ?>
    <tr>
      <td class="primary" data-label="Dossier"><a class="row-link code" href="<?= e(url('dossiers/' . $s['id'])) ?>"><?= e($s['reference']) ?></a></td>
      <td data-label="Trajet" class="code"><?= e(Domain::iata($s['origin_city'], $s['origin_country'])) ?> → <?= e(Domain::iata($s['destination_city'], $s['destination_country'])) ?></td>
      <td data-label="Vol"><?= e(trim(($s['airline'] ?? '') . ' ' . ($s['flight_number'] ?? '')) ?: '—') ?></td>
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
