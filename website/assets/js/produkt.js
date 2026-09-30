/* TRIPLEX-Q – Produktseite: Variantenauswahl, Preis, Warenkorb, Schnellbestellung */
(function () {
  "use strict";
  const quelle = document.getElementById("produkt-daten");
  if (!quelle || !window.TQ) return;
  const P = JSON.parse(quelle.textContent);
  const { Warenkorb, euro, toast, Konto, Preise } = window.TQ;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const gewaehlt = {};
  const preisEl = $("[data-preis]");
  const skuEl = $("[data-sku]");
  const hinweis = $("[data-hinweis]");
  const kaufen = $("[data-kaufen]");
  const mengeFeld = $("[data-menge-feld]");
  /* Preis ohne Auswahl: "ab …" aus den aktuell gültigen Preisen */
  function startPreis() {
    const preise = P.varianten.filter((v) => v.preis != null).map((v) => Konto.preisFuer(v.sku, v.preis));
    if (!preise.length) return "Preis auf Anfrage";
    const min = Math.min(...preise);
    return min === Math.max(...preise) ? euro(min) : "ab " + euro(min);
  }
  const startSku = skuEl ? skuEl.textContent : "";
  const felder = $$(".auswahl");

  function passend(auswahl, ohne) {
    return P.varianten.filter((v) => Object.entries(auswahl).every(([a, w]) => a === ohne || v.auswahl[a] === w));
  }

  function aktuelleVariante() {
    if (P.attribute.length === 0) return P.varianten[0];
    if (P.attribute.some((a) => !gewaehlt[a])) return null;
    return passend(gewaehlt)[0] || null;
  }

  function aktualisieren() {
    felder.forEach((fs) => {
      const attr = fs.dataset.attr;
      const moeglich = new Set(passend(gewaehlt, attr).map((v) => v.auswahl[attr]));
      $$(".wahl", fs).forEach((b) => {
        b.classList.toggle("aktiv", gewaehlt[attr] === b.dataset.wert);
        b.classList.toggle("aus", !moeglich.has(b.dataset.wert));
        b.setAttribute("aria-pressed", gewaehlt[attr] === b.dataset.wert);
      });
      $("[data-gewaehlt]", fs).textContent = gewaehlt[attr] ? "· " + gewaehlt[attr] : "";
    });
    const v = aktuelleVariante();
    hinweis.classList.remove("warn");
    if (v) {
      const rabatt = Konto.rabatt();
      const sonder = v.preis != null ? Konto.sonderpreis(v.sku) : null;
      const ihrPreis = v.preis != null ? Konto.preisFuer(v.sku, v.preis) : null;
      if (v.preis == null) {
        preisEl.textContent = "Preis auf Anfrage";
      } else if (ihrPreis < v.preis) {
        preisEl.innerHTML = euro(ihrPreis) + ' <s class="listenpreis">' + euro(v.preis) + "</s>";
      } else {
        preisEl.textContent = euro(ihrPreis);
      }
      preisEl.classList.add("aktiv");
      if (skuEl) skuEl.textContent = v.sku || startSku;
      hinweis.textContent = v.preis == null ? "Diese Ausführung bitte anfragen."
        : "Artikel-Nr. " + v.sku + " – verfügbar zur Bestellung"
          + (sonder != null ? " · Ihr persönlicher Preis" : rabatt > 0 ? " · inkl. Ihres Kundenrabatts von " + rabatt.toLocaleString("de-DE", { maximumFractionDigits: 2 }) + " %" : "");
      kaufen.disabled = v.preis == null;
    } else {
      preisEl.textContent = startPreis();
      preisEl.classList.remove("aktiv");
      if (skuEl) skuEl.textContent = startSku;
      const offen = P.attribute.filter((a) => !gewaehlt[a]).map((a) => (a === "Schneidendurchmesser" ? "Durchmesser" : a));
      const unmoeglich = P.attribute.every((a) => gewaehlt[a]);
      hinweis.textContent = unmoeglich ? "Diese Kombination gibt es nicht – bitte andere Auswahl treffen." : "Bitte wählen: " + offen.join(" und ");
      kaufen.disabled = false;
    }
  }

  felder.forEach((fs) => {
    const attr = fs.dataset.attr;
    $$(".wahl", fs).forEach((b) => b.addEventListener("click", () => {
      gewaehlt[attr] = gewaehlt[attr] === b.dataset.wert ? undefined : b.dataset.wert;
      // unmögliche Kombination: andere Auswahl zurücksetzen
      if (gewaehlt[attr] && passend(gewaehlt).length === 0) {
        P.attribute.forEach((a) => { if (a !== attr) gewaehlt[a] = undefined; });
      }
      aktualisieren();
    }));
    const filter = $("[data-wahl-filter]", fs);
    if (filter) {
      filter.addEventListener("input", () => {
        const q = filter.value.replace(".", ",").trim();
        $$(".wahl", fs).forEach((b) => b.classList.toggle("versteckt", q !== "" && !b.dataset.wert.startsWith(q)));
      });
    }
  });

  $$("[data-menge]").forEach((b) => b.addEventListener("click", () => {
    mengeFeld.value = Math.max(1, Math.min(999, (parseInt(mengeFeld.value, 10) || 1) + parseInt(b.dataset.menge, 10)));
  }));

  function inDenKorb(v, menge) {
    const label = Object.entries(v.auswahl).map(([a, w]) => (a === "Schneidendurchmesser" ? "Ø " + w : w)).join(" · ");
    Warenkorb.hinzu({ sku: v.sku, id: P.id, name: P.name, auswahl: label, preis: v.preis, bild: P.bild }, menge);
    toast(menge + " × " + P.name + (label ? " (" + label + ")" : "") + " im Warenkorb", true);
  }

  kaufen.addEventListener("click", () => {
    const v = aktuelleVariante();
    if (!v) {
      hinweis.classList.add("warn");
      const erstes = felder.find((fs) => !gewaehlt[fs.dataset.attr]);
      if (erstes) erstes.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    inDenKorb(v, Math.max(1, parseInt(mengeFeld.value, 10) || 1));
  });

  $$("[data-schnell]").forEach((b) => b.addEventListener("click", () => {
    const v = P.varianten.find((x) => x.sku === b.dataset.schnell);
    if (v) inDenKorb(v, 1);
  }));

  const tSuche = $("[data-tabellen-suche]");
  if (tSuche) {
    const zeilen = $$("[data-tabelle] tbody tr");
    tSuche.addEventListener("input", () => {
      const q = tSuche.value.toLowerCase().replace(".", ",").trim();
      zeilen.forEach((z) => z.classList.toggle("weg", q !== "" && !z.textContent.toLowerCase().replace(/\./g, ",").includes(q)));
    });
  }

  /* Galerie */
  const haupt = $("[data-hauptbild]");
  const buehne = $("[data-buehne]");
  $$(".thumb").forEach((t) => t.addEventListener("click", () => {
    $$(".thumb").forEach((x) => x.classList.toggle("aktiv", x === t));
    haupt.style.opacity = 0;
    setTimeout(() => {
      haupt.src = t.dataset.bild;
      buehne.className = "produkt-buehne buehne-" + t.dataset.art;
      haupt.style.opacity = 1;
    }, 180);
  }));

  /* ------------------------------------------------ Preise aus der Verwaltung übernehmen */
  function preiseAnwenden() {
    P.varianten.forEach((v) => { v.preis = Preise.von(v.sku, v.preis); });
    $$("[data-tabelle] tr[data-sku]").forEach((z) => {
      const v = P.varianten.find((x) => x.sku === z.dataset.sku);
      if (!v) return;
      const zelle = z.querySelector("[data-preis-zelle]");
      if (zelle) zelle.textContent = v.preis == null ? "auf Anfrage" : euro(v.preis);
      const knopf = z.querySelector("[data-schnell]");
      if (knopf) knopf.hidden = v.preis == null;
    });
    aktualisieren();
  }
  document.addEventListener("preise", preiseAnwenden);

  /* ------------------------------------------------ Admin: Preise direkt hier ändern */
  function adminFeld() {
    const vorhanden = $("[data-admin-preise]");
    const istAdmin = Konto.kunde && Konto.kunde.admin && Konto.csrfAdmin;
    if (!istAdmin) { if (vorhanden) vorhanden.remove(); return; }
    if (vorhanden) return;
    const box = document.createElement("section");
    box.className = "admin-preise karte";
    box.dataset.adminPreise = "";
    const viele = P.varianten.length > 12;
    box.innerHTML = `
      <header><span class="admin-marke">Admin</span><h2>Preise bearbeiten</h2>
        <small>Netto-Preise, gelten sofort für alle Kunden. Leeres Feld = „auf Anfrage“.</small></header>
      <div class="admin-werkzeug">
        ${viele ? '<input type="search" placeholder="Filtern: Artikelnummer oder Ø" data-admin-filter>' : ""}
        <label>Alle um <input type="text" inputmode="decimal" size="5" placeholder="+3" data-admin-prozent> %</label>
        <button class="btn btn-linie" type="button" data-admin-prozent-los>Übernehmen</button>
      </div>
      <div class="admin-liste"><table><thead><tr><th>Artikel-Nr.</th><th>Ausführung</th><th class="r">Preis netto €</th></tr></thead><tbody></tbody></table></div>
      <div class="admin-fuss"><button class="btn btn-rot" type="button" data-admin-speichern>Preise speichern</button>
        <span class="formular-status" data-admin-status></span>
        <a class="textlink" href="${window.TQ.basis}admin/?seite=preise">Alle Preise in der Verwaltung</a></div>`;
    const tbody = box.querySelector("tbody");
    const feld = (v) => (v.preis == null ? "" : v.preis.toFixed(2).replace(".", ","));
    P.varianten.forEach((v) => {
      const z = document.createElement("tr");
      z.dataset.sku = v.sku;
      const ausf = Object.entries(v.auswahl).map(([a, w]) => (a === "Schneidendurchmesser" ? "Ø " + w : w)).join(" · ") || "–";
      z.innerHTML = `<td></td><td></td><td class="r"><input type="text" inputmode="decimal" size="8"></td>`;
      z.children[0].textContent = v.sku;
      z.children[1].textContent = ausf;
      const eingabe = z.querySelector("input");
      eingabe.value = feld(v);
      eingabe.dataset.alt = feld(v);
      eingabe.setAttribute("aria-label", "Preis " + v.sku);
      eingabe.addEventListener("input", () => eingabe.classList.toggle("geaendert", eingabe.value !== eingabe.dataset.alt));
      tbody.appendChild(z);
    });
    const status = box.querySelector("[data-admin-status]");
    const filter = box.querySelector("[data-admin-filter]");
    if (filter) filter.addEventListener("input", () => {
      const q = filter.value.toLowerCase().replace(".", ",").trim();
      tbody.querySelectorAll("tr").forEach((z) => { z.hidden = q !== "" && !z.textContent.toLowerCase().includes(q); });
    });
    box.querySelector("[data-admin-prozent-los]").addEventListener("click", () => {
      const p = parseFloat((box.querySelector("[data-admin-prozent]").value || "").replace(",", "."));
      if (!p || Math.abs(p) >= 50) { status.textContent = "Bitte eine Prozentzahl zwischen −50 und +50 eingeben."; status.className = "formular-status fehler"; return; }
      tbody.querySelectorAll("tr:not([hidden]) input").forEach((e) => {
        const alt = parseFloat(e.value.replace(",", "."));
        if (!isNaN(alt)) { e.value = (Math.round(alt * (1 + p / 100) * 100) / 100).toFixed(2).replace(".", ","); e.classList.toggle("geaendert", e.value !== e.dataset.alt); }
      });
      status.textContent = "Neue Preise eingetragen – bitte prüfen und speichern.";
      status.className = "formular-status";
    });
    box.querySelector("[data-admin-speichern]").addEventListener("click", async () => {
      const neu = {};
      tbody.querySelectorAll("tr").forEach((z) => {
        const e = z.querySelector("input");
        if (e.value.trim() !== e.dataset.alt) neu[z.dataset.sku] = e.value.trim();
      });
      if (!Object.keys(neu).length) { status.textContent = "Keine Änderungen."; status.className = "formular-status"; return; }
      status.textContent = "Speichere …"; status.className = "formular-status";
      try {
        const antwort = await fetch(window.TQ.basis + "api/preise.php?aktion=speichern", {
          method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ csrf: Konto.csrfAdmin, preise: neu }),
        });
        const j = await antwort.json();
        if (!j.ok) throw new Error(j.meldung || "Speichern fehlgeschlagen");
        Preise.setzen(j.geaendert || {});
        preiseAnwenden();
        tbody.querySelectorAll("tr").forEach((z) => {
          const v = P.varianten.find((x) => x.sku === z.dataset.sku);
          const e = z.querySelector("input");
          e.value = e.dataset.alt = feld(v);
          e.classList.remove("geaendert");
        });
        status.textContent = j.anzahl + (j.anzahl === 1 ? " Preis" : " Preise") + " gespeichert – gilt ab sofort.";
        status.className = "formular-status ok";
      } catch (err) {
        status.textContent = err.message;
        status.className = "formular-status fehler";
      }
    });
    const ziel = $(".produkt-beschreibung");
    ziel.parentNode.insertBefore(box, ziel);
  }

  document.addEventListener("konto", () => { aktualisieren(); adminFeld(); });

  /* Nur eine Möglichkeit je Attribut? Direkt vorwählen */
  P.attribute.forEach((a) => {
    const werte = new Set(P.varianten.map((v) => v.auswahl[a]));
    if (werte.size === 1) gewaehlt[a] = [...werte][0];
  });
  aktualisieren();
})();
