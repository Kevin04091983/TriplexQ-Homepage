/* Wächter: QR-Code für die Zwei-Faktor-Einrichtung (Bibliothek qrcode.js, MIT, lokal) */
document.querySelectorAll("[data-qr]").forEach(function (el) {
  var qr = qrcode(0, "M");
  qr.addData(el.getAttribute("data-qr"));
  qr.make();
  el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 3, scalable: true });
});
