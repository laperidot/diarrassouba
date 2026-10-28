<?php
use App\Core\Domain;
use App\Core\Money;
$x = $expense ?? [];
$readonly = $expense && ($x['status'] !== 'active' || !can('expenses.edit'));
$v = fn ($k, $d = '') => old($k, $x[$k] ?? ($preset[$k] ?? $d));
$cur = old('currency', $x['currency'] ?? 'XOF');
$errors = App\Core\Session::errors();
?>
<div class="page-head">
  <div><a class="crumb" href="<?= e(url('depenses')) ?>">← Dépenses</a><h1><?= $expense ? e($x['description']) : 'Nouvelle dépense' ?></h1></div>
  <?php if ($expense && $x['status'] === 'cancelled'): ?><span class="status tone-muted">Annulée</span><?php endif ?>
</div>
<?php if ($expense && $x['status'] === 'cancelled'): ?><div class="flash flash-warning"><?= icon('alert') ?><span>Dépense annulée : <?= e($x['cancel_reason']) ?>. Elle n’entre plus dans les totaux.</span></div><?php endif ?>
<div class="grid grid-main">
<form class="panel form" method="post" enctype="multipart/form-data" action="<?= e(url($expense ? 'depenses/' . $x['id'] : 'depenses')) ?>" data-expense-form>
  <?= csrf_field() ?>
  <fieldset<?= $readonly ? ' disabled' : '' ?>>
    <?php if (isset($errors['similar'])): ?><label class="check flash flash-warning"><input type="checkbox" name="confirm_similar" value="1"> Il s’agit bien d’une autre dépense : l’enregistrer.</label><?php endif ?>
    <div class="row row-2">
      <div class="field"><label for="category">Catégorie</label><select id="category" name="category" required data-category<?= invalid('category') ?>><option value="">Choisir…</option><?php foreach (Domain::EXPENSE_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('category'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select><?= field_error('category') ?></div>
      <div class="field"><label for="shipment_id">Rattachement</label><select id="shipment_id" name="shipment_id"<?= invalid('shipment_id') ?>><option value="">Frais généraux (aucun dossier)</option><?php foreach ($shipments as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($v('shipment_id'), $s['id']) ?>><?= e($s['reference'] . ' ' . ($s['flight_number'] ?? '')) ?></option><?php endforeach ?></select><?= field_error('shipment_id') ?></div>
    </div>
    <div class="field"><label for="description">Libellé</label><input id="description" name="description" required value="<?= $v('description') ?>"<?= invalid('description') ?>><?= field_error('description') ?></div>
    <div data-ticket-fields>
      <p class="small muted" style="margin:0 0 8px">Billet d’avion d’un accompagnateur ou d’un agent. Le transport des colis s’enregistre en « Fret aérien ».</p>
      <div class="row row-2">
        <div class="field"><label for="ticket_reference">Référence du billet</label><input id="ticket_reference" name="ticket_reference" value="<?= $v('ticket_reference') ?>" autocapitalize="characters"<?= invalid('ticket_reference') ?>><?= field_error('ticket_reference') ?></div>
        <div class="field"><label for="airline">Compagnie</label><input id="airline" name="airline" value="<?= $v('airline') ?>"<?= invalid('airline') ?>><?= field_error('airline') ?></div>
      </div>
      <div class="field"><label for="beneficiary">Bénéficiaire (voyageur)</label><input id="beneficiary" name="beneficiary" value="<?= $v('beneficiary') ?>"></div>
    </div>
    <div class="field" data-supplier-field><label for="supplier_reference">N° de pièce / facture fournisseur <span class="muted">(évite les doublons)</span></label><input id="supplier_reference" name="supplier_reference" value="<?= $v('supplier_reference') ?>"<?= invalid('supplier_reference') ?>><?= field_error('supplier_reference') ?></div>
    <div class="row row-3">
      <div class="field"><label for="amount">Montant</label><input id="amount" name="amount" inputmode="decimal" required value="<?= old('amount', isset($x['amount_minor']) ? Money::input((int) $x['amount_minor'], $cur) : '') ?>"<?= invalid('amount') ?>><?= field_error('amount') ?></div>
      <div class="field"><label for="currency">Devise</label><select id="currency" name="currency"><?php foreach (Domain::CURRENCIES as $k => $c): ?><option value="<?= $k ?>"<?= selected($cur, $k) ?>><?= e($k) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="expense_date">Date</label><input id="expense_date" name="expense_date" type="date" required value="<?= $v('expense_date', (new DateTimeImmutable('now', local_tz()))->format('Y-m-d')) ?>"<?= invalid('expense_date') ?>><?= field_error('expense_date') ?></div>
    </div>
    <div class="field"><label for="payment_status">Statut de paiement</label><select id="payment_status" name="payment_status"><?php foreach (Domain::EXPENSE_PAYMENT_STATUS as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('payment_status', 'unpaid'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="attachment">Justificatif <span class="muted">(photo ou PDF)</span></label><input id="attachment" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></div>
    <?php if (!$readonly): ?><div class="form-actions sticky"><button class="btn btn-primary"><?= $expense ? 'Enregistrer' : 'Enregistrer la dépense' ?></button></div><?php endif ?>
  </fieldset>
</form>
<?php if ($expense): ?>
<aside class="stack">
  <section class="panel">
    <h2 style="margin-bottom:8px">Justificatifs</h2>
    <?php foreach ($attachments as $a): ?><div class="kv-line"><a href="<?= e(url('fichiers/depenses/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= e($a['original_name']) ?></a><span class="small muted"><?= e(dt($a['created_at'], 'd/m/Y')) ?></span></div><?php endforeach ?>
    <?php if (!$attachments): ?><p class="muted small" style="margin:0">Aucun justificatif joint.</p><?php endif ?>
  </section>
  <section class="panel small">
    <div class="kv-line"><span>Saisie par</span><span><?= e($x['created_by_name'] ?? '—') ?></span></div>
    <div class="kv-line"><span>Le</span><span><?= e(dt($x['created_at'])) ?></span></div>
  </section>
  <?php if ($x['status'] === 'active' && can('expenses.cancel')): ?>
  <details class="inline-form panel"><summary class="btn btn-danger btn-block">Annuler la dépense…</summary>
    <form method="post" action="<?= e(url('depenses/' . $x['id'] . '/annuler')) ?>"><?= csrf_field() ?>
      <div class="field"><label for="reason">Motif</label><input id="reason" name="reason" required minlength="5"></div>
      <button class="btn btn-danger btn-block">Confirmer</button>
    </form>
  </details>
  <?php endif ?>
</aside>
<?php endif ?>
</div>
