/* Page publique de suivi : mise en forme du code saisi et service worker. */
(function () {
  'use strict';
  var input = document.getElementById('code');
  if (input) {
    input.addEventListener('input', function () {
      var pos = input.selectionStart, before = input.value.length;
      input.value = input.value.toUpperCase();
      var d = input.value.length - before;
      try { input.setSelectionRange(pos + d, pos + d); } catch (e) {}
    });
  }
  if ('serviceWorker' in navigator) {
    var base = location.pathname.replace(/\/suivi.*$/, '/');
    navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () {});
  }
})();
