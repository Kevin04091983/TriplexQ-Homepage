<?php
/**
 * TRIPLEX-Q – Funktionen der Übernahme aus dem alten Shop (CSV lesen, Spalten zuordnen, übernehmen).
 * Genutzt von admin/uebernahme.php (Verwaltung) und beim Einspielen direkt auf dem Server.
 * Gibt selbst nichts aus; setzt db.php voraus.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {   // nur zum Einbinden, nicht direkt aufrufbar
    http_response_code(404);
    exit;
}

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

/**
 * Bestellungen aus dem XML-Export des alten Shops (Bestellungen → markieren → Exportieren).
 * Übernimmt die Beträge exakt (Artikel, Versand, Rabatt, MwSt., Gesamt). Kunde über die alte
 * Kundennummer oder die E-Mail-Adresse; Gastbestellungen ohne Konto werden ohne Kunde gespeichert.
 * Nummer im neuen Shop: "ALT-<alte Nummer>" – doppeltes Einlesen legt nichts doppelt an.
 */
function bestellungen_xml_uebernehmen(string $pfad, bool $nurPruefen): array
{
    $bericht = ['neu' => 0, 'aktualisiert' => 0, 'uebersprungen' => 0, 'ohne_konto' => 0, 'umsatz_netto' => 0.0, 'hinweise' => []];
    $alt = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($pfad, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($alt);
    if (!$xml || !isset($xml->Order)) {
        throw new RuntimeException('Keine Bestell-XML aus dem alten Shop (Element <Order> fehlt).');
    }
    $t = static fn($x) => trim((string) $x);
    $n = static fn($x) => round((float) str_replace(',', '.', (string) $x), 2);
    $adresse = static function ($a) use ($t): string {
        if (!$a) { return ''; }
        return trim(implode("\n", array_filter([
            $t($a->Company), trim($t($a->FirstName) . ' ' . $t($a->LastName)), $t($a->Street),
            trim($t($a->Zipcode) . ' ' . $t($a->City)), $t($a->Country) !== 'DE' ? land($t($a->Country)) : '',
        ])));
    };
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($xml->Order as $o) {
            $bnr = $t($o->OrderNumber);
            if ($bnr === '') { continue; }
            $nummer = 'ALT-' . mb_substr($bnr, 0, 27);
            $s = $pdo->prepare('SELECT id FROM bestellungen WHERE nummer = ?');
            $s->execute([$nummer]);
            if ($s->fetch()) { $bericht['uebersprungen']++; continue; }

            $rech = $o->Addresses->BillingAddress;
            $lief = $o->Addresses->ShippingAddress;
            $email = mb_strtolower($t($rech->EMail));
            $k = kunde_finden($email, $t($o->CustomerNumber));
            if (!$k) { $bericht['ohne_konto']++; }

            $positionen = [];
            $waren = 0.0;
            foreach ($o->LineItems->LineItem as $li) {
                $menge = max(1, (int) round((float) $li->Quantity));
                $gesamt = $n($li->TotalPrice);
                $positionen[] = [mb_substr($t($li->Id), 0, 60), mb_substr($t($li->Name), 0, 255), mb_substr($t($li->VariationString), 0, 190),
                    $menge, $n($li->UnitPrice), $gesamt];
                $waren += $gesamt;
            }
            foreach ($o->LineItems->LineItemDiscount as $d) {
                if ($n($d->TotalPrice) != 0.0) {
                    $positionen[] = ['RABATT', 'Rabatt', '', 1, $n($d->TotalPrice), $n($d->TotalPrice)];
                    $waren += $n($d->TotalPrice);
                }
            }
            $versand = 0.0;
            $versandName = 'post';
            foreach ($o->LineItems->LineItemShipping as $v) {
                $versand += $n($v->TotalPrice);
                $versandName = mb_substr(mb_strtolower($t($v->Id)), 0, 20) ?: 'post';
            }
            $zahlung = 0.0;
            foreach ($o->LineItems->LineItemPayment as $z) {
                if ($n($z->TotalPrice) != 0.0) {
                    $positionen[] = ['ZAHLUNG', 'Zahlungsgebühr ' . $t($z->Name), '', 1, $n($z->TotalPrice), $n($z->TotalPrice)];
                    $zahlung += $n($z->TotalPrice);
                }
            }
            $rechAdr = $adresse($rech);
            $liefAdr = $adresse($lief);
            $bemerkung = 'Bestellung aus dem alten Shop (Nr. ' . $bnr . ')'
                . ($t($rech->VATID) !== '' ? "\nUSt-IdNr.: " . $t($rech->VATID) : '')
                . ($t($o->CustomerComment) !== '' ? "\nAnmerkung des Kunden: " . $t($o->CustomerComment) : '');
            $datum = str_replace('T', ' ', mb_substr($t($o->CreationDate), 0, 19)) ?: date('Y-m-d H:i:s');
            $netto = round($waren + $zahlung, 2);
            $mwst = $n($o->TotalTax);
            $summe = $n($o->GrandTotal);
            if (abs($netto + $versand + $mwst - $summe) > 0.05) {
                if (count($bericht['hinweise']) < 40) {
                    $bericht['hinweise'][] = "Bestellung $bnr: Summe der Positionen weicht ab – Gesamtbetrag aus dem alten Shop übernommen";
                }
            }
            $pdo->prepare('INSERT INTO bestellungen (nummer, kunde_id, email, anschrift, lieferadresse, bemerkung, versandart, netto, versand, mwst, gesamt, status, quelle, erstellt)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?, "abgeschlossen", "altshop", ?)')
                ->execute([$nummer, $k['id'] ?? null, $email ?: ($k['email'] ?? ''), $rechAdr, $liefAdr !== '' && $liefAdr !== $rechAdr ? $liefAdr : null,
                    $bemerkung, $versandName, $netto, $versand, $mwst, $summe, $datum]);
            $bid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO positionen (bestellung_id, sku, bezeichnung, auswahl, menge, einzelpreis, gesamtpreis) VALUES (?,?,?,?,?,?,?)');
            foreach ($positionen as $p) {
                $ins->execute([$bid, ...$p]);
            }
            $bericht['neu']++;
            $bericht['umsatz_netto'] += $netto;
        }
        $nurPruefen ? $pdo->rollBack() : $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $bericht['umsatz_netto'] = round($bericht['umsatz_netto'], 2);
    return $bericht;
}
