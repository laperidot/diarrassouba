<?php
use App\Core\Domain;
use App\Core\Money;

$cur = $inv['currency'];
$isDraft = $inv['status'] === 'draft';
$isCredit = $inv['kind'] === 'credit_note';
$open = !$isCredit && in_array($inv['status'], ['validated', 'partial'], true);
$balance = $open ? (int) $inv['total_minor'] - (int) $inv['paid_minor'] : 0;
$newReceipt = (int) ($_GET['recu'] ?? 0);
$editable = $isDraft && can('invoices.edit');
?>
<div class="page-head">
  <div>
    <a class="crumb" href="<?= e(url('factures')) ?>">← Factures</a>
    <h1><?= $isCredit ? 'Avoir' : 'Facture' ?> <?= e($inv['number'] ?? 'brouillon') ?></h1>
  </div>
  <?= status_badge($inv['status'], 'invoice') ?>
  <div class="actions" style="flex-basis:100%">
    <a class="btn" href="<?= e(url('factures/' . $inv['id'] . '/pdf')) ?>" target="_blank" rel="noopener"><?= icon('download') ?>PDF</a>
    <?php if (!$isDraft): ?><button class="btn" type="button" data-share-endpoint="<?= e(url('factures/' . $inv['id'] . '/partage')) ?>"><?= icon('share') ?>Partager</button><?php endif ?>
    <?php if ($editable): ?>
      <form method="post" action="<?= e(url('factures/' . $inv['id'] . '/valider')) ?>"><?= csrf_field() ?><button class="btn btn-go" data-confirm="Valider la facture ? Elle sera numérotée et ne pourra plus être modifiée."><?= icon('check') ?>Valider et numéroter</button></form>
      <form method="post" action="<?= e(url('factures/' . $inv['id'] . '/supprimer')) ?>"><?= csrf_field() ?><button class="btn btn-danger" data-confirm="Supprimer ce brouillon ? Les colis redeviendront facturables.">Supprimer le brouillon</button></form>
    <?php endif ?>
  </div>
</div>

<?php if ($newReceipt): ?>
  <div class="flash flash-success"><?= icon('receipt') ?><span>Reçu prêt : <a href="<?= e(url('paiements/' . $newReceipt . '/recu')) ?>" target="_blank" rel="noopener">ouvrir le PDF</a> ou <button type="button" class="btn-link" data-share-endpoint="<?= e(url('paiements/' . $newReceipt . '/partage')) ?>">le partager au client</button>.</span></div>
<?php endif ?>
<?php if ($isDraft): ?>
  <div class="flash flash-info"><?= icon('edit') ?><span>Brouillon non numéroté : il n’est pas exigible et ne compte pas dans le chiffre d’affaires. Les colis qu’il contient sont réservés (ils ne peuvent pas être facturés ailleurs).</span></div>
<?php endif ?>
<?php if (in_array($inv['status'], ['cancelled', 'credited'], true)): ?>
  <div class="flash flash-warning"><?= icon('alert') ?><span><?= $inv['status'] === 'credited' ? 'Annulée par avoir' : 'Annulée' ?> le <?= e(dt($inv['cancelled_at'], 'd/m/Y')) ?> : <?= e($inv['cancel_reason']) ?></span></div>
<?php endif ?>
<?php if ($isCredit && $inv['credit_of_invoice_id']): ?>
  <div class="flash flash-info"><?= icon('invoice') ?><span>Avoir sur la facture <a href="<?= e(url('factures/' . $inv['credit_of_invoice_id'])) ?>"><?= e($inv['credit_of_number']) ?></a>.</span></div>
<?php endif ?>

<div class="grid grid-main">
  <div class="stack">
    <form method="post" action="<?= e(url('factures/' . $inv['id'])) ?>" id="draft-form">
      <?= csrf_field() ?>
      <section class="panel flush">
        <div class="panel-head"><h2>Lignes</h2><span class="muted small"><?= count($items) ?> colis</span></div>
        <table class="table stacked">
          <thead><tr><th>Colis</th><th class="num">Poids facturé</th><th class="num">Prix / kg</th><th class="num">Transport</th><th>Frais supplémentaires</th><th class="num">Total ligne</th><?= $editable ? '<th></th>' : '' ?></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td class="primary" data-label="Colis"><?php if ($it['parcel_id']): ?><a class="row-link code" href="<?= e(url('colis/' . $it['parcel_id'])) ?>"><?= e($it['tracking_code']) ?></a><?php else: ?><span class="code"><?= e($it['tracking_code']) ?></span><?php endif ?><br><span class="small muted"><?= e($it['description']) ?></span></td>
              <td class="num" data-label="Poids facturé"><?= e(kg((int) $it['billable_weight_g'])) ?></td>
              <td class="num" data-label="Prix / kg"><?= e(money((int) $it['rate_per_kg_minor'], $cur)) ?></td>
              <td class="num" data-label="Transport"><?= e(money((int) $it['transport_minor'], $cur)) ?></td>
              <td data-label="Frais">
                <?php if ($editable): ?>
                  <div class="fee-inputs">
                    <input name="fees[<?= (int) $it['id'] ?>][amount]" inputmode="decimal" placeholder="0" value="<?= e(Money::input((int) $it['fees_minor'], $cur)) ?>" aria-label="Montant des frais">
                    <input name="fees[<?= (int) $it['id'] ?>][label]" placeholder="Emballage…" value="<?= e($it['fees_label'] ?? '') ?>" aria-label="Libellé des frais">
                  </div>
                <?php else: ?>
                  <?= (int) $it['fees_minor'] !== 0 ? e(($it['fees_label'] ?: 'Frais') . ' : ' . money((int) $it['fees_minor'], $cur)) : '—' ?>
                <?php endif ?>
              </td>
              <td class="num" data-label="Total ligne"><strong><?= e(money((int) $it['line_total_minor'], $cur)) ?></strong></td>
              <?php if ($editable): ?>
                <td class="num"><button class="btn btn-sm btn-quiet" form="rm-<?= (int) $it['id'] ?>" data-confirm="Retirer ce colis de la facture ?">Retirer</button></td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <div style="padding:16px 18px">
          <div class="totals">
            <div><span>Transport</span><span><?= e(money((int) $inv['subtotal_minor'], $cur)) ?></span></div>
            <?php if ((int) $inv['fees_minor'] !== 0): ?><div><span>Frais supplémentaires</span><span><?= e(money((int) $inv['fees_minor'], $cur)) ?></span></div><?php endif ?>
            <?php if ((int) $inv['tax_rate_bp'] > 0): ?><div><span>Taxe <?= e(str_replace('.', ',', (string) ($inv['tax_rate_bp'] / 100))) ?> %</span><span><?= e(money((int) $inv['tax_minor'], $cur)) ?></span></div><?php endif ?>
            <div class="grand"><span>Total</span><span><?= e(money((int) $inv['total_minor'], $cur)) ?></span></div>
            <?php if (!$isDraft && !$isCredit): ?>
              <div><span>Déjà payé</span><span><?= e(money((int) $inv['paid_minor'], $cur)) ?></span></div>
              <div class="<?= $balance > 0 ? 'due' : '' ?>"><span>Reste à payer</span><span><?= e(money($balance, $cur)) ?></span></div>
            <?php endif ?>
          </div>
        </div>
      </section>

      <?php if ($editable): ?>
      <section class="panel" style="margin-top:16px">
        <h2 style="margin-bottom:12px">Conditions</h2>
        <div class="row row-3">
          <div class="field"><label for="payment_terms">Modalité de paiement</label><select id="payment_terms" name="payment_terms" data-terms><option value="">Non précisée</option><?php foreach (Domain::PAYMENT_TERMS as $k => $l): ?><option value="<?= $k ?>"<?= selected($inv['payment_terms'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="due_date">Échéance</label><input id="due_date" name="due_date" type="date" value="<?= e($inv['due_date'] ?? '') ?>"<?= invalid('due_date') ?>><?= field_error('due_date') ?></div>
          <div class="field"><label for="tax_rate">Taxe (%)</label><input id="tax_rate" name="tax_rate" inputmode="decimal" value="<?= e(str_replace('.', ',', (string) ($inv['tax_rate_bp'] / 100))) ?>"<?= invalid('tax_rate') ?>><?= field_error('tax_rate') ?><span class="hint">0 si non applicable. À valider avec votre comptable.</span></div>
        </div>
        <div data-installments<?= in_array($inv['payment_terms'], ['installments', 'deposit'], true) ? '' : ' hidden' ?>>
          <p class="label" style="margin:4px 0 8px">Échéancier <span class="muted small">(la somme doit égaler le total)</span></p>
          <?php $rows = $installments ?: [[], []]; $rows[] = []; foreach ($rows as $i => $r): ?>
            <div class="row row-3">
              <div class="field"><label class="sr-only" for="ins-d-<?= $i ?>">Date</label><input id="ins-d-<?= $i ?>" type="date" name="installments[<?= $i ?>][due_date]" value="<?= e($r['due_date'] ?? '') ?>"></div>
              <div class="field"><label class="sr-only" for="ins-a-<?= $i ?>">Montant</label><input id="ins-a-<?= $i ?>" inputmode="decimal" name="installments[<?= $i ?>][amount]" placeholder="Montant" value="<?= isset($r['amount_minor']) ? e(Money::input((int) $r['amount_minor'], $cur)) : '' ?>"></div>
              <div class="field"><label class="sr-only" for="ins-l-<?= $i ?>">Libellé</label><input id="ins-l-<?= $i ?>" name="installments[<?= $i ?>][label]" placeholder="<?= $i === 0 ? 'Acompte' : 'Solde' ?>" value="<?= e($r['label'] ?? '') ?>"></div>
            </div>
          <?php endforeach ?>
          <?= field_error('installments') ?>
        </div>
        <div class="field"><label for="notes">Mention sur la facture</label><input id="notes" name="notes" maxlength="500" value="<?= e($inv['notes'] ?? '') ?>"></div>
        <button class="btn btn-primary">Enregistrer le brouillon</button>
      </section>
      <?php endif ?>
    </form>
    <?php if ($editable): foreach ($items as $it): ?>
      <form id="rm-<?= (int) $it['id'] ?>" method="post" action="<?= e(url('factures/' . $inv['id'] . '/lignes/' . $it['id'] . '/retirer')) ?>" hidden><?= csrf_field() ?></form>
    <?php endforeach; endif ?>

    <?php if (!$isDraft && !$isCredit): ?>
    <section class="panel flush" id="paiement">
      <div class="panel-head"><h2>Règlements</h2></div>
      <?php if (!$payments): ?>
        <div class="empty" style="padding:18px"><p>Aucun paiement enregistré.</p></div>
      <?php else: ?>
        <table class="table stacked">
          <thead><tr><th>Reçu</th><th>Date</th><th>Moyen</th><th class="num">Montant</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): $neg = (int) $p['amount_minor'] < 0; ?>
            <tr>
              <td class="primary" data-label="Reçu"><span class="code"><?= e($p['receipt_number']) ?></span><?= $p['reversed_by_id'] ? ' <span class="tag">annulé</span>' : '' ?><br><span class="small muted"><?= e($p['notes'] ?? '') ?></span></td>
              <td data-label="Date"><?= e(dt($p['paid_at'])) ?></td>
              <td data-label="Moyen"><?= e(Domain::PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? '<br><span class="small muted">' . e($p['reference']) . '</span>' : '' ?><br><span class="small muted"><?= $p['confirmation'] === 'provider' ? 'Confirmé par le prestataire' : 'Saisie manuelle, ' . e($p['recorded_by_name'] ?? '') ?></span></td>
              <td data-label="Montant" class="num"<?= $neg ? ' style="color:var(--coral-ink)"' : '' ?>><strong><?= e(money((int) $p['amount_minor'], $cur)) ?></strong></td>
              <td class="num">
                <div class="actions" style="justify-content:flex-end">
                  <a class="btn btn-sm" href="<?= e(url('paiements/' . $p['id'] . '/recu')) ?>" target="_blank" rel="noopener">Reçu</a>
                  <button class="btn btn-sm" type="button" data-share-endpoint="<?= e(url('paiements/' . $p['id'] . '/partage')) ?>" aria-label="Partager le reçu"><?= icon('share') ?></button>
                  <?php if (!$neg && !$p['reversed_by_id'] && can('payments.reverse')): ?>
                    <details class="inline-form"><summary class="btn btn-sm btn-quiet">Annuler…</summary>
                      <form method="post" action="<?= e(url('paiements/' . $p['id'] . '/annuler')) ?>" style="text-align:left;margin-top:8px">
                        <?= csrf_field() ?>
                        <div class="field"><label for="rev-<?= (int) $p['id'] ?>">Motif</label><input id="rev-<?= (int) $p['id'] ?>" name="reason" required minlength="5" placeholder="Erreur de saisie, remboursement…"></div>
                        <button class="btn btn-sm btn-danger">Confirmer l’annulation</button>
                      </form>
                    </details>
                  <?php endif ?>
                </div>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>
    <?php endif ?>
  </div>

  <aside class="stack">
    <?php if ($open && can('payments.record')): ?>
    <section class="panel">
      <h2 style="margin-bottom:12px">Enregistrer un paiement</h2>
      <form method="post" action="<?= e(url('factures/' . $inv['id'] . '/paiements')) ?>" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
        <div class="field"><label for="amount">Montant reçu</label><div class="input-suffix"><input id="amount" name="amount" inputmode="decimal" required value="<?= old('amount', Money::input($balance, $cur)) ?>"<?= invalid('amount') ?>><span><?= e($cur) ?></span></div><?= field_error('amount') ?><span class="hint">Reste à payer : <?= e(money($balance, $cur)) ?>. Un montant inférieur enregistre un paiement partiel.</span></div>
        <div class="field"><label for="method">Moyen de paiement</label><select id="method" name="method" required<?= invalid('method') ?>><option value="">Choisir…</option><?php foreach (Domain::PAYMENT_METHODS as $k => $l): ?><option value="<?= $k ?>"<?= selected(old('method'), $k) ?>><?= e($l) ?></option><?php endforeach ?></select><?= field_error('method') ?></div>
        <div class="field"><label for="reference">Référence de transaction</label><input id="reference" name="reference" value="<?= old('reference') ?>"<?= invalid('reference') ?>><?= field_error('reference') ?><span class="hint">Obligatoire sauf espèces (identifiant Mobile Money, n° de virement…).</span></div>
        <div class="field"><label for="paid_at">Date du paiement</label><input id="paid_at" name="paid_at" type="datetime-local" value="<?= old('paid_at') ?>"<?= invalid('paid_at') ?>><?= field_error('paid_at') ?><span class="hint">Vide = maintenant.</span></div>
        <div class="field"><label for="pay-notes">Note</label><input id="pay-notes" name="notes" maxlength="255" value="<?= old('notes') ?>"></div>
        <button class="btn btn-go btn-block"><?= icon('cash') ?>Enregistrer le paiement</button>
        <p class="small muted" style="margin:8px 0 0">Saisie manuelle : vérifiez la réception des fonds avant d’enregistrer.</p>
      </form>
    </section>
    <?php endif ?>

    <section class="panel">
      <dl class="dl">
        <dt>Client</dt><dd><a href="<?= e(url('clients/' . $inv['client_id'])) ?>"><?= e($inv['client_name']) ?></a></dd>
        <dt>Émise le</dt><dd><?= e(d($inv['issue_date'])) ?></dd>
        <?php if (!$isCredit): ?><dt>Échéance</dt><dd><?= e(d($inv['due_date'])) ?></dd><?php endif ?>
        <dt>Modalité</dt><dd><?= e(Domain::PAYMENT_TERMS[$inv['payment_terms'] ?? ''] ?? 'Non précisée') ?></dd>
        <?php if ($inv['shipment_reference']): ?><dt>Dossier</dt><dd><a href="<?= e(url('dossiers/' . $inv['shipment_id'])) ?>"><?= e($inv['shipment_reference']) ?></a></dd><?php endif ?>
        <dt>Devise</dt><dd><?= e(Domain::CURRENCIES[$cur]['label'] ?? $cur) ?></dd>
      </dl>
      <?php if ($installments && !$isDraft): ?>
        <hr><p class="label" style="margin-bottom:6px">Échéancier</p>
        <?php foreach ($installments as $ins): ?><div class="kv-line"><span><?= e(d($ins['due_date'])) ?> <?= e($ins['label'] ?? '') ?></span><span class="num"><?= e(money((int) $ins['amount_minor'], $cur)) ?></span></div><?php endforeach ?>
      <?php endif ?>
    </section>

    <?php if (!$isCredit && can('invoices.cancel') && in_array($inv['status'], ['validated', 'partial', 'paid'], true)): ?>
    <section class="panel">
      <h2 style="margin-bottom:8px">Corriger la facture</h2>
      <p class="small muted">Une facture validée ne se modifie pas : elle s’annule (sans paiement) ou se compense par un avoir. Les colis redeviennent alors facturables.</p>
      <?php if ((int) $inv['paid_minor'] === 0 && $inv['status'] === 'validated'): ?>
      <details class="inline-form"><summary class="btn btn-block btn-danger">Annuler la facture…</summary>
        <form method="post" action="<?= e(url('factures/' . $inv['id'] . '/annuler')) ?>"><?= csrf_field() ?>
          <div class="field"><label for="cancel-reason">Motif</label><input id="cancel-reason" name="reason" required minlength="5"<?= invalid('reason') ?>><?= field_error('reason') ?></div>
          <button class="btn btn-danger btn-block">Confirmer l’annulation</button>
        </form>
      </details>
      <?php endif ?>
      <details class="inline-form" style="margin-top:8px"><summary class="btn btn-block">Émettre un avoir…</summary>
        <form method="post" action="<?= e(url('factures/' . $inv['id'] . '/avoir')) ?>"><?= csrf_field() ?>
          <div class="field"><label for="credit-reason">Motif de l’avoir</label><input id="credit-reason" name="reason" required minlength="5"></div>
          <?php if ((int) $inv['paid_minor'] > 0): ?><p class="small flash flash-warning">Cette facture a reçu <?= e(money((int) $inv['paid_minor'], $cur)) ?>. Si vous remboursez le client, annulez aussi les paiements concernés.</p><?php endif ?>
          <button class="btn btn-block btn-primary">Émettre l’avoir</button>
        </form>
      </details>
    </section>
    <?php endif ?>
  </aside>
</div>
