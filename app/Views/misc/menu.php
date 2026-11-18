<?php use App\Core\Auth; ?>
<div class="page-head"><h1>Menu</h1><p class="lede"><?= e($user['name']) ?>, <?= e(App\Core\Domain::ROLES[$user['role_code']] ?? '') ?></p></div>
<p class="menu-group">Au quotidien</p>
<ul class="menu-list">
  <?php if (Auth::role() !== 'courier'): ?><li><a href="<?= e(url('tableau-de-bord')) ?>"><?= icon('home') ?>Accueil</a></li><?php endif ?>
  <?php if (can('parcels.create')): ?><li><a href="<?= e(url('colis/nouveau')) ?>"><?= icon('plus') ?>Enregistrer un colis</a></li><?php endif ?>
  <?php if (can('parcels.view') && Auth::role() !== 'courier'): ?><li><a href="<?= e(url('colis')) ?>"><?= icon('box') ?>Colis</a></li><?php endif ?>
  <?php if (can('shipments.view')): ?><li><a href="<?= e(url('dossiers')) ?>"><?= icon('plane') ?>Vols</a></li><?php endif ?>
  <?php if (can('deliveries.view')): ?><li><a href="<?= e(url('livraisons')) ?>"><?= icon('truck') ?>Livraisons et retraits</a></li><?php endif ?>
  <?php if (can('clients.view')): ?><li><a href="<?= e(url('clients')) ?>"><?= icon('users') ?>Clients</a></li><?php endif ?>
</ul>
<?php if (can('invoices.view') || can('expenses.view')): ?>
<p class="menu-group">Argent</p>
<ul class="menu-list">
  <?php if (can('invoices.view')): ?><li><a href="<?= e(url('factures')) ?>"><?= icon('invoice') ?>Factures</a></li><li><a href="<?= e(url('paiements')) ?>"><?= icon('cash') ?>Paiements</a></li><?php endif ?>
  <?php if (can('expenses.view')): ?><li><a href="<?= e(url('depenses')) ?>"><?= icon('wallet') ?>Dépenses</a></li><?php endif ?>
</ul>
<?php endif ?>
<div class="install-zone" data-install-zone hidden>
  <p class="menu-group">Application</p>
  <button type="button" class="install-card" data-install hidden>
    <span class="mark" aria-hidden="true">DI</span>
    <span><strong>Installer l’application</strong><span class="small muted">Icône sur l’écran d’accueil, plein écran, ouverture plus rapide.</span></span>
    <?= icon('download') ?>
  </button>
  <div class="install-card install-ios" data-install-ios hidden>
    <span class="mark" aria-hidden="true">DI</span>
    <span><strong>Installer sur iPhone / iPad</strong><span class="small muted">Dans Safari, touchez <?= icon('share') ?> <b>Partager</b>, puis <b>Sur l’écran d’accueil</b>.</span></span>
  </div>
</div>
<p class="menu-group">Apparence</p>
<div class="theme-pick"><span>Thème de l’application</span><?= theme_picker() ?></div>
<p class="menu-group">Mon compte et réglages</p>
<ul class="menu-list">
  <li><a href="<?= e(url('notifications')) ?>"><?= icon('bell') ?>Notifications</a></li>
  <?php if (can('users.manage')): ?><li><a href="<?= e(url('utilisateurs')) ?>"><?= icon('users') ?>Utilisateurs</a></li><?php endif ?>
  <?php if (can('tariffs.manage')): ?><li><a href="<?= e(url('tarifs')) ?>"><?= icon('tag') ?>Prix au kilo</a></li><?php endif ?>
  <?php if (can('audit.view')): ?><li><a href="<?= e(url('journal')) ?>"><?= icon('shield') ?>Historique des actions</a></li><?php endif ?>
  <?php if (can('settings.manage')): ?><li><a href="<?= e(url('parametres')) ?>"><?= icon('settings') ?>Paramètres (téléphone, adresse, dépôt)</a></li><?php endif ?>
  <li><a href="<?= e(url('suivi')) ?>"><?= icon('route') ?>Page de suivi pour les clients</a></li>
  <li><a href="<?= e(url('mot-de-passe')) ?>"><?= icon('key') ?>Changer mon mot de passe</a></li>
</ul>
<form method="post" action="<?= e(url('deconnexion')) ?>" style="margin-top:18px"><?= csrf_field() ?><button class="btn btn-block"><?= icon('logout') ?>Se déconnecter</button></form>
