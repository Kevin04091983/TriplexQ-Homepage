/* TRIPLEX-Q – gemeinsame Funktionen für alle Seiten */
(function () {
  "use strict";
  document.documentElement.classList.add("js");
  const basis = document.body.dataset.basis || "";
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------------ Warenkorb (localStorage) */
  const SCHLUESSEL = "tq-warenkorb-v1";
  const Warenkorb = {
    lesen() {
      try {
        const d = JSON.parse(localStorage.getItem(SCHLUESSEL) || "[]");
        return Array.isArray(d) ? d : [];
      } catch (e) { return []; }
    },
    schreiben(pos) {
      try { localStorage.setItem(SCHLUESSEL, JSON.stringify(pos)); } catch (e) { /* privat-Modus */ }
      badge(true);
      document.dispatchEvent(new CustomEvent("warenkorb"));
    },
    hinzu(artikel, menge) {
      const pos = this.lesen();
      const vorhanden = pos.find((p) => p.sku === artikel.sku);
      if (vorhanden) vorhanden.menge = Math.min(999, vorhanden.menge + menge);
      else pos.push(Object.assign({}, artikel, { menge }));
      this.schreiben(pos);
    },
    anzahl() { return this.lesen().reduce((s, p) => s + p.menge, 0); },
    leeren() { this.schreiben([]); },
  };
  /* ------------------------------------------------ Kundenkonto */
  const DEMO = "tq-vorschau-konto";
  const Konto = {
    kunde: null,
    vorschau: false,       // true = kein PHP erreichbar (lokale Vorschau)
    csrf: "",
    rabatt() { return this.kunde ? Number(this.kunde.rabatt) || 0 : 0; },
    preis(betrag) {
      const r = this.rabatt();
      return r > 0 ? Math.round(betrag * (1 - r / 100) * 100) / 100 : betrag;
    },
    /** eigener Artikelpreis des Kunden (falls hinterlegt) */
    sonderpreis(sku) {
      const sp = this.kunde && this.kunde.sonderpreise;
      return sp && Object.prototype.hasOwnProperty.call(sp, sku) ? Number(sp[sku]) : null;
    },
    /** Preis, den dieser Kunde zahlt: Sonderpreis > Listenpreis abzüglich Rabatt (wie auf dem Server) */
    preisFuer(sku, liste) {
      const sp = this.sonderpreis(sku);
      return sp != null ? sp : this.preis(liste);
    },
    async ruf(aktion, daten, zusatz) {
      if (this.vorschau) return this.vorschauRuf(aktion, daten);
      try {
        // Lesende Aufrufe per GET, alles andere per POST – immer mit Sicherheitskennung (sonst lehnt der Server ab)
        const lesen = ["ich", "bestellungen", "bestaetigen"].includes(aktion);
        const antwort = await fetch(basis + "api/konto.php?aktion=" + aktion + (zusatz || ""), {
          method: lesen ? "GET" : "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "same-origin",
          body: lesen ? undefined : JSON.stringify(Object.assign({ csrf: this.csrf }, daten || {})),
        });
        const j = await antwort.json();
        if (j.csrf) this.csrf = j.csrf;
        if (j.csrf_admin !== undefined) this.csrfAdmin = j.csrf_admin;
        if (j.kunde !== undefined) { this.kunde = j.kunde; melden(); }
        if (aktion === "abmelden" && j.ok) { this.kunde = null; melden(); }
        return j;
      } catch (e) {
        this.vorschau = true;
        return this.vorschauRuf(aktion, daten);
      }
    },
    /* Vorschau ohne Server: Ablauf zum Ansehen, Daten bleiben nur im Browser */
    vorschauRuf(aktion, daten) {
      const lesen = () => { try { return JSON.parse(localStorage.getItem(DEMO) || "null"); } catch (e) { return null; } };
      const schreiben = (k) => { try { localStorage.setItem(DEMO, JSON.stringify(k)); } catch (e) {} };
      if (aktion === "ich") { this.kunde = lesen(); melden(); return Promise.resolve({ ok: true, angemeldet: !!this.kunde, kunde: this.kunde }); }
      if (aktion === "registrieren" || aktion === "anmelden") {
        const alt = lesen() || {};
        const k = Object.assign({ rabatt: 8, email_bestaetigt: true, land: "Deutschland" }, alt, daten || {});
        delete k.passwort; delete k.website; delete k.csrf;
        k.rabatt = alt.rabatt !== undefined ? alt.rabatt : 8;
        schreiben(k); this.kunde = k; melden();
        return Promise.resolve({ ok: true, kunde: k });
      }
      if (aktion === "profil") {
        const k = Object.assign(lesen() || {}, daten || {});
        delete k.csrf;
        schreiben(k); this.kunde = k; melden();
        return Promise.resolve({ ok: true, kunde: k });
      }
      if (aktion === "abmelden") { this.kunde = null; melden(); return Promise.resolve({ ok: true }); }
      if (aktion === "bestellungen") return Promise.resolve({ ok: true, bestellungen: [] });
      if (aktion === "passwort" || aktion === "passwort-vergessen" || aktion === "passwort-neu" || aktion === "bestaetigen") {
        return Promise.resolve({ ok: true, meldung: "In der Vorschau ohne Server wird dabei keine E-Mail verschickt." });
      }
      return Promise.resolve({ ok: false, meldung: "In der Vorschau nicht verfügbar." });
    },
    async laden() {
      const a = await this.ruf("ich");
      return a;
    },
  };

  function melden() {
    const an = !!Konto.kunde;
    $$("[data-konto-an]").forEach((p) => { p.hidden = !an; });
    $$("[data-konto-link]").forEach((a) => {
      a.setAttribute("aria-label", an ? "Mein Konto" : "Anmelden");
      a.classList.toggle("angemeldet", an);
    });
    document.dispatchEvent(new CustomEvent("konto", { detail: Konto.kunde }));
  }

  /* ------------------------------------------------ Aktuelle Preise vom Server
     Die Seiten enthalten die Preise vom letzten Seitenbau. Was in der Verwaltung geändert wurde,
     holt sich jede Seite hier ab (nur geänderte Artikel). Ohne Server bleiben die eingebauten Preise. */
  const Preise = {
    geaendert: {},
    geladen: false,
    async laden() {
      try {
        const antwort = await fetch(basis + "api/preise.php", { credentials: "same-origin", cache: "no-store" });
        const j = await antwort.json();
        if (j && j.ok) { this.setzen(j.preise || {}); this.produkte = j.produkte || {}; this.karten(); }
      } catch (e) { /* lokale Vorschau ohne PHP */ }
      this.geladen = true;
      document.dispatchEvent(new CustomEvent("preise", { detail: this.geaendert }));
    },
    setzen(liste) {
      Object.assign(this.geaendert, liste);
      const tabelle = window.TQ_PREISE;
      if (tabelle) Object.entries(liste).forEach(([sku, preis]) => { if (tabelle[sku]) tabelle[sku][0] = preis; });
    },
    produkte: {},
    /** "ab …"-Preise auf Produktkarten und Linien-Kacheln (Start, Shop, Produktseiten) */
    karten() {
      const text = (mm) => (mm[0] === mm[1] ? euro(mm[0]) : "ab " + euro(mm[0]));
      $$("[data-karten-preis]").forEach((el) => {
        const mm = this.produkte[el.dataset.kartenPreis];
        if (mm) el.textContent = text(mm);
      });
      $$(".linie[data-produkte]").forEach((kachel) => {
        const ziel = kachel.querySelector("[data-ab]");
        if (!ziel) return;
        // Format "produkt-id:eingebauter-Mindestpreis …"; geänderte Produkte kommen vom Server
        const werte = kachel.dataset.produkte.split(" ").map((paar) => {
          const [id, eingebaut] = paar.split(":");
          return this.produkte[id] ? this.produkte[id][0] : parseFloat(eingebaut);
        }).filter((w) => isFinite(w));
        if (werte.length) ziel.textContent = euro(Math.min(...werte));
      });
    },
    /** gültiger Preis eines Artikels (geändert oder eingebaut) */
    von(sku, eingebaut) { return Object.prototype.hasOwnProperty.call(this.geaendert, sku) ? this.geaendert[sku] : eingebaut; },
  };

  window.TQ = { Warenkorb, euro, toast, basis, sende, Konto, Preise };

  function badge(hupf) {
    const n = Warenkorb.anzahl();
    $$("[data-cart-count]").forEach((b) => {
      b.textContent = n > 99 ? "99+" : n;
      b.hidden = n === 0;
      if (hupf) { b.classList.remove("hupf"); void b.offsetWidth; b.classList.add("hupf"); }
    });
  }
  badge(false);
  window.addEventListener("storage", () => badge(false));

  function euro(b) {
    return new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(b || 0);
  }

  /* ------------------------------------------------ Hinweis unten */
  let toastTimer;
  function toast(text, mitLink) {
    const t = $("[data-toast]");
    if (!t) return;
    t.innerHTML = "";
    const s = document.createElement("span");
    s.textContent = text;
    t.appendChild(s);
    if (mitLink) {
      const a = document.createElement("a");
      a.href = basis + "warenkorb.html";
      a.textContent = "Zum Warenkorb";
      t.appendChild(a);
    }
    t.classList.add("zeigen");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove("zeigen"), 4200);
  }

  /* ------------------------------------------------ Kopf & Menü */
  const kopf = $("[data-kopf]");
  const scroll = () => kopf && kopf.classList.toggle("gescrollt", window.scrollY > 20);
  window.addEventListener("scroll", scroll, { passive: true });
  scroll();
  const menue = $("[data-menue]");
  if (menue) {
    menue.addEventListener("click", () => {
      const offen = kopf.classList.toggle("offen");
      menue.setAttribute("aria-expanded", offen);
      menue.setAttribute("aria-label", offen ? "Menü schließen" : "Menü öffnen");
    });
    $$(".hauptnav a").forEach((a) => a.addEventListener("click", () => kopf.classList.remove("offen")));
  }

  /* ------------------------------------------------ Einblenden beim Scrollen */
  const reveal = $$("[data-reveal]");
  if ("IntersectionObserver" in window) {
    const io = new IntersectionObserver((eintraege) => {
      eintraege.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add("sichtbar"); io.unobserve(e.target); }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    reveal.forEach((el, i) => { el.style.transitionDelay = (i % 4) * 70 + "ms"; io.observe(el); });
  } else {
    reveal.forEach((el) => el.classList.add("sichtbar"));
  }

  /* ------------------------------------------------ Cookie-Richtlinie: Auswahl merken
     "akzeptiert" = YouTube-Videos werden sofort eingebunden, sonst erst nach Klick */
  const COOKIE = "tq-cookie-wahl";
  const cookieWahl = () => { try { return localStorage.getItem(COOKIE) || ""; } catch (e) { return ""; } };
  const cookieBox = $("[data-cookie]");
  if (cookieBox) {
    const status = $("[data-cookie-status]", cookieBox);
    const zeigen = () => {
      const w = cookieWahl();
      status.textContent = w === "akzeptiert" ? "Ihre Auswahl: akzeptiert." : w === "abgelehnt" ? "Ihre Auswahl: abgelehnt." : "";
      status.className = "formular-status" + (w ? " ok" : "");
      $$("[data-cookie-wahl]", cookieBox).forEach((b) => b.setAttribute("aria-pressed", b.dataset.cookieWahl === w));
    };
    $$("[data-cookie-wahl]", cookieBox).forEach((b) => b.addEventListener("click", () => {
      try { localStorage.setItem(COOKIE, b.dataset.cookieWahl); } catch (e) { /* privat-Modus */ }
      zeigen();
    }));
    zeigen();
  }

  /* ------------------------------------------------ Videos: erst nach Klick laden (oder sofort, wenn akzeptiert) */
  function videoLaden(knopf, autoplay) {
    const id = knopf.dataset.video;
    const f = document.createElement("iframe");
    f.src = "https://www.youtube-nocookie.com/embed/" + encodeURIComponent(id) + "?rel=0" + (autoplay ? "&autoplay=1" : "");
    f.title = knopf.getAttribute("aria-label") || "Video";
    f.allow = "autoplay; encrypted-media; picture-in-picture; fullscreen";
    f.allowFullscreen = true;
    f.loading = "lazy";
    knopf.replaceChildren(f);
  }
  $$("[data-video]").forEach((knopf) => {
    if (cookieWahl() === "akzeptiert") videoLaden(knopf, false);
    else knopf.addEventListener("click", () => videoLaden(knopf, true), { once: true });
  });

  /* ------------------------------------------------ WhatsApp-Chat "Vertriebsteam" */
  const wa = $("[data-wa]");
  if (wa) {
    const fenster = $(".wa-fenster", wa);
    const knopf = $("[data-wa-knopf]", wa);
    const feld = $("#wa-text", wa);
    const zeit = $("[data-wa-zeit]", wa);
    const offen = (an, fokus) => {
      fenster.hidden = !an;
      wa.classList.toggle("offen", an);
      knopf.setAttribute("aria-expanded", an);
      if (an && zeit) zeit.textContent = new Date().toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
      if (an && fokus) feld.focus();
    };
    knopf.addEventListener("click", () => offen(fenster.hidden, true));
    $("[data-wa-zu]", wa).addEventListener("click", () => { offen(false); knopf.focus(); });
    document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && !fenster.hidden) { offen(false); knopf.focus(); } });
    $("[data-wa-form]", wa).addEventListener("submit", (ev) => {
      ev.preventDefault();
      // Auf Produktseiten das Werkzeug gleich mitschicken
      const h1 = $(".produkt h1") || $("h1");
      const bezug = document.body.querySelector(".produkt") && h1 ? "\n\n(Frage zu: " + h1.textContent.trim() + ")" : "";
      const text = (feld.value.trim() || "Hallo TRIPLEX-Q Vertriebsteam, ich habe eine Frage.") + bezug;
      window.open("https://wa.me/" + wa.dataset.waNummer + "?text=" + encodeURIComponent(text), "_blank", "noopener");
    });
    feld.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter" && !ev.shiftKey) { ev.preventDefault(); $("[data-wa-form]", wa).requestSubmit(); }
    });
    // Link mit "#whatsapp" (z. B. aus einer Mail oder von Instagram) öffnet den Chat sofort
    if (location.hash === "#whatsapp") offen(true, false);
    // Wie im alten Shop: am Computer einmal pro Besuch nach ein paar Sekunden von selbst aufklappen (nicht am Handy)
    try {
      if (window.matchMedia("(min-width: 1024px)").matches && !sessionStorage.getItem("tq-wa-gezeigt")) {
        setTimeout(() => {
          if (fenster.hidden && !document.querySelector(".dialog:not([hidden]), .lightbox:not([hidden])")) offen(true, false);
          try { sessionStorage.setItem("tq-wa-gezeigt", "1"); } catch (e) { /* privat-Modus */ }
        }, 8000);
      }
    } catch (e) { /* kein sessionStorage */ }
  }

  /* ------------------------------------------------ Instagram-Reels: erst beim Klick von Instagram laden */
  const reels = $("[data-reels]");
  if (reels) {
    const pfeile = $$("[data-reels-pfeil]");
    const pfeileSetzen = () => {
      const ende = reels.scrollWidth - reels.clientWidth - 4;
      pfeile.forEach((p) => { p.disabled = p.dataset.reelsPfeil === "-1" ? reels.scrollLeft <= 4 : reels.scrollLeft >= ende; });
    };
    pfeile.forEach((p) => p.addEventListener("click", () => {
      reels.scrollBy({ left: Number(p.dataset.reelsPfeil) * reels.clientWidth * 0.8 });
    }));
    reels.addEventListener("scroll", pfeileSetzen, { passive: true });
    window.addEventListener("resize", pfeileSetzen);
    pfeileSetzen();

    let dialog, ausloeser;
    const schliessen = () => {
      if (!dialog || dialog.hidden) return;
      dialog.hidden = true;
      $("iframe", dialog).src = "about:blank";          // Video stoppen
      document.body.style.overflow = "";
      if (ausloeser) ausloeser.focus();
    };
    reels.addEventListener("click", (ev) => {
      const knopf = ev.target.closest("[data-reel]");
      if (!knopf) return;
      ausloeser = knopf;
      const code = knopf.dataset.reel;
      if (!dialog) {
        dialog = document.createElement("div");
        dialog.className = "reel-dialog";
        dialog.setAttribute("role", "dialog");
        dialog.setAttribute("aria-modal", "true");
        dialog.setAttribute("aria-label", "Instagram-Video");
        dialog.innerHTML = '<div class="reel-box"><button class="reel-zu" type="button" aria-label="Video schließen">' +
          '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>' +
          '<iframe title="Instagram-Video" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe>' +
          '<a target="_blank" rel="noopener">Auf Instagram öffnen ↗</a></div>';
        document.body.appendChild(dialog);
        $(".reel-zu", dialog).addEventListener("click", schliessen);
        dialog.addEventListener("click", (e) => { if (e.target === dialog) schliessen(); });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape") schliessen(); });
      }
      $("iframe", dialog).src = "https://www.instagram.com/reel/" + encodeURIComponent(code) + "/embed/";
      $("a", dialog).href = "https://www.instagram.com/reel/" + encodeURIComponent(code) + "/";
      dialog.hidden = false;
      document.body.style.overflow = "hidden";
      $(".reel-zu", dialog).focus();
    });
  }

  /* ------------------------------------------------ Lightbox */
  const box = $("[data-lightbox-box]");
  if (box) {
    const bild = $("img", box);
    const zu = () => { box.hidden = true; bild.removeAttribute("src"); };
    $$("[data-lightbox]").forEach((a) => a.addEventListener("click", (ev) => {
      ev.preventDefault();
      bild.src = a.href;
      box.hidden = false;
    }));
    box.addEventListener("click", (ev) => { if (ev.target !== bild) zu(); });
    document.addEventListener("keydown", (ev) => { if (ev.key === "Escape") zu(); });
  }

  /* ------------------------------------------------ Formulare */
  function pruefen(form) {
    let ok = true;
    $$("input, textarea, select", form).forEach((f) => {
      if (f.closest(".feld-hp")) return;
      const huelle = f.closest(".feld, .check");
      const gueltig = f.type === "checkbox" ? (!f.required || f.checked) : f.checkValidity() && (!f.required || f.value.trim() !== "");
      if (huelle) huelle.classList.toggle("fehler", !gueltig);
      if (!gueltig && ok) { f.focus(); ok = false; }
    });
    return ok;
  }
  window.TQ.pruefen = pruefen;

  /* Versand: zuerst Server-Skript (api/*.php), sonst E-Mail-Programm öffnen */
  async function sende(endpunkt, daten, mailBetreff, mailText, empfaenger) {
    try {
      const antwort = await fetch(basis + "api/" + endpunkt, {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(daten),
      });
      if (antwort.ok) {
        const j = await antwort.json().catch(() => ({}));
        if (j && j.ok) { sende.antwort = j; return "server"; }
      }
    } catch (e) { /* kein Server-Skript verfügbar */ }
    const href = "mailto:" + empfaenger + "?subject=" + encodeURIComponent(mailBetreff) + "&body=" + encodeURIComponent(mailText);
    window.location.href = href;
    return "mail";
  }

  const params = new URLSearchParams(location.search);
  $$("[data-betreff]").forEach((f) => { if (params.get("betreff")) f.value = params.get("betreff"); });
  if (params.get("betreff") && $("#kontakt") && !location.hash) {
    setTimeout(() => $("#kontakt").scrollIntoView({ behavior: "smooth" }), 300);
  }

  $$('[data-formular="kontakt"]').forEach((form) => {
    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const status = $("[data-status]", form);
      status.className = "formular-status";
      if (!pruefen(form)) { status.textContent = "Bitte füllen Sie die markierten Felder aus."; status.classList.add("fehler"); return; }
      const d = Object.fromEntries(new FormData(form));
      if (d.website) return;
      const text = `Name: ${d.name}\nFirma: ${d.firma || "-"}\nE-Mail: ${d.email}\nTelefon: ${d.telefon || "-"}\n\n${d.nachricht}`;
      const weg = await sende("kontakt.php", d, "Anfrage über triplex-q.com: " + (d.betreff || "Kontakt"), text, "kontakt@triplex-q.de");
      status.classList.add("ok");
      status.textContent = weg === "server"
        ? "Vielen Dank! Ihre Nachricht ist bei uns eingegangen – wir melden uns in Kürze."
        : "Ihr E-Mail-Programm wurde mit der vorbereiteten Nachricht geöffnet – bitte dort nur noch auf „Senden“ klicken.";
      if (weg === "server") form.reset();
    });
  });

  $$("[data-jahr]").forEach((s) => { s.textContent = new Date().getFullYear(); });

  Konto.laden();   // Kopfzeile und Preise an das Kundenkonto anpassen
  Preise.laden();  // in der Verwaltung geänderte Preise übernehmen
})();
