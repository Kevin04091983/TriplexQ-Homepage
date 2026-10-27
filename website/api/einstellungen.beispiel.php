<?php
/* VORLAGE – als einstellungen.php kopieren und mit den echten Zugangsdaten füllen.
   Die echte einstellungen.php wird NICHT ins Git übernommen (.gitignore). */
/* Zentrale Einstellungen für Formulare, Kundenkonto und Verwaltung.
   Diese Datei nach dem Hochladen mit den echten Zugangsdaten füllen. */

// ---------- E-Mail ----------
// mehrere Empfänger mit Komma trennen; der erste ist Antwort-Adresse in der Bestellbestätigung
const EMPFAENGER_BESTELLUNG = 'order@wkz-schmidt.de, wareneingang@wkz-schmidt.de';
const EMPFAENGER_KONTAKT = 'kontakt@triplex-q.de';
// Absender-Domain muss im SELBEN Strato-Paket liegen wie die Seite, sonst verwirft Strato die Mail still.
// Live (Webshop-Paket, triplex-q.com): shop@triplex-q.com. Antworten gehen an EMPFAENGER_KONTAKT.
const ABSENDER = 'TRIPLEX-Q Webshop <shop@triplex-q.com>';

// Nur zum Testen: true = es werden keine E-Mails verschickt (z. B. Probeübernahme mit echten Kundendaten)
const MAIL_AUS = false;

// ---------- Shop ----------
const VERSAND_NETTO = 9.50;
const MWST = 0.19;
const SEITEN_URL = 'https://triplex-q.de';   // ohne Schrägstrich am Ende

// ---------- Datenbank (Strato: Kundenbereich > Datenbanken und Webspace) ----------
const DB_HOST = 'rdbms.strato.de';
const DB_NAME = 'DBxxxxxxx';
const DB_USER = 'Uxxxxxxx';
const DB_PASS = 'HIER_DAS_DATENBANKPASSWORT';

// ---------- Admin-Konten ----------
// Wer sich auf der Kontoseite mit einer dieser E-Mail-Adressen anmeldet, ist automatisch Admin:
// Preise direkt auf den Produktseiten ändern, Zugang zur Verwaltung ohne zweite Anmeldung.
// Das Konto muss ganz normal registriert und die E-Mail-Adresse bestätigt sein.
const ADMIN_EMAILS = ['kontakt@triplex-q.de'];

// ---------- Verwaltung (Notfall-Zugang ohne Kundenkonto) ----------
// Passwort-Hash erzeugen: einmal  php -r "echo password_hash('WunschPasswort', PASSWORD_DEFAULT);"
// ausführen (oder die beiliegende Datei admin/passwort-hash.php im Browser öffnen) und hier eintragen.
const ADMIN_BENUTZER = 'triplexq';
const ADMIN_HASH = '$2y$12$ERSETZEN.DURCH.EIGENEN.HASH.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
