<?php
use App\Core\Config;
use App\Core\Domain;

$phone = App\Core\Settings::get('COMPANY_PHONE');
$depot = App\Core\Settings::get('COMPANY_DEPOT_INFO');
$err = !empty($formatError) ? 'format' : null;
?>
<div class="public-top">
  <div class="inner">
    <div class="public-bar"><a class="brand" href="<?= e(url('suivi')) ?>"><span class="brand-name">DIARRASSOUBA</span><span class="brand-sub">Import-Export</span></a><?= theme_toggle() ?></div>
    <p class="eyebrow">Suivi de fret · Le Caire → Afrique de l’Ouest</p>
    <h1>Où en est votre colis ?</h1>
    <p>Saisissez le code inscrit sur votre reçu ou sous le code-barres de l’étiquette.</p>
  </div>
</div>
<main class="public-main">
  <form class="track-form" method="get" action="<?= e(url('suivi')) ?>" role="search">
    <label class="sr-only" for="code">Code de suivi</label>
    <input id="code" name="code" placeholder="DI-XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" value="<?= e($code ?? '') ?>" required>
    <button class="btn btn-primary"><?= icon('search') ?><span>Suivre</span></button>
  </form>

  <?php if ($state === 'initial' && $err === 'format'): ?>
    <div class="boarding"><div class="boarding-top">
      <h2>Ce code n’a pas le bon format</h2>
      <p class="muted" style="margin-top:6px">Un code de suivi ressemble à <strong class="code">DI-7K3M-Q9XA</strong> : les lettres DI suivies de 8 caractères. Vérifiez-le sur votre reçu.</p>
    </div></div>
  <?php elseif ($state === 'initial'): ?>
    <div class="boarding"><div class="boarding-top">
      <h2>Le trajet de votre colis</h2>
      <p class="muted" style="margin:6px 0 18px">Chaque étape est enregistrée par notre équipe, au départ comme à l’arrivée.</p>
      <?= parcel_rail('', false) ?>
    </div></div>
  <?php elseif ($state === 'unknown'): ?>
    <div class="boarding"><div class="boarding-top">
      <h2>Aucun colis ne correspond à ce code</h2>
      <p class="muted" style="margin-top:6px">Vérifiez chaque caractère (le chiffre 0 et la lettre O sont équivalents). Un colis enregistré aujourd’hui peut mettre quelques minutes à apparaître.<?php if ($phone): ?> Besoin d’aide : <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a>.<?php endif ?></p>
    </div></div>
  <?php elseif ($state === 'limited'): ?>
    <div class="boarding"><div class="boarding-top">
      <h2>Trop de recherches en peu de temps</h2>
      <p class="muted" style="margin-top:6px">Pour protéger les informations de nos clients, les recherches sont limitées. Réessayez dans une dizaine de minutes.</p>
    </div></div>
  <?php elseif ($state === 'error'): ?>
    <div class="boarding"><div class="boarding-top">
      <h2>Le suivi est momentanément indisponible</h2>
      <p class="muted" style="margin-top:6px">Votre colis n’est pas concerné : seul l’affichage est en panne. Réessayez dans quelques minutes.</p>
      <a class="btn" href="<?= e(url('suivi/' . $code)) ?>" style="margin-top:8px">Réessayer</a>
    </div></div>
  <?php else:
    $p = $parcel;
    $from = Domain::iata($p['origin_city'], $p['origin_country']);
    $to = Domain::iata($p['destination_city'], $p['destination_country']);
    $instructions = match ($p['status']) {
        'registered', 'awaiting' => 'Votre colis est enregistré à notre comptoir et sera placé sur un prochain vol.',
        'shipped', 'in_transit' => 'Votre colis est en vol. Vous serez prévenu dès son arrivée.',
        'arrived' => 'Le vol est arrivé. Le colis est en cours de dédouanement et de tri ; il sera bientôt disponible au dépôt.',
        'at_depot' => 'Votre colis vous attend au dépôt. Présentez ce code de suivi et une pièce d’identité au nom du destinataire.' . ($depot ? ' ' . $depot : ''),
        'out_for_delivery' => 'Un livreur est en route. Gardez votre téléphone à portée de main.',
        'delivered' => 'Votre colis a été remis. Merci de votre confiance.',
        default => 'Notre équipe traite une situation particulière sur ce colis. Contactez-nous' . ($phone ? ' au ' . $phone : '') . ' en indiquant votre code de suivi.',
    };
  ?>
    <article class="boarding" aria-labelledby="tc">
      <div class="boarding-top">
        <div style="display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;justify-content:space-between">
          <div>
            <div class="small muted">Code de suivi</div>
            <div class="tracking-big" id="tc"><?= e($p['tracking_code']) ?></div>
          </div>
          <?= status_badge($p['status']) ?>
        </div>
        <div class="route" style="margin-top:18px" aria-label="Trajet <?= e($from) ?> vers <?= e($to) ?>">
          <span class="iata"><?= e($from) ?></span><span class="leg"><?= icon('plane') ?></span><span class="iata"><?= e($to) ?></span>
        </div>
        <div class="route-cities"><span><?= e($p['origin_city'] ?: country_name($p['origin_country'])) ?></span><span><?= e($p['destination_city'] ?: country_name($p['destination_country'])) ?></span></div>
      </div>
      <div class="boarding-perf" aria-hidden="true"></div>
      <div class="boarding-bottom">
        <?= parcel_rail($p['status']) ?>
        <p style="margin:18px 0 16px"><?= e($instructions) ?></p>
        <dl class="dl">
          <dt>Pris en charge le</dt><dd><?= e(dt($p['received_at'], 'd/m/Y')) ?></dd>
          <?php if ($p['weight'] !== null): ?><dt>Poids</dt><dd class="num"><?= e(kg($p['weight'])) ?><?= $p['pieces'] > 1 ? ', ' . (int) $p['pieces'] . ' pièces' : '' ?></dd><?php endif ?>
          <?php if ($p['recipient']): ?><dt>Destinataire</dt><dd><?= e($p['recipient']) ?></dd><?php endif ?>
          <?php if ($p['flight']): ?><dt>Vol</dt><dd class="code"><?= e($p['flight']) ?></dd><?php endif ?>
          <?php if ($p['eta']): ?><dt>Arrivée prévue</dt><dd><?= e(dt($p['eta'], 'd/m/Y')) ?> (estimation)</dd><?php endif ?>
        </dl>
      </div>
    </article>

    <section class="panel" style="margin-top:16px" aria-labelledby="hist">
      <h2 id="hist" style="margin-bottom:14px">Historique</h2>
      <?php if (!$events): ?>
        <p class="muted">Aucun événement n’a encore été enregistré pour ce colis.</p>
      <?php else: ?>
      <ol class="timeline">
        <?php foreach ($events as $ev): ?>
          <li class="<?= $ev['alert'] ? 'alert' : '' ?>">
            <div class="t"><?= e($ev['label']) ?></div>
            <div class="m"><?= e(dt($ev['occurred_at'], 'd/m/Y à H:i')) ?><?= $ev['location'] ? ', ' . e($ev['location']) : '' ?></div>
            <?php if ($ev['comment']): ?><div class="c"><?= e($ev['comment']) ?></div><?php endif ?>
          </li>
        <?php endforeach ?>
      </ol>
      <?php endif ?>
    </section>
    <p class="small muted" style="margin-top:12px">Heures affichées à l’heure d’Abidjan. Pour toute question, munissez-vous de votre code de suivi<?= $phone ? ' et appelez le ' . e($phone) : '' ?>.</p>
  <?php endif ?>
</main>
