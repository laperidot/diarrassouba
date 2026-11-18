<?php
use App\Core\Config;
use App\Core\Domain;
use App\Core\Money;
use App\Core\Settings;
$app = Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT');
$phone = Settings::get('COMPANY_PHONE');
$mail = Settings::get('COMPANY_EMAIL');
$roleLabel = ['sender' => 'expéditeur', 'recipient' => 'destinataire', 'both' => 'expéditeur et destinataire'][$role] ?? '';
$today = $st['today'];
?><!doctype html>
<html lang="fr" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Relevé de paiement <?= e($client['code']) ?> | <?= e($app) ?></title>
<meta name="theme-color" content="#121419">
<link rel="icon" href="<?= e(url('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="sh-body">
<main class="sh-card">
  <header class="sh-head">
    <span class="sh-logo"><img src="<?= e(url('assets/icons/icon-192.png')) ?>" alt="" width="64" height="64"></span>
    <h1><?= e($app) ?></h1>
    <p>Relevé de paiement client</p>
    <span class="sh-pill"><?= e($client['code']) ?></span>
  </header>

  <div class="sh-body-in">
    <section class="sh-client">
      <p class="sh-label">Client<?= $roleLabel ? ' (' . e($roleLabel) . ')' : '' ?></p>
      <p class="sh-name"><?= e($client['name']) ?></p>
      <?php if ($client['phone']): ?><p class="sh-phone"><?= e($client['phone']) ?></p><?php endif ?>
    </section>

    <?php foreach ($st['accounts'] as $cur => $a): $pct = $a['invoiced'] > 0 ? max(0, min(100, (int) round($a['settled'] * 100 / $a['invoiced']))) : 100; ?>
    <section class="sh-tiles" aria-label="Synthèse en <?= e(Domain::CURRENCIES[$cur]['label'] ?? $cur) ?>">
      <div class="sh-tile is-total"><span>Total</span><strong><?= e(Money::format($a['invoiced'], $cur)) ?></strong></div>
      <div class="sh-tile is-paid"><span>Réglé</span><strong><?= e(Money::format($a['settled'], $cur)) ?></strong></div>
      <div class="sh-tile is-due"><span><?= $a['balance'] < 0 ? 'En votre faveur' : 'Reste' ?></span><strong><?= e(Money::format(abs($a['balance']), $cur)) ?></strong></div>
    </section>
    <div class="sh-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Part réglée : <?= $pct ?> %"><span style="width:<?= $pct ?>%"></span></div>
    <?php if ($a['overdue'] > 0): ?><p class="sh-alert">Dont <?= e(Money::format($a['overdue'], $cur)) ?> arrivé(s) à échéance : merci de régulariser.</p><?php endif ?>
    <?php endforeach ?>

    <h2 class="sh-h2">Historique des versements</h2>
    <?php if (!$payments): ?>
      <p class="sh-empty">Aucun versement enregistré</p>
    <?php else: ?>
    <table class="sh-table">
      <thead><tr><th>Date</th><th>Mode</th><th class="num">Montant</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): $neg = (int) $p['amount_minor'] < 0; ?>
        <tr<?= $neg ? ' class="is-reversal"' : '' ?>>
          <td><?= e(dt($p['paid_at'], 'd/m/Y')) ?></td>
          <td><?= e($neg ? 'Annulation' : (Domain::PAYMENT_METHODS[$p['method']] ?? $p['method'])) ?><small><?= e($p['receipt_number']) ?></small></td>
          <td class="num"><?= e(Money::format((int) $p['amount_minor'], $p['currency'])) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>

    <?php if ($invoices): ?>
    <h2 class="sh-h2">Factures</h2>
    <ul class="sh-invoices">
      <?php foreach ($invoices as $i):
          $open = in_array($i['status'], ['validated', 'partial'], true);
          $due = $open ? (int) $i['total_minor'] - (int) $i['paid_minor'] : 0;
          $late = $open && $i['due_date'] && $i['due_date'] < $today; ?>
        <li>
          <div><strong><?= e($i['number']) ?></strong><small>Émise le <?= e(d($i['issue_date'])) ?><?= $i['codes'] ? ' · Colis : ' . e(str_replace(',', ', ', $i['codes'])) : '' ?></small></div>
          <div class="sh-inv-amount">
            <?= e(Money::format((int) $i['total_minor'], $i['currency'])) ?>
            <?php if ($due > 0): ?><span class="sh-tag <?= $late ? 'is-late' : 'is-open' ?>"><?= $late ? 'échue' : 'reste' ?> <?= e(Money::format($due, $i['currency'])) ?></span>
            <?php elseif ($i['status'] === 'paid'): ?><span class="sh-tag is-ok">payée</span>
            <?php else: ?><span class="sh-tag"><?= e(Domain::INVOICE_STATUSES[$i['status']] ?? $i['status']) ?></span><?php endif ?>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>

    <?php if ($pay = Settings::get('COMPANY_PAYMENT_INFO')): ?><p class="sh-note"><strong>Pour régler :</strong> <?= nl2br(e($pay)) ?></p><?php endif ?>
    <p class="sh-meta">Compte <strong><?= e($client['code']) ?></strong> · Émis le <?= e(dt(gmdate('Y-m-d H:i:s'))) ?></p>
    <a class="sh-btn" href="<?= e($pdfUrl) ?>">Télécharger le relevé (PDF)</a>
  </div>

  <footer class="sh-foot">
    <?php if ($phone): ?><div><span>Contact</span><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></div><?php endif ?>
    <?php if ($mail): ?><div class="end"><span>E-mail</span><a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a></div><?php endif ?>
  </footer>
</main>
</body>
</html>
