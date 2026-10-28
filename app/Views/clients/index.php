<div class="page-head">
  <h1>Clients</h1>
  <?php if (can('clients.edit')): ?><a class="btn btn-primary" href="<?= e(url('clients/nouveau')) ?>"><?= icon('plus') ?>Nouveau client</a><?php endif ?>
  <p class="lede">Expéditeurs et destinataires. Recherchez par nom, numéro de téléphone ou code client.</p>
</div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, téléphone ou CL-00012" aria-label="Rechercher un client">
  <label class="check" style="margin:0;align-self:center"><input type="checkbox" name="archives" value="1"<?= checked($archived) ?>> Inclure les archivés</label>
  <button class="btn">Rechercher</button>
</form>
<?php if (!$clients): ?>
  <div class="panel empty">
    <h3><?= $q !== '' ? 'Aucun client ne correspond à « ' . e($q) . ' »' : 'Aucun client enregistré' ?></h3>
    <p>Les clients sont aussi créés automatiquement lors de l’enregistrement d’un colis.</p>
    <?php if (can('clients.edit')): ?><a class="btn btn-primary" href="<?= e(url('clients/nouveau')) ?>">Créer une fiche client</a><?php endif ?>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Client</th><th>Code</th><th>Téléphone</th><th>Ville</th><th class="num">Colis</th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr>
      <td class="primary" data-label="Client"><a class="row-link" href="<?= e(url('clients/' . $c['id'])) ?>"><?= e($c['name']) ?></a><?= $c['archived_at'] ? ' <span class="tag">archivé</span>' : '' ?></td>
      <td data-label="Code" class="code"><?= e($c['code']) ?></td>
      <td data-label="Téléphone" class="num"><?= e(can('clients.contacts') ? $c['phone'] : mask_phone($c['phone'])) ?></td>
      <td data-label="Ville" class="hide-sm"><?= e($c['city'] ?: '—') ?></td>
      <td data-label="Colis" class="num"><?= (int) $c['parcel_count'] ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 30, ['q' => $q, 'archives' => $archived ? '1' : '']) ?>
<?php endif ?>
