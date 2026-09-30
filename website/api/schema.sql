-- TRIPLEX-Q – Tabellen für Kundenkonto und Bestellungen (MySQL 8 / MariaDB)
-- Einmalig im Strato-Kundenbereich unter "Datenbanken und Webspace" > phpMyAdmin einspielen.

CREATE TABLE IF NOT EXISTS kunden (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(190) NOT NULL UNIQUE,
  passwort_hash   VARCHAR(255) NOT NULL,
  firma           VARCHAR(120) NOT NULL DEFAULT '',
  vorname         VARCHAR(80)  NOT NULL DEFAULT '',
  nachname        VARCHAR(80)  NOT NULL DEFAULT '',
  strasse         VARCHAR(150) NOT NULL DEFAULT '',
  plz             VARCHAR(20)  NOT NULL DEFAULT '',
  ort             VARCHAR(100) NOT NULL DEFAULT '',
  land            VARCHAR(60)  NOT NULL DEFAULT 'Deutschland',
  telefon         VARCHAR(60)  NOT NULL DEFAULT '',
  ustid           VARCHAR(40)  NOT NULL DEFAULT '',
  lieferadresse   TEXT         NULL,
  rabatt_prozent  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  gesperrt        TINYINT(1)   NOT NULL DEFAULT 0,
  email_bestaetigt TINYINT(1)  NOT NULL DEFAULT 0,
  token           VARCHAR(64)  NULL,
  token_zweck     VARCHAR(20)  NULL,
  token_bis       DATETIME     NULL,
  notiz           TEXT         NULL,
  -- Übernahme aus dem alten Shop: Kundennummer dort, und ob noch ein neues Passwort festgelegt werden muss
  altkunde_nr     VARCHAR(40)  NOT NULL DEFAULT '',
  passwort_neu_noetig TINYINT(1) NOT NULL DEFAULT 0,
  eingeladen      DATETIME     NULL,
  preisgruppe_id  INT UNSIGNED NULL,                    -- Kundengruppe mit eigenen Preisen (z. B. "Händler 30%")
  quelle          VARCHAR(20)  NOT NULL DEFAULT 'shop',  -- 'shop' = im neuen Shop registriert, 'altshop' = übernommen
  erstellt        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  letzter_login   DATETIME     NULL,
  INDEX (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bestellungen (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nummer       VARCHAR(32) NOT NULL UNIQUE,
  kunde_id     INT UNSIGNED NULL,
  email        VARCHAR(190) NOT NULL,
  anschrift    TEXT NOT NULL,
  lieferadresse TEXT NULL,
  bemerkung    TEXT NULL,
  versandart   VARCHAR(20) NOT NULL DEFAULT 'dhl',
  rabatt_prozent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  netto        DECIMAL(10,2) NOT NULL,
  versand      DECIMAL(10,2) NOT NULL,
  mwst         DECIMAL(10,2) NOT NULL,
  gesamt       DECIMAL(10,2) NOT NULL,
  status       VARCHAR(20) NOT NULL DEFAULT 'neu',
  quelle       VARCHAR(20) NOT NULL DEFAULT 'shop',      -- 'shop' = neue Seite, 'altshop' = aus dem alten Shop übernommen
  erstellt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (kunde_id), INDEX (erstellt),
  CONSTRAINT fk_bestellung_kunde FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positionen (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bestellung_id INT UNSIGNED NOT NULL,
  sku           VARCHAR(60) NOT NULL,
  bezeichnung   VARCHAR(255) NOT NULL,
  auswahl       VARCHAR(190) NOT NULL DEFAULT '',
  menge         INT UNSIGNED NOT NULL,
  einzelpreis   DECIMAL(10,2) NOT NULL,
  gesamtpreis   DECIMAL(10,2) NOT NULL,
  INDEX (bestellung_id),
  CONSTRAINT fk_position_bestellung FOREIGN KEY (bestellung_id) REFERENCES bestellungen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fehlversuche bei der Anmeldung (Schutz gegen Passwort-Raten)
CREATE TABLE IF NOT EXISTS anmeldeversuche (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kennung VARCHAR(190) NOT NULL,
  zeit   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (kennung, zeit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktuelle Verkaufspreise (netto). Ohne Eintrag gilt der Preis aus preise.json.
-- preis = NULL bedeutet "Preis auf Anfrage".
CREATE TABLE IF NOT EXISTS preise (
  sku           VARCHAR(60) NOT NULL PRIMARY KEY,
  preis         DECIMAL(10,2) NULL,
  geaendert     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  geaendert_von VARCHAR(190) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jede Preisänderung wird protokolliert (alter Preis, neuer Preis, wer, wann)
CREATE TABLE IF NOT EXISTS preis_protokoll (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku       VARCHAR(60) NOT NULL,
  alt       DECIMAL(10,2) NULL,
  neu       DECIMAL(10,2) NULL,
  wer       VARCHAR(190) NOT NULL,
  zeit      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (sku), INDEX (zeit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kundenindividuelle Artikelpreise (netto). Gehen vor Listenpreis und Prozent-Rabatt.
CREATE TABLE IF NOT EXISTS kundenpreise (
  kunde_id  INT UNSIGNED NOT NULL,
  sku       VARCHAR(60) NOT NULL,
  preis     DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (kunde_id, sku),
  CONSTRAINT fk_kundenpreis_kunde FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kundengruppen mit eigenen Artikelpreisen (aus dem alten Shop: "22 Prozent", "Händler 30%", "Interessenten 15%")
CREATE TABLE IF NOT EXISTS preisgruppen (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preisgruppen_preise (
  gruppe_id INT UNSIGNED NOT NULL,
  sku       VARCHAR(60) NOT NULL,
  preis     DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (gruppe_id, sku),
  CONSTRAINT fk_gruppenpreis_gruppe FOREIGN KEY (gruppe_id) REFERENCES preisgruppen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
