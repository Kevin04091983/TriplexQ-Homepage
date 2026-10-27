/* TRIPLEX-Q – Kundenkonto: Anmelden, Registrieren, Stammdaten, Bestellhistorie.
   Auf dem Server läuft alles über api/konto.php. Ohne PHP (lokale Vorschau) schaltet
   die Seite in einen Vorschau-Modus, damit man den Ablauf ansehen kann. */
(function () {
  "use strict";
  const wurzel = document.querySelector("[data-konto]");
  if (!wurzel || !window.TQ) return;
  if (!window.TQ.Konto) {
    // veraltete app.js aus dem Browser-Zwischenspeicher: Hinweis statt leerer Seite
    const m = wurzel.querySelector("[data-melde]");
    m.textContent = "Bitte laden Sie die Seite einmal neu (Strg + F5), um das Kundenkonto zu öffnen.";
    m.className = "formular-status gross fehler";
    return;
  }
  const { euro, pruefen, toast, Konto } = window.TQ;
  const $ = (s, r = wurzel) => r.querySelector(s);
  const $$ = (s, r = wurzel) => Array.from(r.querySelectorAll(s));
  const melde = $("[data-melde]");

  function status(form, text, art) {
    const s = form.querySelector("[data-status]");
    if (!s) return;
    s.className = "formular-status" + (art ? " " + art : "");
    s.textContent = text || "";
  }

  function zeigen() {
    const k = Konto.kunde;
    $("[data-abgemeldet]").hidden = !!k;
    $("[data-angemeldet]").hidden = !k;
    $("[data-kein-server]").hidden = !Konto.vorschau;
    if (!k) return;
    $("[data-name]").textContent = k.firma || (k.vorname + " " + k.nachname);
    $("[data-email]").textContent = k.email + (k.vorname ? " · " + k.vorname + " " + k.nachname : "");
    $("[data-unbestaetigt]").hidden = k.email_bestaetigt !== false || Konto.vorschau;
    const adminBox = $("[data-admin-box]");
    if (adminBox) adminBox.hidden = !k.admin;
    const codeBox = $("[data-admin-code]");
    if (codeBox) codeBox.hidden = !k.admin_code_noetig;
    const rabatt = Number(k.rabatt) || 0;
    $("[data-rabatt-box]").hidden = rabatt <= 0;
    if (rabatt > 0) $("[data-rabatt]").textContent = rabatt.toLocaleString("de-DE", { maximumFractionDigits: 2 }) + " % Rabatt";
    const f = $("[data-form-daten]");
    ["firma", "ustid", "vorname", "nachname", "strasse", "plz", "ort", "land", "telefon", "lieferadresse"].forEach((n) => {
      if (f.elements[n]) f.elements[n].value = k[n] || (n === "land" ? "Deutschland" : "");
    });
    bestellungen();
  }

  /* ------------------------------------------------ Bestellhistorie */
  async function bestellungen() {
    const box = $("[data-bestellungen]");
    if (Konto.vorschau) {
      box.innerHTML = '<h2 class="karte-titel">Meine Bestellungen</h2><p class="klein-text">Im Vorschau-Modus gibt es noch keine Bestellungen. Auf dem Server stehen hier alle Bestellungen mit Datum, Artikeln und Summen – inklusive Schaltfläche „Nochmal bestellen“.</p>';
      return;
    }
    const antwort = await Konto.ruf("bestellungen");
    if (!antwort.ok) { box.innerHTML = '<p class="klein-text">Bestellungen konnten nicht geladen werden.</p>'; return; }
    if (!antwort.bestellungen.length) {
      box.innerHTML = '<h2 class="karte-titel">Meine Bestellungen</h2><p class="klein-text">Sie haben noch nichts bestellt.</p><a class="btn btn-rot" href="shop.html">Zum Shop</a>';
      return;
    }
    box.innerHTML = '<h2 class="karte-titel">Meine Bestellungen</h2>';
    antwort.bestellungen.forEach((b) => {
      const d = document.createElement("article");
      d.className = "bestellung";
      const datum = new Date(b.erstellt.replace(" ", "T")).toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric" });
      const zeilen = (b.positionen || []).map((p) =>
        `<li><span>${p.menge} × ${p.bezeichnung}${p.auswahl ? " (" + p.auswahl + ")" : ""}</span><small>Art.-Nr. ${p.sku}</small><b>${euro(Number(p.gesamtpreis))}</b></li>`).join("");
      d.innerHTML = `
        <header><div><strong>${b.nummer}</strong><small>${datum} · ${b.versandart === "abholung" ? "Selbstabholung" : "Versand"}</small></div>
        <span class="zustand zustand-${b.status}">${{ neu: "Neu", bearbeitung: "In Bearbeitung", versendet: "Versendet", abgeschlossen: "Abgeschlossen", storniert: "Storniert" }[b.status] || b.status}</span></header>
        <ul class="bestellung-artikel">${zeilen}</ul>
        <footer><span>${euro(Number(b.netto))} netto · zzgl. Versand und MwSt.</span><strong>${euro(Number(b.gesamt))}</strong>
        <button class="btn btn-linie" type="button" data-nochmal>Nochmal bestellen</button></footer>`;
      d.querySelector("[data-nochmal]").addEventListener("click", () => {
        (b.positionen || []).forEach((p) => {
          const k = (window.TQ_PREISE || {})[p.sku];
          if (k) window.TQ.Warenkorb.hinzu({ sku: p.sku, id: k[1], name: k[2], auswahl: k[3], preis: k[0], bild: k[4] }, Number(p.menge) || 1);
        });
        toast("Artikel aus Bestellung " + b.nummer + " im Warenkorb", true);
      });
      box.appendChild(d);
    });
  }

  /* ------------------------------------------------ Formulare */
  function daten(form, felder) {
    const d = {};
    felder.forEach((n) => { if (form.elements[n]) d[n] = form.elements[n].value.trim(); });
    return d;
  }

  $("[data-form-anmelden]").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    if (!pruefen(form)) return status(form, "Bitte E-Mail und Passwort eingeben.", "fehler");
    status(form, "Anmeldung läuft …");
    const a = await Konto.ruf("anmelden", daten(form, ["email", "passwort"]));
    if (a.ok) { melde.textContent = "Willkommen zurück!"; melde.className = "formular-status gross ok"; zeigen(); window.scrollTo({ top: 0, behavior: "smooth" }); }
    else if (a.passwort_neu) { status(form, ""); melde.textContent = a.meldung; melde.className = "formular-status gross ok"; window.scrollTo({ top: 0, behavior: "smooth" }); }
    else status(form, a.meldung || "Anmeldung fehlgeschlagen.", "fehler");
  });

  $("[data-form-registrieren]").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    if (!pruefen(form)) return status(form, "Bitte die markierten Felder ausfüllen.", "fehler");
    status(form, "Konto wird angelegt …");
    const a = await Konto.ruf("registrieren", daten(form, ["email", "passwort", "firma", "ustid", "vorname", "nachname", "strasse", "plz", "ort", "land", "telefon", "website"]));
    if (a.ok) {
      melde.textContent = "Ihr Konto ist angelegt. Wir haben Ihnen eine E-Mail zur Bestätigung geschickt.";
      melde.className = "formular-status gross ok";
      zeigen();
      window.scrollTo({ top: 0, behavior: "smooth" });
    } else if (a.passwort_neu) { status(form, ""); melde.textContent = a.meldung; melde.className = "formular-status gross ok"; window.scrollTo({ top: 0, behavior: "smooth" }); }
    else status(form, a.meldung || "Konto konnte nicht angelegt werden.", "fehler");
  });

  $("[data-form-daten]").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    if (!pruefen(form)) return status(form, "Bitte Vor- und Nachname angeben.", "fehler");
    const a = await Konto.ruf("profil", daten(form, ["firma", "ustid", "vorname", "nachname", "strasse", "plz", "ort", "land", "telefon", "lieferadresse"]));
    status(form, a.ok ? "Gespeichert." : (a.meldung || "Speichern fehlgeschlagen."), a.ok ? "ok" : "fehler");
    if (a.ok) zeigen();
  });

  $("[data-form-passwort]").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    if (!pruefen(form)) return status(form, "Bitte beide Felder ausfüllen.", "fehler");
    const a = await Konto.ruf("passwort", daten(form, ["alt", "neu"]));
    status(form, a.ok ? "Passwort geändert." : (a.meldung || "Passwort konnte nicht geändert werden."), a.ok ? "ok" : "fehler");
    if (a.ok) form.reset();
  });

  $("[data-form-vergessen]").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    if (!pruefen(form)) return status(form, "Bitte E-Mail-Adresse eingeben.", "fehler");
    const a = await Konto.ruf("passwort-vergessen", daten(form, ["email"]));
    status(form, a.meldung || "Falls ein Konto besteht, ist die E-Mail unterwegs.", "ok");
  });

  $("[data-form-neues-passwort]").addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    if (!pruefen(form)) return status(form, "Bitte ein Passwort mit mindestens 8 Zeichen eingeben.", "fehler");
    const a = await Konto.ruf("passwort-neu", { token: new URLSearchParams(location.search).get("neues-passwort"), passwort: form.elements.passwort.value });
    status(form, a.meldung || (a.ok ? "Passwort gespeichert." : "Link abgelaufen."), a.ok ? "ok" : "fehler");
    if (a.ok) setTimeout(() => { location.href = "konto.html"; }, 1500);
  });

  $("[data-vergessen]").addEventListener("click", () => {
    $("[data-abgemeldet]").hidden = true;
    $("[data-form-vergessen]").hidden = false;
  });
  $("[data-zurueck]").addEventListener("click", () => {
    $("[data-form-vergessen]").hidden = true;
    $("[data-abgemeldet]").hidden = false;
  });
  $("[data-abmelden]").addEventListener("click", async () => {
    const a = await Konto.ruf("abmelden");
    if (a.ok) {
      melde.textContent = "Sie sind abgemeldet.";
      melde.className = "formular-status gross";
    } else {
      // Sitzung passt nicht mehr zusammen: neu laden, damit Anzeige und Server wieder übereinstimmen
      melde.textContent = a.meldung || "Abmelden hat nicht geklappt – bitte Seite neu laden.";
      melde.className = "formular-status gross fehler";
      await Konto.laden();
    }
    zeigen();
  });

  $$("[data-reiter]").forEach((b) => b.addEventListener("click", () => {
    $$("[data-reiter]").forEach((x) => x.classList.toggle("aktiv", x === b));
    $$("[data-tafel]").forEach((t) => { t.hidden = t.dataset.tafel !== b.dataset.reiter; });
  }));

  /* ------------------------------------------------ Start */
  (async function start() {
    const p = new URLSearchParams(location.search);
    await Konto.laden();
    if (p.get("bestaetigen")) {
      const a = await Konto.ruf("bestaetigen", null, "&token=" + encodeURIComponent(p.get("bestaetigen")));
      melde.textContent = a.meldung || "";
      melde.className = "formular-status gross " + (a.ok ? "ok" : "fehler");
      await Konto.laden();
    }
    zeigen();
    if (p.get("neues-passwort")) {
      $("[data-abgemeldet]").hidden = true;
      $("[data-angemeldet]").hidden = true;
      $("[data-form-neues-passwort]").hidden = false;
    }
  })();
})();
