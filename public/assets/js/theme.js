/* Thème jour / nuit — chargé dans <head> sans « defer » pour éviter le flash de couleur.
 * Choix enregistré dans ce navigateur : « light », « dark » ou absent (= suivre le système).
 * Boutons : [data-theme-toggle] bascule ; [data-theme-set="auto|light|dark"] choisit. */
(function () {
  'use strict';
  var KEY = 'di-theme';
  var root = document.documentElement;
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function stored() {
    try { var v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : null; } catch (e) { return null; }
  }
  function save(v) {
    try { if (v) { localStorage.setItem(KEY, v); } else { localStorage.removeItem(KEY); } } catch (e) { /* navigation privée */ }
  }
  function effective() {
    return stored() || (media && media.matches ? 'dark' : 'light');
  }
  function apply() {
    var t = effective();
    root.setAttribute('data-theme', t);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) { meta.setAttribute('content', t === 'dark' ? '#0C0D10' : '#EEE9DF'); }
    var pref = stored() || 'auto';
    var buttons = document.querySelectorAll('[data-theme-set]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('aria-pressed', buttons[i].getAttribute('data-theme-set') === pref ? 'true' : 'false');
    }
    var toggles = document.querySelectorAll('[data-theme-toggle]');
    for (var j = 0; j < toggles.length; j++) {
      var label = t === 'dark' ? 'Passer en mode jour' : 'Passer en mode nuit';
      toggles[j].setAttribute('title', label);
      toggles[j].setAttribute('aria-label', label);
    }
  }

  apply();
  if (media) {
    var onChange = function () { if (!stored()) { apply(); } };
    if (media.addEventListener) { media.addEventListener('change', onChange); } else if (media.addListener) { media.addListener(onChange); }
  }
  // Autre onglet : même choix partout
  window.addEventListener('storage', function (e) { if (e.key === KEY) { apply(); } });

  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-theme-toggle], [data-theme-set]') : null;
    if (!el) { return; }
    e.preventDefault();
    if (el.hasAttribute('data-theme-toggle')) {
      save(effective() === 'dark' ? 'light' : 'dark');
    } else {
      var v = el.getAttribute('data-theme-set');
      save(v === 'auto' ? null : v);
    }
    apply();
  });

  document.addEventListener('DOMContentLoaded', function () {
    apply();
    // Transitions douces seulement après le premier affichage (pas d'animation au chargement)
    requestAnimationFrame(function () { root.classList.add('theme-ready'); });
  });
})();
