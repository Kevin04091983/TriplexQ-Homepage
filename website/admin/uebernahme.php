<?php
/**
 * TRIPLEX-Q – Übernahme aus dem alten Shop: Kunden, alte Bestellungen, Kundenpreise; Einladungen verschicken.
 * Ablauf je Datei: CSV hochladen → Vorschau mit erkannter Spaltenzuordnung (änderbar) → übernehmen.
 * Mehrfaches Einlesen derselben Datei legt nichts doppelt an.
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/db.php';
sitzung_start();
if (empty($_SESSION['admin'])) {
    header('Location: ./');
    exit;
}
@set_time_limit(300);

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------------ Felder und typische Spaltennamen */
const FELDER = [
    'kunden' => [
        'email' => ['E-Mail (Rechnung)', ['billingaddress.email', 'e-mail', 'email', 'e-mail-adresse', 'emailadresse', 'mail']],
        'login_email' => ['E-Mail (Login)', ['user.email']],
        'altkunde_nr' => ['Kundennummer (alt)', ['alias', 'kundennummer', 'kunden-nr', 'kunden-nr.', 'kundennr', 'customernumber', 'bezeichner']],
        'firma' => ['Firma', ['billingaddress.company', 'firma', 'firmenname', 'company']],
        'vorname' => ['Vorname', ['billingaddress.firstname', 'vorname', 'firstname']],
        'nachname' => ['Nachname', ['billingaddress.lastname', 'nachname', 'lastname', 'familienname']],
        'strasse' => ['Straße', ['billingaddress.street', 'straße u. haus-nr.', 'straße', 'strasse', 'street', 'adresse']],
        'hausnummer' => ['Hausnummer', ['hausnummer', 'hausnr', 'hausnr.', 'streetnumber']],
        'plz' => ['PLZ', ['billingaddress.zipcode', 'plz', 'postleitzahl', 'zipcode']],
        'ort' => ['Ort', ['billingaddress.city', 'ort', 'stadt', 'city']],
        'land' => ['Land', ['billingaddress.country', 'land', 'country']],
        'telefon' => ['Telefon', ['billingaddress.phone', 'telefon', 'tel', 'phone']],
        'ustid' => ['USt-IdNr.', ['billingaddress.vatid', 'ust-idnr', 'ust-idnr.', 'ustidnr', 'ust-id', 'umsatzsteuer-id', 'vatid']],
        'rabatt' => ['Rabatt %', ['rabatt', 'rabatt %', 'kundenrabatt', 'discount']],
        'kundengruppe' => ['Kundengruppe', ['customergroup', 'kundengruppe', 'gruppe']],
        'liefer_firma' => ['Lieferadresse Firma', ['shippingaddress.company']],
        'liefer_vorname' => ['Lieferadresse Vorname', ['shippingaddress.firstname']],
        'liefer_nachname' => ['Lieferadresse Nachname', ['shippingaddress.lastname']],
        'liefer_strasse' => ['Lieferadresse Straße', ['shippingaddress.street']],
        'liefer_plz' => ['Lieferadresse PLZ', ['shippingaddress.zipcode']],
        'liefer_ort' => ['Lieferadresse Ort', ['shippingaddress.city']],
        'liefer_land' => ['Lieferadresse Land', ['shippingaddress.country']],
    ],
    'bestellungen' => [
        'nummer' => ['Bestellnummer', ['bestellnummer', 'bestell-nr', 'bestell-nr.', 'auftragsnummer', 'ordernumber', 'order number', 'bestellung']],
        'datum' => ['Bestelldatum', ['bestelldatum', 'datum', 'erstellt', 'creationdate', 'orderdate', 'bestellt am']],
        'email' => ['E-Mail', ['e-mail', 'email', 'kunden-e-mail', 'e-mail-adresse']],
        'altkunde_nr' => ['Kundennummer', ['kundennummer', 'kunden-nr', 'kundennr', 'customernumber']],
        'sku' => ['Artikelnummer', ['artikelnummer', 'artikel-nr', 'artikel-nr.', 'produktnummer', 'productnumber', 'sku']],
        'bezeichnung' => ['Artikelname', ['artikelname', 'artikel', 'bezeichnung', 'produktname', 'productname', 'name']],
        'menge' => ['Menge', ['menge', 'anzahl', 'quantity', 'stück']],
        'einzelpreis' => ['Einzelpreis', ['einzelpreis', 'stückpreis', 'preis', 'unitprice', 'price']],
        'gesamtpreis' => ['Positionspreis', ['gesamtpreis', 'positionspreis', 'summe position', 'linetotal', 'zeilensumme']],
        'status' => ['Status', ['status', 'bestellstatus']],
    ],
    'preise' => [
        'email' => ['E-Mail', ['e-mail', 'email', 'e-mail-adresse']],
        'altkunde_nr' => ['Kundennummer', ['kundennummer', 'kunden-nr', 'kundennr', 'customernumber']],
        'sku' => ['Artikelnummer', ['artikelnummer', 'artikel-nr', 'artikel-nr.', 'produktnummer', 'sku']],
        'preis' => ['Preis netto', ['preis', 'kundenpreis', 'sonderpreis', 'preis netto', 'nettopreis', 'price']],
    ],
];
const TITEL = ['kunden' => 'Kunden', 'gruppenpreise' => 'Kundengruppen-Preise', 'bestellungen' => 'Alte Bestellungen', 'preise' => 'Einzelne Kundenpreise'];
const PFLICHT = ['kunden' => ['email'], 'bestellungen' => ['nummer', 'sku', 'menge'], 'preise' => ['sku', 'preis'], 'gruppenpreise' => []];

const LAENDER_KUERZEL = ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'NL' => 'Niederlande', 'BE' => 'Belgien',
    'FR' => 'Frankreich', 'IT' => 'Italien', 'ES' => 'Spanien', 'PL' => 'Polen', 'CZ' => 'Tschechien', 'DK' => 'Dänemark',
    'SE' => 'Schweden', 'FI' => 'Finnland', 'LU' => 'Luxemburg', 'HU' => 'Ungarn', 'SI' => 'Slowenien', 'SK' => 'Slowakei',
    'PT' => 'Portugal', 'IE' => 'Irland', 'GR' => 'Griechenland', 'GB' => 'Vereinigtes Königreich', 'UK' => 'Vereinigtes Königreich',
    'RO' => 'Rumänien', 'BG' => 'Bulgarien', 'HR' => 'Kroatien', 'EE' => 'Estland', 'LV' => 'Lettland', 'LT' => 'Litauen',
    'MT' => 'Malta', 'CY' => 'Zypern'];

/* ------------------------------------------------------------------ CSV lesen (UTF-8 oder Excel/Windows, ; , oder Tab) */
function csv_lesen(string $pfad): array
{
    $inhalt = (string) file_get_contents($pfad);
    $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt);
    if (!mb_check_encoding($inhalt, 'UTF-8')) {
        $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    }
    $erste = strtok($inhalt, "\r\n") ?: '';
    $trenner = ';';
    $max = -1;
    foreach ([';', ',', "\t"] as $t) {
        if (substr_count($erste, $t) > $max) {
            $max = substr_count($erste, $t);
            $trenner = $t;
        }
    }
    $f = fopen('php://temp', 'r+');
    fwrite($f, $inhalt);
    rewind($f);
    $zeilen = [];
    while (($z = fgetcsv($f, 0, $trenner, '"', '')) !== false) {
        if ($z === [null] || implode('', array_map('trim', $z)) === '') {
            continue;
        }
        $zeilen[] = array_map(static fn($w) => trim((string) $w), $z);
    }
    fclose($f);
    $kopf = array_shift($zeilen) ?? [];
    return [$kopf, $zeilen];
}

function norm(string $t): string
{
    return trim(mb_strtolower(preg_replace('/\s+/', ' ', str_replace(['_', ':'], [' ', ''], $t))));
}

/** "Straße u. Haus-Nr. [BillingAddress.Street]" → ["straße u. haus-nr.", "billingaddress.street"] */
function kopf_namen(string $k): array
{
    if (preg_match('/^(.*?)\s*\[(.+)\]\s*$/u', $k, $m)) {
        return [norm($m[1]), norm($m[2])];
    }
    return [norm($k), ''];
}

/** automatische Zuordnung Feld → Spaltenindex (technischer Name hat Vorrang, dann der deutsche) */
function zuordnen(string $art, array $kopf): array
{
    if (!isset(FELDER[$art])) {
        return [];
    }
    $map = [];
    $namen = array_map('kopf_namen', $kopf);
    foreach (FELDER[$art] as $feld => [, $synonyme]) {
        $map[$feld] = null;
        foreach ($synonyme as $syn) {
            foreach ([1, 0] as $teil) {
                foreach ($namen as $i => $n) {
                    if ($n[$teil] === $syn && !in_array($i, $map, true)) {
                        $map[$feld] = $i;
                        break 3;
                    }
                }
            }
        }
    }
    return $map;
}

function wert(array $zeile, ?int $i): string { return $i === null ? '' : trim($zeile[$i] ?? ''); }

function zahl(string $w): ?float
{
    $w = str_replace(['€', 'EUR', ' ', "\u{a0}"], '', $w);
    if ($w === '') { return null; }
    if (str_contains($w, ',')) { $w = str_replace(['.', ','], ['', '.'], $w); }
    return is_numeric($w) ? (float) $w : null;
}

function datum(string $w): string
{
    foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y'] as $format) {
        $d = DateTime::createFromFormat($format, substr($w, 0, 19));
        if ($d) { return $d->format('Y-m-d H:i:s'); }
    }
    $t = strtotime($w);
    return $t ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s');
}

function land(string $w): string
{
    $w = trim($w);
    if ($w === '') { return 'Deutschland'; }
    return LAENDER_KUERZEL[strtoupper($w)] ?? $w;
}

/** Kunde über E-Mail oder alte Kundennummer finden */
function kunde_finden(string $email, string $nr): ?array
{
    if ($email !== '') {
        $s = db()->prepare('SELECT * FROM kunden WHERE email = ?');
        $s->execute([mb_strtolower($email)]);
        if ($k = $s->fetch()) { return $k; }
    }
    if ($nr !== '') {
        $s = db()->prepare('SELECT * FROM kunden WHERE altkunde_nr = ? AND altkunde_nr <> ""');
        $s->execute([$nr]);
        if ($k = $s->fetch()) { return $k; }
    }
    return null;
}

/** Kundengruppe anlegen bzw. finden */
function preisgruppe_id(string $name): int
{
    $name = mb_substr(trim($name, " \t\"'"), 0, 80);
    db()->prepare('INSERT IGNORE INTO preisgruppen (name) VALUES (?)')->execute([$name]);
    $s = db()->prepare('SELECT id FROM preisgruppen WHERE name = ?');
    $s->execute([$name]);
    return (int) $s->fetchColumn();
}

/**
 * Gruppenpreise aus dem Strato-Export ("Kundenspezifische_Preise.csv"):
 *   Produkt;"Gruppe" (dreifach in Anführungszeichen);Menge;Preis;…
 *   Produkt = "Hauptprodukt/SubProducts/Artikelnummer" oder Artikelnummer.
 * Der Export setzt vor die erste Zeile Reste einer anderen Tabelle ("CrossSelling;83…") – das wird repariert.
 * Liefert [gruppe => [sku => preis]], Hinweise.
 */
function gruppenpreise_lesen(string $pfad): array
{
    $basis = preise_basis();
    $text = (string) file_get_contents($pfad);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $daten = [];
    $hinweise = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $nr => $zeile) {
        if (!preg_match('/^(.*?);"*([^";]+?)"*;(\d+);([\d.,]*);?/u', $zeile, $m) || $nr === 0 && str_contains($zeile, '[Class]')) {
            continue;
        }
        [$alles, $produkt, $gruppe, $menge, $preis] = $m;
        // "3XD-1.00-3.00/SubProducts/3XD-1.00" → "3XD-1.00"; Reste davor ("CrossSelling;…") abschneiden
        $sku = str_contains($produkt, '/SubProducts/') ? substr($produkt, strrpos($produkt, '/SubProducts/') + 13) : $produkt;
        if (str_contains($sku, ';')) {
            $sku = substr($sku, strrpos($sku, ';') + 1);
        }
        if (!isset($basis[$sku])) {
            // Reparatur: "8383100" (Rest der Vorzeile + 83100) → längste bekannte Artikelnummer am Ende
            for ($i = 1; $i < strlen($sku) - 3; $i++) {
                if (isset($basis[substr($sku, $i)])) {
                    $sku = substr($sku, $i);
                    break;
                }
            }
        }
        if (!isset($basis[$sku])) {
            $hinweise[] = 'Zeile ' . ($nr + 1) . ": Artikelnummer $sku gibt es im neuen Shop nicht";
            continue;
        }
        if ($preis === '' || (int) $menge !== 1) {
            if ($preis === '') { $hinweise[] = 'Zeile ' . ($nr + 1) . ": $sku / $gruppe ohne Preis – übersprungen"; }
            continue;
        }
        $daten[trim($gruppe)][(string) $sku] = (float) str_replace(['.', ','], ['', '.'], $preis);
    }
    return [$daten, $hinweise];
}

/* ------------------------------------------------------------------ Übernehmen */
function uebernehmen(string $art, array $zeilen, array $map, bool $nurPruefen): array
{
    $bericht = ['neu' => 0, 'aktualisiert' => 0, 'uebersprungen' => 0, 'hinweise' => []];
    $hinweis = static function (string $t) use (&$bericht) {
        if (count($bericht['hinweise']) < 40) { $bericht['hinweise'][] = $t; }
    };
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($art === 'kunden') {
            // Anmelde-Adresse = Login-E-Mail (falls vorhanden), sonst Rechnungs-E-Mail.
            // Kommt eine Adresse mehrfach vor, gewinnt die Zeile mit Login im alten Shop.
            $mailVon = static fn(array $z) => mb_strtolower(wert($z, $map['login_email'] ?? null) ?: wert($z, $map['email']));
            $beste = [];
            foreach ($zeilen as $nr => $z) {
                $m = $mailVon($z);
                if (!isset($beste[$m]) || (wert($z, $map['login_email'] ?? null) !== '' && wert($zeilen[$beste[$m]], $map['login_email'] ?? null) === '')) {
                    $beste[$m] = $nr;
                }
            }
            foreach ($zeilen as $nr => $z) {
                $zeileNr = $nr + 2;
                $email = $mailVon($z);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $bericht['uebersprungen']++;
                    $hinweis("Zeile $zeileNr: keine gültige E-Mail-Adresse (" . ($email ?: 'leer') . ')');
                    continue;
                }
                if ($beste[$email] !== $nr) {
                    $bericht['uebersprungen']++;
                    $hinweis("Zeile $zeileNr: $email kommt mehrfach vor – zusammengeführt (Zeile " . ($beste[$email] + 2) . ' verwendet)');
                    continue;
                }
                $strasse = trim(wert($z, $map['strasse']) . ' ' . wert($z, $map['hausnummer']));
                $rabatt = zahl(wert($z, $map['rabatt'])) ?? 0.0;
                $daten = [
                    'firma' => mb_substr(wert($z, $map['firma']), 0, 120), 'vorname' => mb_substr(wert($z, $map['vorname']), 0, 80),
                    'nachname' => mb_substr(wert($z, $map['nachname']), 0, 80), 'strasse' => mb_substr($strasse, 0, 150),
                    'plz' => mb_substr(wert($z, $map['plz']), 0, 20), 'ort' => mb_substr(wert($z, $map['ort']), 0, 100),
                    'land' => mb_substr(land(wert($z, $map['land'])), 0, 60), 'telefon' => mb_substr(wert($z, $map['telefon']), 0, 60),
                    'ustid' => mb_substr(wert($z, $map['ustid']), 0, 40), 'altkunde_nr' => mb_substr(wert($z, $map['altkunde_nr']), 0, 40),
                ];
                $gruppe = wert($z, $map['kundengruppe']);
                $gruppeId = $gruppe !== '' ? preisgruppe_id($gruppe) : null;
                $liefer = '';
                if (wert($z, $map['liefer_strasse'] ?? null) !== '') {
                    $liefer = trim(implode("\n", array_filter([
                        wert($z, $map['liefer_firma'] ?? null),
                        trim(wert($z, $map['liefer_vorname'] ?? null) . ' ' . wert($z, $map['liefer_nachname'] ?? null)),
                        wert($z, $map['liefer_strasse'] ?? null),
                        trim(wert($z, $map['liefer_plz'] ?? null) . ' ' . wert($z, $map['liefer_ort'] ?? null)),
                        land(wert($z, $map['liefer_land'] ?? null)),
                    ])));
                }
                $vorhanden = kunde_finden($email, '');
                if ($vorhanden) {
                    // bestehendes Konto: nur leere Felder ergänzen, Passwort nie anfassen
                    $setzen = [];
                    $werte = [];
                    foreach ($daten as $feld => $w) {
                        if ($w !== '' && trim((string) $vorhanden[$feld]) === '') {
                            $setzen[] = "$feld = ?";
                            $werte[] = $w;
                        }
                    }
                    if ($liefer !== '' && trim((string) $vorhanden['lieferadresse']) === '') {
                        $setzen[] = 'lieferadresse = ?';
                        $werte[] = $liefer;
                    }
                    if ($gruppeId && !$vorhanden['preisgruppe_id']) {
                        $setzen[] = 'preisgruppe_id = ?';
                        $werte[] = $gruppeId;
                    }
                    if ($rabatt > 0 && (float) $vorhanden['rabatt_prozent'] == 0.0) {
                        $setzen[] = 'rabatt_prozent = ?';
                        $werte[] = min(90, $rabatt);
                    }
                    if ($setzen) {
                        $werte[] = $vorhanden['id'];
                        $pdo->prepare('UPDATE kunden SET ' . implode(', ', $setzen) . ' WHERE id = ?')->execute($werte);
                        $bericht['aktualisiert']++;
                    } else {
                        $bericht['uebersprungen']++;
                    }
                    continue;
                }
                $pdo->prepare('INSERT INTO kunden (email, passwort_hash, firma, vorname, nachname, strasse, plz, ort, land, telefon, ustid,
                                   altkunde_nr, rabatt_prozent, passwort_neu_noetig, email_bestaetigt, quelle, lieferadresse, preisgruppe_id)
                               VALUES (?, "", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?)')
                    ->execute([$email, $daten['firma'], $daten['vorname'], $daten['nachname'], $daten['strasse'], $daten['plz'],
                        $daten['ort'], $daten['land'], $daten['telefon'], $daten['ustid'], $daten['altkunde_nr'], min(90, $rabatt),
                        'altshop', $liefer !== '' ? $liefer : null, $gruppeId]);
                $bericht['neu']++;
            }
        } elseif ($art === 'gruppenpreise') {
            [$daten, $hin] = gruppenpreise_lesen($GLOBALS['import_pfad']);
            foreach ($hin as $t) { $hinweis($t); }
            $bericht['uebersprungen'] = count($hin);
            $ins = $pdo->prepare('INSERT INTO preisgruppen_preise (gruppe_id, sku, preis) VALUES (?,?,?) ON DUPLICATE KEY UPDATE preis = VALUES(preis)');
            foreach ($daten as $gruppe => $preise) {
                $gid = preisgruppe_id($gruppe);
                foreach ($preise as $sku => $preis) {
                    $ins->execute([$gid, $sku, round($preis, 2)]);
                    $bericht['neu']++;
                }
                $hinweis("Kundengruppe „{$gruppe}“: " . count($preise) . ' Preise');
            }
        } elseif ($art === 'bestellungen') {
            $gruppen = [];
            foreach ($zeilen as $nr => $z) {
                $bnr = wert($z, $map['nummer']);
                if ($bnr === '') {
                    $hinweis('Zeile ' . ($nr + 2) . ': ohne Bestellnummer übersprungen');
                    continue;
                }
                $gruppen[$bnr][] = $z;
            }
            $basis = preise_basis();
            foreach ($gruppen as $bnr => $pos) {
                $bnr = (string) $bnr;                           // PHP macht aus "4711" als Schlüssel eine Zahl
                $nummer = 'ALT-' . mb_substr($bnr, 0, 27);
                $s = $pdo->prepare('SELECT id FROM bestellungen WHERE nummer = ?');
                $s->execute([$nummer]);
                if ($s->fetch()) {
                    $bericht['uebersprungen']++;
                    continue;                                   // schon übernommen
                }
                $erste = $pos[0];
                $k = kunde_finden(mb_strtolower(wert($erste, $map['email'])), wert($erste, $map['altkunde_nr']));
                if (!$k) {
                    $hinweis("Bestellung $bnr: Kunde nicht gefunden (" . (wert($erste, $map['email']) ?: wert($erste, $map['altkunde_nr']) ?: '?')
                        . ') – bitte zuerst die Kunden übernehmen');
                    $bericht['uebersprungen']++;
                    continue;
                }
                $netto = 0.0;
                $zeilenDaten = [];
                foreach ($pos as $z) {
                    $sku = wert($z, $map['sku']);
                    $menge = max(1, (int) round(zahl(wert($z, $map['menge'])) ?? 1));
                    $einzel = zahl(wert($z, $map['einzelpreis']));
                    $gesamt = zahl(wert($z, $map['gesamtpreis']));
                    if ($einzel === null && $gesamt !== null) { $einzel = round($gesamt / $menge, 2); }
                    if ($gesamt === null && $einzel !== null) { $gesamt = round($einzel * $menge, 2); }
                    $bez = wert($z, $map['bezeichnung']) ?: ($basis[$sku][1] ?? $sku);
                    $zeilenDaten[] = [$sku, mb_substr($bez, 0, 255), mb_substr($basis[$sku][2] ?? '', 0, 190), $menge, $einzel ?? 0.0, $gesamt ?? 0.0];
                    $netto += $gesamt ?? 0.0;
                }
                $anschrift = trim($k['firma'] . "\n" . $k['vorname'] . ' ' . $k['nachname'] . "\n" . $k['strasse'] . "\n" . $k['plz'] . ' ' . $k['ort']);
                $pdo->prepare('INSERT INTO bestellungen (nummer, kunde_id, email, anschrift, versandart, netto, versand, mwst, gesamt, status, quelle, erstellt, bemerkung)
                               VALUES (?,?,?,?, "dhl", ?, 0, ?, ?, "abgeschlossen", "altshop", ?, ?)')
                    ->execute([$nummer, $k['id'], $k['email'], $anschrift, round($netto, 2), round($netto * MWST, 2), round($netto * (1 + MWST), 2),
                        datum(wert($erste, $map['datum'])), 'Bestellung aus dem alten Shop (Nr. ' . $bnr . ')']);
                $bid = (int) $pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO positionen (bestellung_id, sku, bezeichnung, auswahl, menge, einzelpreis, gesamtpreis) VALUES (?,?,?,?,?,?,?)');
                foreach ($zeilenDaten as $zd) {
                    $ins->execute([$bid, ...$zd]);
                }
                $bericht['neu']++;
            }
        } else { // preise
            $basis = preise_basis();
            foreach ($zeilen as $nr => $z) {
                $zeileNr = $nr + 2;
                $sku = wert($z, $map['sku']);
                $preis = zahl(wert($z, $map['preis']));
                $k = kunde_finden(mb_strtolower(wert($z, $map['email'])), wert($z, $map['altkunde_nr']));
                if (!$k) {
                    $bericht['uebersprungen']++;
                    $hinweis("Zeile $zeileNr: Kunde nicht gefunden");
                    continue;
                }
                if (!isset($basis[$sku])) {
                    $bericht['uebersprungen']++;
                    $hinweis("Zeile $zeileNr: Artikelnummer $sku gibt es im neuen Shop nicht");
                    continue;
                }
                if ($preis === null || $preis <= 0) {
                    $bericht['uebersprungen']++;
                    $hinweis("Zeile $zeileNr: ungültiger Preis");
                    continue;
                }
                $pdo->prepare('INSERT INTO kundenpreise (kunde_id, sku, preis) VALUES (?,?,?) ON DUPLICATE KEY UPDATE preis = VALUES(preis)')
                    ->execute([$k['id'], $sku, round($preis, 2)]);
                $bericht['neu']++;
            }
        }
        if ($nurPruefen) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $bericht;
}

/* ------------------------------------------------------------------ Anfragen verarbeiten */
$meldung = '';
$fehler = '';
$bericht = null;
$art = array_key_exists($_GET['art'] ?? '', TITEL) ? $_GET['art'] : 'kunden';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $fehler = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } elseif (($_POST['tun'] ?? '') === 'hochladen' && is_uploaded_file($_FILES['datei']['tmp_name'] ?? '')) {
        $ziel = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tq-import-' . bin2hex(random_bytes(8)) . '.csv';
        move_uploaded_file($_FILES['datei']['tmp_name'], $ziel);
        $_SESSION['import'][$art] = ['pfad' => $ziel, 'name' => (string) $_FILES['datei']['name']];
        header('Location: ?art=' . $art);
        exit;
    } elseif (in_array($_POST['tun'] ?? '', ['pruefen', 'uebernehmen'], true) && !empty($_SESSION['import'][$art]['pfad'])) {
        [$kopf, $zeilen] = csv_lesen($_SESSION['import'][$art]['pfad']);
        $GLOBALS['import_pfad'] = $_SESSION['import'][$art]['pfad'];
        $map = [];
        foreach (FELDER[$art] ?? [] as $feld => $x) {
            $v = $_POST['map'][$feld] ?? '';
            $map[$feld] = $v === '' ? null : (int) $v;
        }
        $fehlt = array_filter(PFLICHT[$art], static fn($f) => $map[$f] === null);
        if ($fehlt) {
            $fehler = 'Bitte zuordnen: ' . implode(', ', array_map(static fn($f) => FELDER[$art][$f][0], $fehlt));
        } else {
            try {
                $bericht = uebernehmen($art, $zeilen, $map, $_POST['tun'] === 'pruefen');
                $meldung = $_POST['tun'] === 'pruefen'
                    ? 'Probelauf – es wurde noch nichts gespeichert.'
                    : TITEL[$art] . ' übernommen.';
                if ($_POST['tun'] === 'uebernehmen') {
                    @unlink($_SESSION['import'][$art]['pfad']);
                    unset($_SESSION['import'][$art]);
                }
            } catch (Throwable $e) {
                $fehler = 'Übernahme abgebrochen, es wurde nichts gespeichert: ' . $e->getMessage();
            }
        }
    } elseif (($_POST['tun'] ?? '') === 'verwerfen') {
        @unlink($_SESSION['import'][$art]['pfad'] ?? '');
        unset($_SESSION['import'][$art]);
    } elseif (($_POST['tun'] ?? '') === 'einladen') {
        $offen = db()->query('SELECT * FROM kunden WHERE passwort_neu_noetig = 1 AND eingeladen IS NULL AND gesperrt = 0 ORDER BY id LIMIT 50')->fetchAll();
        $n = 0;
        foreach ($offen as $k) {
            passwort_link_senden($k, true);
            db()->prepare('UPDATE kunden SET eingeladen = NOW() WHERE id = ?')->execute([$k['id']]);
            $n++;
        }
        $meldung = "$n Einladung(en) verschickt.";
    }
}

$zahlen = db()->query('SELECT
    SUM(passwort_neu_noetig = 1) AS offen,
    SUM(passwort_neu_noetig = 1 AND eingeladen IS NULL) AS nicht_eingeladen,
    SUM(quelle = "altshop") AS altkunden,
    SUM(passwort_neu_noetig = 0 AND quelle = "altshop") AS aktiv
  FROM kunden')->fetch();
$altBestellungen = (int) db()->query('SELECT COUNT(*) FROM bestellungen WHERE quelle = "altshop"')->fetchColumn();
$sonderpreise = (int) db()->query('SELECT COUNT(*) FROM kundenpreise')->fetchColumn();
$gruppenpreise = (int) db()->query('SELECT COUNT(*) FROM preisgruppen_preise')->fetchColumn();
$ohneGruppe = (int) db()->query('SELECT COUNT(*) FROM kunden WHERE preisgruppe_id IS NULL AND quelle = "altshop"')->fetchColumn();

$upload = $_SESSION['import'][$art] ?? null;
$kopf = $zeilen = [];
$map = [];
if ($upload && is_file($upload['pfad'])) {
    [$kopf, $zeilen] = csv_lesen($upload['pfad']);
    $map = zuordnen($art, $kopf);
    if (!empty($_POST['map'])) {
        foreach (FELDER[$art] ?? [] as $feld => $x) {
            $v = $_POST['map'][$feld] ?? '';
            $map[$feld] = $v === '' ? null : (int) $v;
        }
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Übernahme alter Shop | TRIPLEX-Q</title>
<link rel="icon" href="../assets/img/logo/favicon-32.png">
<style>
  :root { --blau:#003b9f; --navy:#06163f; --rot:#e40001; --grau:#f4f7fc; --linie:#dde4f0; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.5 system-ui,Segoe UI,sans-serif; background:var(--grau); color:#0c1830; }
  header { background:var(--navy); color:#fff; padding:14px 22px; display:flex; align-items:center; gap:22px; flex-wrap:wrap; }
  header b { font-size:1.15rem; }
  header a { color:#c9d5f2; text-decoration:none; padding:6px 12px; border-radius:99px; }
  header a:hover, header a.aktiv { background:rgba(255,255,255,.14); color:#fff; }
  main { max-width:1200px; margin:26px auto; padding:0 18px; }
  h1 { font-size:1.6rem; margin:0 0 6px; }
  .karte { background:#fff; border:1px solid var(--linie); border-radius:12px; padding:18px 20px; margin-bottom:18px; }
  .schritte { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; }
  .schritte a { padding:9px 16px; border-radius:99px; border:1.5px solid var(--linie); background:#fff; text-decoration:none; color:#0c1830; font-weight:600; }
  .schritte a.aktiv { background:var(--navy); color:#fff; border-color:var(--navy); }
  .zahlen { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
  .zahlen div { background:var(--grau); border-radius:10px; padding:12px 14px; }
  .zahlen b { display:block; font-size:1.6rem; }
  table { width:100%; border-collapse:collapse; background:#fff; font-size:.9rem; }
  th, td { padding:8px 10px; border-bottom:1px solid var(--linie); text-align:left; vertical-align:top; }
  th { background:var(--navy); color:#fff; font-weight:600; }
  .rahmen { overflow:auto; border:1px solid var(--linie); border-radius:10px; max-height:420px; }
  select, input[type=file] { font:inherit; padding:7px 9px; border:1.5px solid var(--linie); border-radius:8px; background:#fff; }
  button { font:inherit; font-weight:600; padding:10px 18px; border:0; border-radius:99px; background:var(--blau); color:#fff; cursor:pointer; }
  button.rot { background:var(--rot); } button.grau { background:#7b879e; }
  .ok { background:#e7f5ec; border:1px solid #bfe3cd; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  .fehler { background:#fdeaea; border:1px solid #f3bcbc; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  .pos { color:#4a5876; font-size:.88rem; }
  .zuordnung { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px 18px; margin:12px 0 16px; }
  .zuordnung label { display:grid; gap:4px; font-size:.88rem; font-weight:600; }
  .zuordnung .pflicht { color:var(--rot); }
  .knoepfe { display:flex; gap:10px; flex-wrap:wrap; }
</style>
</head>
<body>
<header>
  <b>TRIPLEX-Q Verwaltung</b>
  <a href="./?seite=bestellungen">Bestellungen</a>
  <a href="./?seite=kunden">Kunden</a>
  <a href="./?seite=preise">Preise</a>
  <a href="uebernahme.php" class="aktiv">Übernahme alter Shop</a>
  <a href="../index.html" target="_blank">Zur Website</a>
</header>
<main>
  <h1>Übernahme aus dem alten Shop</h1>
  <p class="pos">Reihenfolge: 1. Kunden · 2. Kundengruppen-Preise · 3. Kundengruppen zuordnen (Verwaltung → Kunden) · 4. alte Bestellungen · 5. Einladungen verschicken.
    Jede Datei wird erst geprüft (Probelauf), dann übernommen. Doppeltes Einlesen legt nichts doppelt an; vorhandene Konten werden nur ergänzt, Passwörter nie überschrieben.</p>

  <?php if ($meldung): ?><p class="ok"><?= h($meldung) ?></p><?php endif; ?>
  <?php if ($fehler): ?><p class="fehler"><?= h($fehler) ?></p><?php endif; ?>

  <div class="karte zahlen">
    <div><b><?= (int) $zahlen['altkunden'] ?></b>übernommene Kunden</div>
    <div><b><?= (int) $zahlen['aktiv'] ?></b>davon mit neuem Passwort</div>
    <div><b><?= (int) $zahlen['offen'] ?></b>Passwort noch offen</div>
    <div><b><?= $altBestellungen ?></b>alte Bestellungen</div>
    <div><b><?= $gruppenpreise ?></b>Kundengruppen-Preise</div>
    <div><b><?= $ohneGruppe ?></b>Altkunden ohne Kundengruppe</div>
    <div><b><?= $sonderpreise ?></b>einzelne Kundenpreise</div>
  </div>

  <?php if ($bericht): ?>
  <div class="karte">
    <b><?= h($meldung) ?></b>
    <p><?= $bericht['neu'] ?> neu · <?= $bericht['aktualisiert'] ?> ergänzt · <?= $bericht['uebersprungen'] ?> übersprungen</p>
    <?php if ($bericht['hinweise']): ?><ul class="pos"><?php foreach ($bericht['hinweise'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="schritte">
    <?php foreach (TITEL as $a => $t): ?><a href="?art=<?= $a ?>" class="<?= $a === $art ? 'aktiv' : '' ?>"><?= h($t) ?></a><?php endforeach; ?>
  </nav>

  <div class="karte">
    <?php if (!$upload): ?>
      <form method="post" enctype="multipart/form-data" class="knoepfe" action="?art=<?= $art ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="tun" value="hochladen">
        <input type="file" name="datei" accept=".csv,text/csv,.txt" required>
        <button type="submit">CSV-Datei „<?= h(TITEL[$art]) ?>“ hochladen</button>
      </form>
      <p class="pos">Export aus dem alten Shop-Admin als CSV. Spalten werden automatisch erkannt und können vor der Übernahme angepasst werden.</p>
    <?php else: ?>
      <p><b><?= h($upload['name']) ?></b> – <?= count($zeilen) ?> Datenzeilen<?= $art !== 'gruppenpreise' ? ', ' . count($kopf) . ' Spalten' : '' ?></p>
      <form method="post" action="?art=<?= $art ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
        <?php if ($art === 'gruppenpreise'): [$gp, $gpHinweise] = gruppenpreise_lesen($upload['pfad']); ?>
        <p>Erkannte Kundengruppen:</p>
        <ul><?php foreach ($gp as $g => $pr): ?><li><b><?= h($g) ?></b>: <?= count($pr) ?> Artikelpreise</li><?php endforeach; ?></ul>
        <?php if ($gpHinweise): ?><p class="pos"><?= count($gpHinweise) ?> Hinweise, z. B.: <?= h(implode(' · ', array_slice($gpHinweise, 0, 4))) ?></p><?php endif; ?>
        <?php else: ?>
        <div class="zuordnung">
          <?php foreach (FELDER[$art] as $feld => [$titel]): ?>
          <label><?= h($titel) ?><?= in_array($feld, PFLICHT[$art], true) ? ' <span class="pflicht">*</span>' : '' ?>
            <select name="map[<?= $feld ?>]">
              <option value="">– nicht vorhanden –</option>
              <?php foreach ($kopf as $i => $k): ?><option value="<?= $i ?>" <?= $map[$feld] === $i ? 'selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?>
            </select>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="pos">Vorschau der ersten Zeilen mit dieser Zuordnung:</p>
        <div class="rahmen"><table>
          <tr><?php foreach (FELDER[$art] as $feld => [$titel]): if ($map[$feld] === null) continue; ?><th><?= h($titel) ?></th><?php endforeach; ?></tr>
          <?php foreach (array_slice($zeilen, 0, 8) as $z): ?>
          <tr><?php foreach (FELDER[$art] as $feld => $x): if ($map[$feld] === null) continue; ?><td><?= h(wert($z, $map[$feld])) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
        <p class="knoepfe" style="margin-top:16px">
          <button type="submit" name="tun" value="pruefen">Probelauf (nichts speichern)</button>
          <button type="submit" name="tun" value="uebernehmen" class="rot" onclick="return confirm('<?= h(TITEL[$art]) ?> jetzt übernehmen?')">Übernehmen</button>
          <button type="submit" name="tun" value="verwerfen" class="grau" formnovalidate>Andere Datei wählen</button>
        </p>
      </form>
    <?php endif; ?>
  </div>

  <div class="karte">
    <h2 style="margin-top:0">Einladungen verschicken</h2>
    <p>Übernommene Kunden bekommen eine E-Mail „Unser neuer TRIPLEX-Q Shop – bitte Passwort festlegen“ mit persönlichem Link (14 Tage gültig).
      Wer die Mail nicht nutzt, bekommt den Link automatisch, sobald er sich anmelden will.</p>
    <p><b><?= (int) $zahlen['nicht_eingeladen'] ?></b> Kunden noch nicht eingeladen. Versand in Paketen zu je 50 (Schutz vor Mail-Sperren).</p>
    <form method="post" onsubmit="return confirm('Jetzt bis zu 50 Einladungen verschicken?')">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
      <input type="hidden" name="tun" value="einladen">
      <button type="submit" class="rot" <?= (int) $zahlen['nicht_eingeladen'] === 0 ? 'disabled' : '' ?>>Nächste 50 Einladungen senden</button>
    </form>
  </div>
</main>
</body>
</html>
