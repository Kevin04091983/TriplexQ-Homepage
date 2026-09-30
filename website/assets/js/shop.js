/* TRIPLEX-Q – Shop: Filter nach Produktlinie, Suche, Durchmesser, Sortierung */
(function () {
  "use strict";
  const K = window.TQ_KATALOG;
  const raster = document.querySelector("[data-produkte]");
  if (!K || !raster) return;

  const karten = Array.from(raster.querySelectorAll(".pkarte"));
  const daten = new Map();
  karten.forEach((k, i) => {
    const href = k.querySelector("a").getAttribute("href");
    const id = href.split("/").pop().replace(".html", "");
    const p = K.produkte.find((x) => x.id === id);
    daten.set(k, Object.assign({ index: i }, p));
  });

  const suche = document.querySelector("[data-suche]");
  const dFeld = document.querySelector("[data-durchmesser]");
  const sort = document.querySelector("[data-sortierung]");
  const chips = Array.from(document.querySelectorAll("[data-filter]"));
  const treffer = document.querySelector("[data-treffer]");
  const leer = document.querySelector("[data-leer]");
  const linienIds = K.linien.map((l) => l.id);
  let linie = linienIds.includes(location.hash.slice(1)) ? location.hash.slice(1) : "alle";

  function normal(t) {
    return (t || "").toLowerCase().replace(/ø/g, "").replace(/ä/g, "ae").replace(/ö/g, "oe").replace(/ü/g, "ue").replace(/ß/g, "ss");
  }

  function anwenden() {
    const worte = normal(suche.value).split(/\s+/).filter(Boolean);
    const d = parseFloat((dFeld.value || "").replace(",", "."));
    let sichtbar = 0;
    karten.forEach((k) => {
      const p = daten.get(k);
      const passtLinie = linie === "alle" || p.linien.includes(linie);
      const text = normal(p.suche);
      const passtSuche = worte.every((w) => text.includes(w) || text.includes(w.replace(",", ".")));
      const passtD = isNaN(d) || p.d.some((x) => Math.abs(x - d) < 0.001) || (!p.d.length && p.d_min <= d && d <= p.d_max);
      const zeigen = passtLinie && passtSuche && passtD;
      k.classList.toggle("weg", !zeigen);
      if (zeigen) sichtbar++;
    });
    const nachWert = {
      "preis-auf": (a, b) => (a.preis_ab ?? 1e9) - (b.preis_ab ?? 1e9),
      "preis-ab": (a, b) => (b.preis_ab ?? 0) - (a.preis_ab ?? 0),
      name: (a, b) => a.kurz.localeCompare(b.kurz, "de"),
      empfehlung: (a, b) => a.index - b.index,
    }[sort.value];
    karten.slice().sort((a, b) => nachWert(daten.get(a), daten.get(b))).forEach((k) => raster.appendChild(k));
    chips.forEach((c) => c.classList.toggle("aktiv", c.dataset.filter === linie));
    const name = linie === "alle" ? "" : " in „" + K.linien.find((l) => l.id === linie).name + "“";
    treffer.textContent = sichtbar + (sichtbar === 1 ? " Werkzeug" : " Werkzeuge") + name + (isNaN(d) ? "" : " mit Ø " + String(d).replace(".", ",") + " mm");
    leer.hidden = sichtbar > 0;
  }

  // geänderte Preise: Sortierung "Preis" auf die aktuellen Werte umstellen
  document.addEventListener("preise", () => {
    const pp = window.TQ.Preise.produkte || {};
    daten.forEach((p) => { if (pp[p.id]) { p.preis_ab = pp[p.id][0]; p.preis_bis = pp[p.id][1]; } });
    anwenden();
  });

  document.addEventListener("konto", () => {
    const r = window.TQ.Konto.rabatt();
    let hinweis = document.querySelector("[data-rabatt-hinweis]");
    if (r <= 0) { if (hinweis) hinweis.remove(); return; }
    if (!hinweis) {
      hinweis = document.createElement("p");
      hinweis.dataset.rabattHinweis = "";
      hinweis.className = "hinweis-box";
      treffer.after(hinweis);
    }
    hinweis.textContent = "Angemeldet: Auf alle Preise erhalten Sie " + r.toLocaleString("de-DE", { maximumFractionDigits: 2 })
      + " % Kundenrabatt. Er wird auf der Produktseite und im Warenkorb abgezogen.";
  });

  chips.forEach((c) => c.addEventListener("click", () => {
    linie = c.dataset.filter;
    history.replaceState(null, "", linie === "alle" ? location.pathname : "#" + linie);
    anwenden();
  }));
  window.addEventListener("hashchange", () => {
    const h = location.hash.slice(1);
    if (linienIds.includes(h)) { linie = h; anwenden(); }
    if (h === "suche") suche.focus();
  });
  [suche, dFeld].forEach((f) => f.addEventListener("input", anwenden));
  sort.addEventListener("change", anwenden);
  if (location.hash === "#suche") setTimeout(() => suche.focus(), 200);
  const q = new URLSearchParams(location.search).get("q");
  if (q) suche.value = q;
  anwenden();
})();
