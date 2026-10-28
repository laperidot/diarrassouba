<?php use App\Core\Domain; use App\Core\Money; $id = $t['id'] ?? 'new'; ?>
<form method="post" action="<?= e(url($t ? 'tarifs/' . $t['id'] : 'tarifs')) ?>" style="margin-top:12px">
  <?= csrf_field() ?>
  <div class="row row-2">
    <div class="field"><label for="tn-<?= $id ?>">Nom</label><input id="tn-<?= $id ?>" name="name" required value="<?= e($t['name'] ?? 'Standard Le Caire → Abidjan') ?>"></div>
    <div class="field"><label for="tc-<?= $id ?>">Devise</label><select id="tc-<?= $id ?>" name="currency"><?php foreach (Domain::CURRENCIES as $k => $c): ?><option value="<?= $k ?>"<?= selected($t['currency'] ?? 'XOF', $k) ?>><?= e($c['label']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="to-<?= $id ?>">Départ</label><select id="to-<?= $id ?>" name="origin_country"><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>"<?= selected($t['origin_country'] ?? 'EG', $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="td-<?= $id ?>">Destination</label><select id="td-<?= $id ?>" name="destination_country"><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>"<?= selected($t['destination_country'] ?? 'CI', $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="tr-<?= $id ?>">Prix par kg</label><input id="tr-<?= $id ?>" name="rate" inputmode="decimal" required value="<?= $t ? e(Money::input((int) $t['rate_per_kg_minor'], $t['currency'])) : '' ?>"></div>
    <div class="field"><label for="tm-<?= $id ?>">Poids minimum facturé (kg)</label><input id="tm-<?= $id ?>" name="min_kg" inputmode="decimal" value="<?= $t ? e(Money::kgInput((int) $t['min_billable_g'])) : '1' ?>"></div>
  </div>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked(!$t || (int) $t['is_active'] === 1) ?>> Proposé à l’enregistrement</label>
  <button class="btn btn-primary"><?= $t ? 'Enregistrer' : 'Ajouter le tarif' ?></button>
</form>
