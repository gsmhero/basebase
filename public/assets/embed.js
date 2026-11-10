/* basebase.ru widget helper: auto-resizes basebase iframes on the host page */
(function () {
  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || d.bb !== 'h' || typeof d.h !== 'number') return;
    var fr = document.getElementsByTagName('iframe');
    for (var i = 0; i < fr.length; i++) {
      if (fr[i].contentWindow === e.source) { fr[i].style.height = Math.min(Math.max(d.h, 120), 4000) + 'px'; }
    }
  });
})();
