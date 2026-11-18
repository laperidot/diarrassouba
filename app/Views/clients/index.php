<div class="page-head">
  <h1>Clients</h1>
  <?php if (can('clients.edit')): ?><a class="btn btn-primary" href="<?= e(url('clients/nouveau')) ?>"><?= icon('plus') ?>Nouveau client</a><?php endif ?>
  <p class="lede">Expéditeurs et destinataires. Recherchez par nom, numéro de téléphone ou code client.</p>
</div>
<form class="toolbar" method="get" role="search">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, téléphone ou CL-00012" aria-label="Rechercher un client">
  <?php if ($role !== ''): ?><input type="hidden" name="role" value="<?= e($role) ?>"><?php endif ?>
  <label class="check" style="margin:0;align-self:center"><input type="checkbox" name="archives" value="1"<?= checked($archived) ?>> Inclure les archivés</label>
  <button class="btn">Rechercher</button>
</form>
<nav class="chips" aria-label="Rôle du client" style="margin-bottom:14px">
  <?php foreach (['' => 'Tous'] + App\Services\ClientService::ROLES as $k => $l): ?>
    <a class="chip" href="<?= e(url('clients', array_filter(['role' => $k, 'q' => $q, 'archives' => $archived ? '1' : '']))) ?>"<?= $role === $k ? ' aria-current="true"' : '' ?>><?= e($l) ?> <span class="chip-count"><?= (int) $counts[$k] ?></span></a>
  <?php endforeach ?>
</nav>
<?php if (!$clients): ?>
  <div class="panel empty">
    <h3><?= $q !== '' ? 'Aucun client ne correspond à « ' . e($q) . ' »' : ($role !== '' ? 'Aucun ' . e(rtrim(mb_strtolower(App\Services\ClientService::ROLES[$role]), 's')) . ' pour l’instant' : 'Aucun client enregistré') ?></h3>
    <p>Les clients sont aussi créés automatiquement lors de l’enregistrement d’un colis.</p>
    <?php if (can('clients.edit')): ?><a class="btn btn-primary" href="<?= e(url('clients/nouveau')) ?>">Créer une fiche client</a><?php endif ?>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Client</th><th>Code</th><th>Téléphone</th><th>Ville</th><th>Rôle</th><th class="num">Colis</th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr>
      <td class="primary" data-label="Client"><a class="row-link" href="<?= e(url('clients/' . $c['id'])) ?>"><?= e($c['name']) ?></a><?= $c['archived_at'] ? ' <span class="tag">archivé</span>' : '' ?></td>
      <td data-label="Code" class="code"><?= e($c['code']) ?></td>
      <td data-label="Téléphone" class="num"><?= e(can('clients.contacts') ? $c['phone'] : mask_phone($c['phone'])) ?></td>
      <td data-label="Ville" class="hide-sm"><?= e($c['city'] ?: '—') ?></td>
      <td data-label="Rôle" class="roles"><?php if ($c['sent_count'] > 0): ?><span class="tag tag-sender" title="<?= (int) $c['sent_count'] ?> colis envoyé(s)">Expéditeur</span><?php endif ?><?php if ($c['received_count'] > 0): ?><span class="tag tag-recipient" title="<?= (int) $c['received_count'] ?> colis reçu(s)">Destinataire</span><?php endif ?><?php if (!$c['parcel_count']): ?><span class="muted">—</span><?php endif ?></td>
      <td data-label="Colis" class="num"><?= (int) $c['parcel_count'] ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= paginate($total, $page, 30, ['q' => $q, 'archives' => $archived ? '1' : '', 'role' => $role]) ?>
<?php endif ?>
