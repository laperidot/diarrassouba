<?php
use App\Core\Config;
use App\Core\Domain;
use App\Core\Money;

$p = $parcel ?? [];
$editing = $parcel !== null;
$v = fn ($k, $d = '') => old($k, $p[$k] ?? $d);
$currency = old('currency', $p['currency'] ?? Config::get('DEFAULT_CURRENCY', 'XOF'));
$prefSender = !$editing && !empty($_GET['expediteur']) ? (new App\Services\ClientService())->find((int) $_GET['expediteur']) : null;
$billingLocked = $billingLocked ?? false;

$picker = function (string $role, string $label, ?array $chosen) {
    $idVal = old($role . '_id', $chosen['id'] ?? '');
    ob_start(); ?>
  <div class="picker" data-picker="<?= $role ?>" data-search="<?= e(url('api/clients')) ?>">
    <input type="hidden" name="<?= $role ?>_id" value="<?= $idVal ?>">
    <div class="picker-chosen"<?= $chosen ? '' : ' hidden' ?>>
      <span class="who"><strong data-who-name><?= e($chosen['name'] ?? '') ?></strong><br><span class="small muted" data-who-meta><?= e(($chosen['code'] ?? '') . ' ' . (can('clients.contacts') ? ($chosen['phone'] ?? '') : '')) ?></span></span>
      <button type="button" class="btn btn-sm" data-clear>Changer</button>
    </div>
    <div data-search-box<?= $chosen ? ' hidden' : '' ?>>
      <div class="field" style="margin-bottom:8px">
        <label for="<?= $role ?>-search"><?= e($label) ?></label>
        <input id="<?= $role ?>-search" type="search" autocomplete="off" placeholder="Rechercher par nom ou téléphone" data-search-input>
        <div class="picker-results" hidden role="listbox"></div>
      </div>
      <p class="small muted" style="margin:0 0 8px">Nouveau client ? Saisissez son nom et son téléphone : la fiche sera créée, ou retrouvée si le numéro existe déjà.</p>
      <div class="row row-2">
        <div class="field"><label for="<?= $role ?>_name">Nom</label><input id="<?= $role ?>_name" name="<?= $role ?>_name" value="<?= old($role . '_name') ?>"<?= invalid($role) ?>></div>
        <div class="field"><label for="<?= $role ?>_phone">Téléphone</label><input id="<?= $role ?>_phone" name="<?= $role ?>_phone" type="tel" inputmode="tel" value="<?= old($role . '_phone') ?>"></div>
      </div>
    </div>
    <?= field_error($role) ?>
  </div>
<?php return ob_get_clean();
};
$senderChosen = $editing ? ['id' => $p['sender_id'], 'name' => $p['sender_name'], 'code' => $p['sender_code'], 'phone' => $p['sender_phone']] : $prefSender;
$recipientChosen = $editing ? ['id' => $p['recipient_id'], 'name' => $p['recipient_name'], 'code' => $p['recipient_code'], 'phone' => $p['recipient_phone']] : null;
?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url($editing ? 'colis/' . $p['id'] : 'colis')) ?>">← <?= $editing ? e($p['tracking_code']) : 'Colis' ?></a><h1><?= e($title) ?></h1></div>
</div>

<form class="form" method="post" enctype="multipart/form-data" action="<?= e(url($editing ? 'colis/' . $p['id'] : 'colis')) ?>" data-parcel-form>
  <?= csrf_field() ?>
  <div class="panel">
    <fieldset>
      <legend>Expéditeur</legend>
      <?= $picker('sender', 'Client expéditeur', $senderChosen) ?>
    </fieldset>
    <fieldset style="margin-bottom:0">
      <legend>Destinataire</legend>
      <?= $picker('recipient', 'Client destinataire', $recipientChosen) ?>
      <div class="row row-2">
        <div class="field"><label for="delivery_contact_name">Personne à contacter à l’arrivée <span class="muted">(si différente)</span></label><input id="delivery_contact_name" name="delivery_contact_name" value="<?= $v('delivery_contact_name') ?>"></div>
        <div class="field"><label for="delivery_contact_phone">Son téléphone</label><input id="delivery_contact_phone" name="delivery_contact_phone" type="tel" inputmode="tel" value="<?= $v('delivery_contact_phone') ?>"<?= invalid('delivery_contact_phone') ?>><?= field_error('delivery_contact_phone') ?></div>
      </div>
    </fieldset>
  </div>

  <div class="panel">
    <fieldset>
      <legend>Colis</legend>
      <div class="field"><label for="description">Contenu</label><input id="description" name="description" required value="<?= $v('description') ?>" placeholder="Ex. : vêtements, pièces détachées, cosmétiques"<?= invalid('description') ?>><?= field_error('description') ?></div>
      <div class="row row-2">
        <div class="field"><label for="weight_kg">Poids pesé</label><div class="input-suffix"><input id="weight_kg" name="weight_kg" inputmode="decimal" required value="<?= old('weight_kg', Money::kgInput(isset($p['weight_g']) ? (int) $p['weight_g'] : null)) ?>" placeholder="12,5"<?= invalid('weight_kg') ?><?= $billingLocked ? ' readonly' : '' ?>><span>kg</span></div><?= field_error('weight_kg') ?></div>
        <div class="field"><label for="pieces">Nombre de pièces</label><input id="pieces" name="pieces" type="number" min="1" max="999" value="<?= $v('pieces', '1') ?>"></div>
      </div>
    </fieldset>
    <fieldset style="margin-bottom:0">
      <legend>Trajet</legend>
      <div class="row row-2">
        <div class="field"><label for="origin_country">Départ</label><select id="origin_country" name="origin_country" data-city-target="origin_city"><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>" data-city="<?= e(Domain::COUNTRY_CITY[$k] ?? '') ?>"<?= selected($v('origin_country', 'EG'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="origin_city">Ville de départ</label><input id="origin_city" name="origin_city" value="<?= $v('origin_city', 'Le Caire') ?>"></div>
        <div class="field"><label for="destination_country">Destination</label><select id="destination_country" name="destination_country" data-city-target="destination_city"><?php foreach (Domain::COUNTRIES as $k => $l): ?><option value="<?= $k ?>" data-city="<?= e(Domain::COUNTRY_CITY[$k] ?? '') ?>"<?= selected($v('destination_country', 'CI'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="destination_city">Ville d’arrivée</label><input id="destination_city" name="destination_city" value="<?= $v('destination_city', 'Abidjan') ?>"></div>
      </div>
      <?php if (!$editing && isset($openShipments)): $chosenShip = (string) old('shipment_id', ''); ?>
      <div class="field flight-pick" data-flight-pick>
        <span class="label" id="flight-pick-label">Vol <span class="muted">(facultatif)</span></span>
        <div class="flight-options" role="radiogroup" aria-labelledby="flight-pick-label">
          <label class="flight-option"><input type="radio" name="shipment_id" value=""<?= $chosenShip === '' ? ' checked' : '' ?>><span><strong>Plus tard</strong><small>Le colis attend au comptoir</small></span></label>
          <?php foreach ($openShipments as $os): ?>
            <label class="flight-option" data-destination="<?= e($os['destination_country']) ?>"><input type="radio" name="shipment_id" value="<?= (int) $os['id'] ?>"<?= $chosenShip === (string) $os['id'] ? ' checked' : '' ?>><span><strong class="code"><?= e($os['reference']) ?></strong><small><?= e(trim(($os['airline'] ?? '') . ' ' . ($os['flight_number'] ?? '')) ?: 'Vol à préciser') ?><?= $os['planned_departure'] ? ' · départ ' . e(dt($os['planned_departure'], 'd/m H:i')) : '' ?> · <?= (int) $os['parcel_count'] ?> colis</small></span></label>
          <?php endforeach ?>
        </div>
        <span class="hint" data-flight-empty hidden>Aucun vol en préparation vers cette destination : le colis attendra au comptoir.</span>
        <span class="hint">Seuls les vols en préparation vers la destination choisie sont proposés.</span>
      </div>
      <?php endif ?>
    </fieldset>
  </div>

  <div class="panel">
    <fieldset style="margin-bottom:0">
      <legend>Tarif</legend>
      <?php if ($billingLocked): ?>
        <div class="flash flash-info"><?= icon('invoice') ?><span>Ce colis est facturé : poids, tarif et devise sont figés. Retirez-le du brouillon ou annulez la facture pour les modifier.</span></div>
      <?php endif ?>
      <div class="field">
        <label for="tariff_id">Grille tarifaire</label>
        <select id="tariff_id" name="tariff_id"<?= $billingLocked ? ' disabled' : '' ?>>
          <option value="">Tarif libre</option>
          <?php foreach ($tariffs as $t): ?>
            <option value="<?= (int) $t['id'] ?>" data-rate="<?= e(Money::input((int) $t['rate_per_kg_minor'], $t['currency'])) ?>" data-currency="<?= e($t['currency']) ?>" data-min="<?= (int) $t['min_billable_g'] ?>"<?= selected($v('tariff_id'), $t['id']) ?>><?= e($t['name']) ?> : <?= e(money((int) $t['rate_per_kg_minor'], $t['currency'])) ?>/kg</option>
          <?php endforeach ?>
        </select>
        <?php if ($billingLocked): ?><input type="hidden" name="tariff_id" value="<?= e((string) ($p['tariff_id'] ?? '')) ?>"><?php endif ?>
      </div>
      <div class="row row-2">
        <div class="field"><label for="rate">Prix par kilogramme</label><input id="rate" name="rate" inputmode="decimal" value="<?= old('rate', isset($p['rate_per_kg_minor']) ? Money::input((int) $p['rate_per_kg_minor'], $p['currency']) : '') ?>"<?= invalid('rate') ?><?= ($billingLocked || !can('parcels.finance')) ? ' readonly' : '' ?>><?= field_error('rate') ?><?php if (!can('parcels.finance')): ?><span class="hint">Le prix de la grille choisie s’applique.</span><?php endif ?></div>
        <div class="field"><label for="currency">Devise</label>
          <select id="currency" name="currency"<?= $billingLocked ? ' disabled' : '' ?>><?php foreach (Domain::CURRENCIES as $k => $cfg): ?><option value="<?= $k ?>" data-exp="<?= $cfg['exp'] ?>"<?= selected($currency, $k) ?>><?= e($cfg['label']) ?></option><?php endforeach ?></select>
          <?php if ($billingLocked): ?><input type="hidden" name="currency" value="<?= e($currency) ?>"><?php endif ?>
          <?= field_error('currency') ?>
        </div>
      </div>
      <div class="calc" data-calc data-step="<?= App\Core\Config::int('BILLING_WEIGHT_STEP_G', 100) ?>" aria-live="polite">
        <span>Poids facturé <strong data-calc-weight>—</strong></span>
        <span>Montant <strong data-calc-amount>—</strong></span>
        <span class="small muted">Calcul définitif fait à l’enregistrement.</span>
      </div>
      <div class="field"><label for="payment_terms">Modalité de paiement</label><select id="payment_terms" name="payment_terms"><option value="">Selon la fiche client</option><?php foreach (Domain::PAYMENT_TERMS as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('payment_terms'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    </fieldset>
  </div>

  <div class="panel">
    <fieldset style="margin-bottom:0">
      <legend>Compléments</legend>
      <div class="field"><label for="received_at">Reçu au comptoir le</label><input id="received_at" name="received_at" type="datetime-local" value="<?= old('received_at', utc_to_local_input($p['received_at'] ?? null)) ?>"><span class="hint">Laissez vide pour maintenant.</span></div>
      <label class="check"><input type="checkbox" name="public_weight" value="1"<?= checked((bool) old('public_weight', $editing ? (string) $p['public_weight'] : '1')) ?>> Afficher le poids sur la page publique de suivi</label>
      <div class="field"><label for="internal_notes">Observations internes</label><textarea id="internal_notes" name="internal_notes" placeholder="État de l’emballage, objets fragiles…"><?= $v('internal_notes') ?></textarea><span class="hint">Jamais visibles par le client.</span></div>
      <?php if (!$editing): ?>
      <div class="field"><label for="attachment">Photo ou document <span class="muted">(facultatif)</span></label><input id="attachment" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"><span class="hint">JPG, PNG, WebP ou PDF, 5 Mo maximum.</span></div>
      <?php endif ?>
    </fieldset>
  </div>

  <div class="form-actions sticky" style="margin-top:16px">
    <button class="btn btn-primary"><?= $editing ? 'Enregistrer les modifications' : 'Enregistrer le colis' ?></button>
    <a class="btn btn-quiet" href="<?= e(url($editing ? 'colis/' . $p['id'] : 'colis')) ?>">Annuler</a>
  </div>
</form>
