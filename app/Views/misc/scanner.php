<div class="page-head"><h1>Scanner un colis</h1><p class="lede">Visez le code-barres de l’étiquette, ou saisissez le code de suivi.</p></div>
<div class="grid grid-2">
  <div>
    <div class="scanner" id="scanner" hidden>
      <video id="scan-video" playsinline muted></video>
      <div class="frame" aria-hidden="true"></div>
    </div>
    <div class="panel" id="scan-unsupported" hidden>
      <p><strong>La caméra ne peut pas lire les codes sur cet appareil.</strong></p>
      <p class="muted small">La lecture automatique fonctionne avec Chrome sur Android. Sur les autres navigateurs, utilisez la saisie manuelle ou une douchette USB/Bluetooth : elle se comporte comme un clavier dans le champ ci-contre.</p>
    </div>
    <button class="btn btn-go btn-block" id="scan-start" type="button" style="margin-top:12px"><?= icon('camera') ?>Ouvrir la caméra</button>
    <p class="small muted" id="scan-status" role="status" aria-live="polite" style="margin-top:8px"></p>
  </div>
  <form class="panel" id="code-form" data-lookup="<?= e(url('api/colis/recherche-code')) ?>">
    <div class="field">
      <label for="code">Code de suivi</label>
      <input id="code" name="code" autocomplete="off" autocapitalize="characters" placeholder="DI-XXXX-XXXX" class="code" style="font-size:1.3rem" autofocus>
      <span class="hint">Les tirets sont facultatifs.</span>
    </div>
    <button class="btn btn-primary btn-block">Ouvrir la fiche du colis</button>
    <p class="flash flash-error" id="code-error" hidden style="margin-top:12px"></p>
  </form>
</div>
