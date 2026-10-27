<?php
/**
 * TRIPLEX-Q – Bestellung entgegennehmen.
 * Preise und Rabatt werden immer serverseitig gerechnet (aus preise.json und dem Kundenkonto),
 * Angaben des Browsers zu Preisen werden ignoriert.
 * Ist die Datenbank eingerichtet, wird die Bestellung zusätzlich gespeichert.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_aus(['ok' => false, 'meldung' => 'Nur POST'], 405);
}
$d = eingabe();
if (!isset($d['kunde'], $d['positionen']) || !is_array($d['positionen']) || !is_array($d['kunde'])) {
    json_aus(['ok' => false, 'meldung' => 'Ungültige Daten'], 400);
}
$k = $d['kunde'];
if (!empty($k['website'])) {
    json_aus(['ok' => true]);                       // Spam-Falle
}
foreach (['vorname', 'nachname', 'strasse', 'plz', 'ort'] as $pflicht) {
    if (text($k[$pflicht] ?? '') === '') {
        json_aus(['ok' => false, 'meldung' => 'Pflichtfeld fehlt: ' . $pflicht], 422);
    }
}

$konto = null;
try {
    $konto = angemeldeter_kunde();
} catch (Throwable $e) {
    $konto = null;                                   // ohne Datenbank: Gastbestellung
}
$email = filter_var(text($k['email'] ?? ($konto['email'] ?? '')), FILTER_VALIDATE_EMAIL);
if (!$email) {
    json_aus(['ok' => false, 'meldung' => 'E-Mail-Adresse ungültig'], 422);
}
$rabatt = $konto ? (float) $konto['rabatt_prozent'] : 0.0;

$preise = preise_aktuell();   // Listenpreise inkl. Änderungen aus der Verwaltung
$sonderpreise = $konto ? kundenpreise((int) $konto['id']) : [];
$zeilen = $positionen = [];
$netto = 0.0;
foreach (array_slice($d['positionen'], 0, 200) as $p) {
    $sku = text($p['sku'] ?? '', 60);
    $menge = max(1, min(999, (int) ($p['menge'] ?? 1)));
    if (!isset($preise[$sku]) || $preise[$sku][0] === null) {
        continue;
    }
    [$listenpreis, $name, $auswahl] = $preise[$sku];
    $einzel = preis_fuer_kunde($konto, $sku, (float) $listenpreis, $sonderpreise);   // Sonderpreis > Rabatt > Liste
    $summe = round($einzel * $menge, 2);
    $netto += $summe;
    $zeilen[] = sprintf('%d x %s  %s%s  à %s = %s', $menge, $sku, $name, $auswahl ? " ($auswahl)" : '', euro($einzel), euro($summe));
    $positionen[] = [$sku, $name, (string) $auswahl, $menge, $einzel, $summe];
}
if (!$zeilen) {
    json_aus(['ok' => false, 'meldung' => 'Keine gültigen Artikel im Warenkorb'], 422);
}

$abholung = ($k['versand'] ?? '') === 'abholung';
$land = text($k['land'] ?? 'Deutschland', 60) ?: 'Deutschland';
$ausland = !$abholung && $land !== 'Deutschland';
$versand = ($abholung || $ausland) ? 0.0 : VERSAND_NETTO;      // Ausland: Versandkosten auf Anfrage
$mwst = round(($netto + $versand) * MWST, 2);
$gesamt = round($netto + $versand + $mwst, 2);
$nummer = 'TQ-' . date('ymd-His') . '-' . random_int(10, 99);

$anschrift = trim(text($k['firma'] ?? '', 120) . "\n" . text($k['vorname'], 80) . ' ' . text($k['nachname'], 80) . "\n"
    . text($k['strasse'], 150) . "\n" . text($k['plz'], 20) . ' ' . text($k['ort'], 100) . "\n" . text($k['land'] ?? 'Deutschland', 60)
    . (text($k['ustid'] ?? '', 40) !== '' ? "\nUSt-IdNr.: " . text($k['ustid'], 40) : ''));
$lieferadresse = mehrzeilig($k['lieferadresse'] ?? '', 600);
$bemerkung = mehrzeilig($k['bemerkung'] ?? '', 1000);

$text = "BESTELLUNG $nummer über " . SEITEN_URL . "\n\nARTIKEL\n" . implode("\n", $zeilen) . "\n\n"
    . ($rabatt > 0 ? 'Kundenrabatt: ' . number_format($rabatt, 2, ',', '.') . " % (bereits in den Einzelpreisen enthalten)\n" : '')
    . ($sonderpreise ? "Kundenindividuelle Preise berücksichtigt\n" : '')
    . 'Zwischensumme netto: ' . euro($netto) . "\n"
    . ($abholung ? "Versand: Selbstabholung\n" : ($ausland ? "Versand nach $land: auf Anfrage (bitte Kosten mitteilen)\n" : 'Versand: ' . euro($versand) . "\n"))
    . 'MwSt. 19 %: ' . euro($mwst) . "\n"
    . 'Gesamt: ' . euro($gesamt) . "\n\n"
    . "RECHNUNGSADRESSE\n" . $anschrift . "\n\nE-Mail: $email\nTelefon: " . text($k['telefon'] ?? '-', 60) . "\n"
    . ($konto ? "Kundenkonto: Nr. {$konto['id']}\n" : "Gastbestellung (kein Kundenkonto)\n")
    . ($lieferadresse !== '' ? "\nLIEFERADRESSE\n$lieferadresse\n" : '')
    . ($bemerkung !== '' ? "\nBEMERKUNG / BESTELLNUMMER DES KUNDEN\n$bemerkung\n" : '')
    . "\nZahlungsart: Rechnung\n";

$gespeichert = false;
try {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO bestellungen (nummer, kunde_id, email, anschrift, lieferadresse, bemerkung, versandart, rabatt_prozent, netto, versand, mwst, gesamt)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$nummer, $konto['id'] ?? null, $email, $anschrift, $lieferadresse ?: null, $bemerkung ?: null,
            $abholung ? 'abholung' : 'dhl', $rabatt, $netto, $versand, $mwst, $gesamt]);
    $id = (int) $pdo->lastInsertId();
    $s = $pdo->prepare('INSERT INTO positionen (bestellung_id, sku, bezeichnung, auswahl, menge, einzelpreis, gesamtpreis) VALUES (?,?,?,?,?,?,?)');
    foreach ($positionen as $p) {
        $s->execute([$id, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]]);
    }
    $pdo->commit();
    $gespeichert = true;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// jede Empfänger-Adresse bekommt eine eigene Mail (order@ und wareneingang@) – zuverlässiger als eine Mail an mehrere
$ok = false;
foreach (array_filter(array_map('trim', explode(',', EMPFAENGER_BESTELLUNG))) as $empfaenger) {
    $ok = mail_senden($empfaenger, "Bestellung $nummer – " . (text($k['firma'] ?? '', 120) ?: text($k['nachname'], 80)),
        $text . ($gespeichert ? '' : "\nHINWEIS: Diese Bestellung konnte nicht in der Datenbank gespeichert werden.\n"), $email) || $ok;
}

mail_senden($email, "Ihre Bestellung $nummer bei TRIPLEX-Q",
    'Guten Tag ' . text($k['vorname'], 80) . ' ' . text($k['nachname'], 80) . ",\n\n"
    . "vielen Dank für Ihre Bestellung. Wir haben sie erhalten und bearbeiten sie umgehend.\n"
    . "Die Rechnung erhalten Sie mit der Lieferung.\n\n" . $text
    . "\nMit freundlichen Grüßen\nPräzisionswerkzeuge Schmidt e.K. – Home of TRIPLEX-Q\n"
    . "Ackerstraße 12, 98544 Zella-Mehlis\nTel. +49 (0) 3682 / 483662\n", trim((string) strtok(EMPFAENGER_BESTELLUNG, ',')));

json_aus(['ok' => $ok || $gespeichert, 'nummer' => $nummer, 'gespeichert' => $gespeichert,
    'meldung' => ($ok || $gespeichert) ? '' : 'Bestellung konnte nicht zugestellt werden.'], ($ok || $gespeichert) ? 200 : 500);
