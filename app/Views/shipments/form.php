<?php
use App\Core\Domain;
use App\Core\Money;
$s = $shipment ?? ($preset ?? []);
$v = fn ($k, $d = '') => old($k, $s[$k] ?? $d);
$cur = old('freight_currency', $s['freight_currency'] ?? 'XOF');
?>
<div class="page-head"><div><a class="crumb" href="<?= e(url($shipment ? 'dossiers/' . $s['id'] : 'dossiers')) ?>">← <?= $shipment ? e($s['reference']) : 'Dossiers' ?></a><h1><?= e($title) ?></h1></div></div>
<form class="panel form" method="post" action="<?= e(url($shipment ? 'dossiers/' . $s['id'] : 'dossiers')) ?>">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Trajet</legend>
    <div class="row row-2">
      <div class="field"><label for="origin_country">Départ</label><select id="origin_country" name="origin_country" data-city-target="origin_city"><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>" data-city="<?= e(Domain::COUNTRY_CITY[$k] ?? '') ?>"<?= selected($v('origin_country', 'EG'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="origin_city">Ville / aéroport de départ</label><input id="origin_city" name="origin_city" value="<?= $v('origin_city', 'Le Caire') ?>"></div>
      <div class="field"><label for="destination_country">Destination</label><select id="destination_country" name="destination_country" data-city-target="destination_city"><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>" data-city="<?= e(Domain::COUNTRY_CITY[$k] ?? '') ?>"<?= selected($v('destination_country', 'CI'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select><span class="hint">Seuls les colis de cette destination pourront être ajoutés.</span></div>
      <div class="field"><label for="destination_city">Ville / aéroport d’arrivée</label><input id="destination_city" name="destination_city" value="<?= $v('destination_city', 'Abidjan') ?>"></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>Vol et transporteur</legend>
    <div class="row row-2">
      <div class="field"><label for="airline">Compagnie aérienne</label><input id="airline" name="airline" value="<?= $v('airline') ?>" placeholder="Ex. : EgyptAir"></div>
      <div class="field"><label for="flight_number">Numéro de vol</label><input id="flight_number" name="flight_number" value="<?= $v('flight_number') ?>" placeholder="MS 0000" autocapitalize="characters"></div>
      <div class="field"><label for="awb_number">Lettre de transport aérien (LTA / AWB)</label><input id="awb_number" name="awb_number" value="<?= $v('awb_number') ?>"></div>
      <div class="field"><label for="carrier_reference">Autre référence transporteur</label><input id="carrier_reference" name="carrier_reference" value="<?= $v('carrier_reference') ?>"></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>Dates</legend>
    <div class="row row-2">
      <div class="field"><label for="planned_departure">Départ prévu</label><input id="planned_departure" name="planned_departure" type="datetime-local" value="<?= old('planned_departure', utc_to_local_input($s['planned_departure'] ?? null)) ?>"<?= invalid('planned_departure') ?>><?= field_error('planned_departure') ?></div>
      <div class="field"><label for="planned_arrival">Arrivée prévue</label><input id="planned_arrival" name="planned_arrival" type="datetime-local" value="<?= old('planned_arrival', utc_to_local_input($s['planned_arrival'] ?? null)) ?>"<?= invalid('planned_arrival') ?>><?= field_error('planned_arrival') ?><span class="hint">Affichée au client comme estimation.</span></div>
      <?php if ($shipment): ?>
      <div class="field"><label for="actual_departure">Départ réel</label><input id="actual_departure" name="actual_departure" type="datetime-local" value="<?= old('actual_departure', utc_to_local_input($s['actual_departure'] ?? null)) ?>"></div>
      <div class="field"><label for="actual_arrival">Arrivée réelle</label><input id="actual_arrival" name="actual_arrival" type="datetime-local" value="<?= old('actual_arrival', utc_to_local_input($s['actual_arrival'] ?? null)) ?>"<?= invalid('actual_arrival') ?>><?= field_error('actual_arrival') ?></div>
      <?php endif ?>
    </div>
  </fieldset>
  <fieldset>
    <legend>Coût</legend>
    <div class="row row-2">
      <div class="field"><label for="freight_cost">Frais de fret prévus (devis)</label><input id="freight_cost" name="freight_cost" inputmode="decimal" value="<?= old('freight_cost', Money::input(isset($s['freight_cost_minor']) ? (int) $s['freight_cost_minor'] : null, $cur)) ?>"<?= invalid('freight_cost') ?>><?= field_error('freight_cost') ?><span class="hint">Information seulement. Le fret réellement payé s’enregistre en dépense « Fret aérien » liée à ce dossier.</span></div>
      <div class="field"><label for="freight_currency">Devise</label><select id="freight_currency" name="freight_currency"><?php foreach (Domain::CURRENCIES as $k => $c): ?><option value="<?= $k ?>"<?= selected($cur, $k) ?>><?= e($c['label']) ?></option><?php endforeach ?></select></div>
    </div>
    <div class="field"><label for="notes">Observations logistiques</label><textarea id="notes" name="notes"><?= $v('notes') ?></textarea></div>
  </fieldset>
  <div class="form-actions sticky"><button class="btn btn-primary"><?= $shipment ? 'Enregistrer' : 'Créer le dossier' ?></button><a class="btn btn-quiet" href="<?= e(url($shipment ? 'dossiers/' . $s['id'] : 'dossiers')) ?>">Annuler</a></div>
</form>
