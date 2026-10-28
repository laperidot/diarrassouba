<?php use App\Core\Domain; use App\Core\Money; ?>
<div class="page-head"><h1>Tarifs au kilogramme</h1><p class="lede">Proposés à l’enregistrement d’un colis. Une modification ne change pas les colis déjà enregistrés : chaque colis garde le tarif appliqué le jour de sa prise en charge.</p></div>
<?php if ($tariffs): ?>
<div class="stack">
<?php foreach ($tariffs as $t): ?>
  <details class="panel inline-form">
    <summary style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <strong><?= e($t['name']) ?></strong>
      <span class="code"><?= e($t['origin_country']) ?> → <?= e($t['destination_country']) ?></span>
      <span class="num"><?= e(money((int) $t['rate_per_kg_minor'], $t['currency'])) ?> / kg</span>
      <?php if ((int) $t['min_billable_g'] > 0): ?><span class="small muted">minimum <?= e(kg((int) $t['min_billable_g'])) ?></span><?php endif ?>
      <span class="status <?= $t['is_active'] ? 'tone-done' : 'tone-muted' ?>" style="margin-left:auto"><?= $t['is_active'] ? 'Actif' : 'Inactif' ?></span>
    </summary>
    <?= App\Core\View::partial('users/_tariff_form', ['t' => $t]) ?>
  </details>
<?php endforeach ?>
</div>
<?php else: ?>
  <div class="panel empty"><h3>Aucun tarif</h3><p>Créez au moins un tarif Égypte → Côte d’Ivoire pour accélérer l’enregistrement des colis.</p></div>
<?php endif ?>
<section class="panel" style="margin-top:16px">
  <h2 style="margin-bottom:12px">Nouveau tarif</h2>
  <?= App\Core\View::partial('users/_tariff_form', ['t' => null]) ?>
</section>
