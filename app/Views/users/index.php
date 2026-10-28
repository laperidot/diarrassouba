<?php use App\Core\Domain; ?>
<div class="page-head"><h1>Utilisateurs</h1><a class="btn btn-primary" href="<?= e(url('utilisateurs/nouveau')) ?>"><?= icon('plus') ?>Nouvel utilisateur</a></div>
<div class="table-wrap">
<table class="table stacked">
  <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Dernière connexion</th><th>État</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td class="primary" data-label="Nom"><a class="row-link" href="<?= e(url('utilisateurs/' . $u['id'])) ?>"><?= e($u['name']) ?></a></td>
      <td data-label="E-mail"><?= e($u['email']) ?></td>
      <td data-label="Rôle"><?= e(Domain::ROLES[$u['role_code']] ?? $u['role_code']) ?></td>
      <td data-label="Connexion" class="hide-sm"><?= e($u['last_login_at'] ? ago($u['last_login_at']) : 'Jamais') ?></td>
      <td data-label="État" class="end"><span class="status <?= $u['is_active'] ? 'tone-done' : 'tone-muted' ?>"><?= $u['is_active'] ? 'Actif' : 'Désactivé' ?></span></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<section class="panel" style="margin-top:16px">
  <h2 style="margin-bottom:10px">Ce que chaque rôle peut faire</h2>
  <div class="grid grid-3 small">
    <div><strong>Administrateur</strong><p class="muted">Tout, dont les utilisateurs.</p></div>
    <div><strong>Responsable</strong><p class="muted">Toutes les opérations et finances, annulations, tarifs, journal.</p></div>
    <div><strong>Agent d’enregistrement</strong><p class="muted">Clients, colis, étiquettes, statuts.</p></div>
    <div><strong>Agent logistique</strong><p class="muted">Dossiers de vol, statuts, livraisons, dépenses.</p></div>
    <div><strong>Comptable / caissier</strong><p class="muted">Factures, paiements, reçus, dépenses.</p></div>
    <div><strong>Agent de livraison</strong><p class="muted">Uniquement ses livraisons et leur confirmation.</p></div>
  </div>
</section>
