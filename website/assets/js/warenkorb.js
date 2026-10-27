/* TRIPLEX-Q – Warenkorb und Bestellung auf Rechnung */
(function () {
  "use strict";
  if (!window.TQ) return;
  const { Warenkorb, euro, basis, sende, pruefen, Konto, Preise } = window.TQ;
  const SHOP = window.TQ_SHOP || { versand: 9.5, mwst: 0.19, order: "order@wkz-schmidt.de" };
  const PREISE = window.TQ_PREISE || {};
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const liste = $("[data-positionen]");
  const leer = $("[data-leer]");
  const form = $('[data-formular="bestellung"]');
  const summe = $("[data-summe]");
  const status = $("[data-status]", summe);

  /* Preise und Namen mit aktuellem Katalog abgleichen */
  function abgleichen() {
    let geaendert = false;
    const pos = Warenkorb.lesen().filter((p) => {
      const k = PREISE[p.sku];
      const preis = k ? Preise.von(p.sku, k[0]) : null;   // inkl. Änderungen aus der Verwaltung
      if (!k || preis == null) { geaendert = true; return false; }
      if (p.preis !== preis) { p.preis = preis; geaendert = true; }
      p.id = k[1]; p.name = k[2]; p.auswahl = k[3]; p.bild = k[4];
      p.menge = Math.max(1, Math.min(999, parseInt(p.menge, 10) || 1));
      return true;
    });
    if (geaendert) Warenkorb.schreiben(pos);
    return pos;
  }

  const abholung = () => form.querySelector('input[name="versand"]:checked').value === "abholung";
  const ausland = () => !!form.land && form.land.value !== "Deutschland";
  /* Deutschland 9,50 € (aus TQ_SHOP); Ausland wie im alten Shop "auf Anfrage" (wird mit der Bestätigung genannt) */
  function versandKosten() {
    return abholung() || ausland() ? 0 : SHOP.versand;
  }

  function rechnen(pos) {
    const liste = pos.reduce((s, p) => s + p.preis * p.menge, 0);
    const netto = pos.reduce((s, p) => s + Konto.preisFuer(p.sku, p.preis) * p.menge, 0);
    const ersparnis = Math.round((liste - netto) * 100) / 100;
    const versand = pos.length ? versandKosten() : 0;
    const mwst = Math.round((netto + versand) * SHOP.mwst * 100) / 100;
    return { liste, ersparnis, netto, versand, mwst, gesamt: netto + versand + mwst };
  }

  function zeichnen() {
    const pos = abgleichen();
    liste.innerHTML = "";
    leer.hidden = pos.length > 0;
    form.hidden = summe.hidden = pos.length === 0;
    pos.forEach((p) => {
      const z = document.createElement("div");
      z.className = "position";
      z.innerHTML = `
        <a class="position-bild" href="${basis}produkte/${p.id}.html"><img src="${basis}assets/img/produkte/${p.bild}" alt=""></a>
        <div>
          <h3><a href="${basis}produkte/${p.id}.html"></a></h3>
          <small class="pos-auswahl"></small>
          <small>Art.-Nr. <span class="pos-sku"></span> · ${Konto.preisFuer(p.sku, p.preis) < p.preis ? '<s>' + euro(p.preis) + '</s> ' : ''}${euro(Konto.preisFuer(p.sku, p.preis))} / Stück</small>
          <button class="entfernen" type="button">Entfernen</button>
        </div>
        <div class="menge"><button type="button" data-d="-1" aria-label="Menge verringern">−</button><input type="number" min="1" max="999" aria-label="Menge"><button type="button" data-d="1" aria-label="Menge erhöhen">+</button></div>
        <div class="position-preis">${euro(Konto.preisFuer(p.sku, p.preis) * p.menge)}<small>netto</small></div>`;
      z.querySelector("h3 a").textContent = p.name;
      z.querySelector(".pos-auswahl").textContent = p.auswahl || "";
      z.querySelector(".pos-sku").textContent = p.sku;
      const feld = z.querySelector("input");
      feld.value = p.menge;
      const setzen = (m) => {
        const alle = Warenkorb.lesen();
        const ziel = alle.find((x) => x.sku === p.sku);
        if (!ziel) return;
        ziel.menge = Math.max(1, Math.min(999, m));
        Warenkorb.schreiben(alle);
        zeichnen();
      };
      z.querySelectorAll("[data-d]").forEach((b) => b.addEventListener("click", () => setzen(p.menge + parseInt(b.dataset.d, 10))));
      feld.addEventListener("change", () => setzen(parseInt(feld.value, 10) || 1));
      z.querySelector(".entfernen").addEventListener("click", () => {
        Warenkorb.schreiben(Warenkorb.lesen().filter((x) => x.sku !== p.sku));
        zeichnen();
      });
      liste.appendChild(z);
    });
    const r = rechnen(pos);
    rabattzeile(r);
    $("[data-zwischensumme]").textContent = euro(r.netto);
    $("[data-versandkosten]").textContent = abholung() ? "kostenlos" : ausland() ? "auf Anfrage" : euro(r.versand);
    $("[data-mwst]").textContent = euro(r.mwst);
    $("[data-gesamt]").textContent = euro(r.gesamt);
    $("[data-ausland-hinweis]").hidden = !ausland() || abholung();
  }

  function rabattzeile(r) {
    let zeile = $("[data-rabattzeile]");
    if (r.ersparnis <= 0) { if (zeile) zeile.remove(); return; }
    if (!zeile) {
      zeile = document.createElement("div");
      zeile.dataset.rabattzeile = "";
      zeile.className = "rabattzeile";
      zeile.innerHTML = "<dt></dt><dd></dd>";
      $("[data-zwischensumme]").closest("div").after(zeile);
    }
    zeile.querySelector("dt").textContent = Konto.rabatt() > 0
      ? "Ihr Kundenrabatt " + Konto.rabatt().toLocaleString("de-DE", { maximumFractionDigits: 2 }) + " %"
      : "Ihre persönlichen Preise";
    zeile.querySelector("dd").textContent = "− " + euro(r.ersparnis);
  }

  /* Adressfelder aus dem Kundenkonto vorbelegen */
  function ausKonto() {
    const k = Konto.kunde;
    const hinweis = $("[data-konto-hinweis]");
    const gast = $("[data-gast-hinweis]");
    if (gast) gast.hidden = !!k;
    if (hinweis) {
      hinweis.hidden = !k;
      if (k) hinweis.querySelector("[data-konto-name]").textContent = k.firma || (k.vorname + " " + k.nachname);
    }
    if (!k) return;
    ["firma", "ustid", "vorname", "nachname", "strasse", "plz", "ort", "land", "email", "telefon"].forEach((n) => {
      if (form.elements[n] && !form.elements[n].value) form.elements[n].value = k[n] || "";
    });
    if (k.lieferadresse && form.elements.lieferadresse && !form.elements.lieferadresse.value) {
      form.elements.lieferadresse.value = k.lieferadresse;
      const check = $("[data-lieferadresse]");
      if (check) { check.checked = true; $("[data-lieferadresse-feld]").hidden = false; }
    }
    zeichnen();
  }
  document.addEventListener("konto", ausKonto);

  form.addEventListener("change", zeichnen);
  const lieferCheck = $("[data-lieferadresse]");
  lieferCheck.addEventListener("change", () => { $("[data-lieferadresse-feld]").hidden = !lieferCheck.checked; });

  function bestelltext(d, pos, r, nummer) {
    const zeilen = pos.map((p) => `${p.menge} x ${p.sku}  ${p.name}${p.auswahl ? " (" + p.auswahl + ")" : ""}  à ${euro(p.preis)}  = ${euro(p.preis * p.menge)}`);
    return [
      `BESTELLUNG ${nummer} über triplex-q.com`, "",
      "ARTIKEL", ...zeilen, "",
      `Zwischensumme netto: ${euro(r.netto)}`,
      d.versand === "abholung" ? "Versand: Selbstabholung" : ausland() ? "Versand ins Ausland: auf Anfrage" : `Versand: ${euro(r.versand)}`,
      `MwSt. 19 %: ${euro(r.mwst)}`,
      `Gesamt: ${euro(r.gesamt)}`, "",
      "RECHNUNGSADRESSE",
      d.firma, `${d.vorname} ${d.nachname}`, d.strasse, `${d.plz} ${d.ort}`, d.land,
      d.ustid ? "USt-IdNr.: " + d.ustid : "", "",
      `E-Mail: ${d.email}`, `Telefon: ${d.telefon || "-"}`,
      d.lieferadresse ? "\nLIEFERADRESSE\n" + d.lieferadresse : "",
      d.bemerkung ? "\nBEMERKUNG / BESTELLNUMMER\n" + d.bemerkung : "", "",
      "Zahlungsart: Rechnung",
    ].filter((z) => z !== undefined).join("\n");
  }

  $("[data-bestellen]").addEventListener("click", async () => {
    status.className = "formular-status";
    if (!pruefen(form)) {
      status.textContent = "Bitte füllen Sie die markierten Felder aus.";
      status.classList.add("fehler");
      return;
    }
    const d = Object.fromEntries(new FormData(form));
    if (d.website) return;
    const pos = abgleichen();
    if (!pos.length) return;
    const r = rechnen(pos);
    const jetzt = new Date();
    const nummer = "TQ-" + jetzt.toISOString().slice(2, 10).replace(/-/g, "") + "-" + String(jetzt.getHours()).padStart(2, "0") + String(jetzt.getMinutes()).padStart(2, "0") + String(jetzt.getSeconds()).padStart(2, "0");
    const knopf = $("[data-bestellen]");
    knopf.disabled = true;
    const weg = await sende("bestellung.php",
      { nummer, kunde: d, positionen: pos.map((p) => ({ sku: p.sku, menge: p.menge })) },
      "Bestellung " + nummer + " – " + (d.firma || d.vorname + " " + d.nachname),
      bestelltext(d, pos, r, nummer), SHOP.order);
    knopf.disabled = false;
    if (weg === "server") {
      Warenkorb.leeren();
      const serverNummer = (sende.antwort && sende.antwort.nummer) || nummer;   // Nummer wie in Mail und Verwaltung
      $("[data-danke-text]").textContent = `Ihre Bestellung ${serverNummer} ist bei uns eingegangen. Eine Bestätigung wurde an ${d.email} gesendet.`;
      $("[data-danke]").hidden = false;
      zeichnen();
    } else {
      status.classList.add("ok");
      status.textContent = "Ihr E-Mail-Programm wurde mit der fertigen Bestellung geöffnet. Bitte dort auf „Senden“ klicken – danach können Sie den Warenkorb leeren.";
      if (!$("[data-leeren]")) {
        const b = document.createElement("button");
        b.type = "button"; b.className = "btn btn-linie breit"; b.dataset.leeren = "";
        b.textContent = "Bestellung gesendet – Warenkorb leeren";
        b.addEventListener("click", () => { Warenkorb.leeren(); zeichnen(); b.remove(); status.textContent = ""; });
        status.after(b);
      }
    }
  });

  // erst zeichnen, wenn die aktuellen Preise vom Server da sind (sonst würden neu bepreiste Artikel herausfallen)
  function start() { zeichnen(); ausKonto(); }
  if (Preise.geladen) start();
  else document.addEventListener("preise", start, { once: true });
})();
