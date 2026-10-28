<div class="page-head"><h1>Journal des opérations</h1><p class="lede">Connexions, créations, changements de statut, opérations financières. Les 200 dernières entrées correspondant au filtre.</p></div>
<form class="toolbar" method="get">
  <input name="action" value="<?= e($f['action']) ?>" placeholder="Action (ex. payment, invoice.cancelled)" aria-label="Action">
  <select name="type" aria-label="Type"><option value="">Tous les objets</option><?php foreach (['parcel' => 'Colis', 'shipment' => 'Dossier', 'invoice' => 'Facture', 'payment' => 'Paiement', 'expense' => 'Dépense', 'delivery' => 'Livraison', 'client' => 'Client', 'user' => 'Utilisateur', 'tariff' => 'Tarif'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['entity_type'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select>
  <input name="id" value="<?= e($f['entity_id']) ?>" placeholder="N° interne" inputmode="numeric" aria-label="Identifiant" style="max-width:120px">
  <button class="btn">Filtrer</button>
</form>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>Détails</th></tr></thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
    <tr>
      <td data-label="Date" class="num"><?= e(dt($l['created_at'], 'd/m/Y H:i:s')) ?></td>
      <td data-label="Utilisateur"><?= e($l['user_name'] ?? 'Système') ?></td>
      <td class="primary" data-label="Action"><span class="code" style="font-size:.85rem"><?= e($l['action']) ?></span></td>
      <td data-label="Objet"><?= e(($l['entity_type'] ?? '') . ($l['entity_id'] ? ' #' . $l['entity_id'] : '')) ?></td>
      <td data-label="Détails" class="small muted" style="max-width:340px;overflow-wrap:anywhere"><?= e($l['details'] ?? '') ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$logs): ?><tr><td colspan="5" class="muted">Aucune entrée.</td></tr><?php endif ?>
  </tbody>
</table>
</div>
