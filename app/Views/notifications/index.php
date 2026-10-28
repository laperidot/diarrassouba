<?php use App\Services\NotificationService; ?>
<div class="page-head">
  <h1>Notifications</h1>
  <?php if (array_filter($items, fn ($n) => !$n['read_at'])): ?>
  <form method="post" action="<?= e(url('notifications/lues')) ?>"><?= csrf_field() ?><button class="btn btn-sm">Tout marquer comme lu</button></form>
  <?php endif ?>
</div>
<section class="panel">
  <?php if (!$items): ?>
    <p class="muted" style="margin:0">Aucune notification. Vous serez prévenu ici des incidents sur les colis et des paiements enregistrés.</p>
  <?php else: ?>
    <ul class="feed">
      <?php foreach ($items as $n):
        $link = match ($n['entity_type']) { 'parcel' => url('colis/' . $n['entity_id']), 'invoice' => url('factures/' . $n['entity_id']), default => null }; ?>
        <li style="<?= $n['read_at'] ? '' : 'font-weight:600' ?>">
          <span><?php if ($link): ?><a href="<?= e($link) ?>"><?= e($n['title']) ?></a><?php else: ?><?= e($n['title']) ?><?php endif ?><?= $n['body'] ? '<br><span class="small muted">' . e($n['body']) . '</span>' : '' ?></span>
          <span class="when"><?= e(ago($n['created_at'])) ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>
<?php if ($external !== []): ?>
<section class="panel flush" style="margin-top:16px">
  <div class="panel-head"><h2>Envois aux clients</h2><span class="muted small">Résultat réel de chaque tentative</span></div>
  <table class="table stacked">
    <thead><tr><th>Date</th><th>Client</th><th>Canal</th><th>Événement</th><th>Résultat</th></tr></thead>
    <tbody>
    <?php foreach ($external as $n):
      $tone = ['sent' => 'done', 'failed' => 'alert', 'not_configured' => 'muted'][$n['status']] ?? 'muted';
      $label = ['sent' => 'Envoyé', 'failed' => 'Échec', 'not_configured' => 'Canal non configuré'][$n['status']] ?? $n['status']; ?>
      <tr>
        <td data-label="Date"><?= e(dt($n['created_at'])) ?></td>
        <td class="primary" data-label="Client"><?= e($n['client_name'] ?? '—') ?></td>
        <td data-label="Canal"><?= e(['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'][$n['channel']] ?? $n['channel']) ?></td>
        <td data-label="Événement"><?= e(NotificationService::EVENTS[$n['event']] ?? $n['event']) ?></td>
        <td data-label="Résultat"><span class="status tone-<?= $tone ?>"><?= e($label) ?></span><?= $n['error'] ? '<br><span class="small muted">' . e($n['error']) . '</span>' : '' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>
