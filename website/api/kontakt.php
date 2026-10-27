<?php
/** TRIPLEX-Q – Kontaktformular per E-Mail. Ohne PHP greift im Browser der mailto-Fallback. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_aus(['ok' => false], 405);
}
$d = eingabe();
if (!empty($d['website'])) {
    json_aus(['ok' => true]);                      // Spam-Falle
}
$email = filter_var(text($d['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$name = text($d['name'] ?? '', 120);
$nachricht = mehrzeilig($d['nachricht'] ?? '', 2000);
if (!$email || $name === '' || trim($nachricht) === '') {
    json_aus(['ok' => false, 'meldung' => 'Bitte Name, E-Mail und Nachricht ausfüllen.'], 422);
}

$text = "Name: $name\nFirma: " . text($d['firma'] ?? '', 120) . "\nE-Mail: $email\nTelefon: "
    . text($d['telefon'] ?? '', 60) . "\n\n$nachricht\n";
// Feste Empfänger je Anfrage-Art – nie eine Adresse aus dem Formular übernehmen (sonst Spam-Schleuder)
const KONTAKT_PARTNER = ['s-tec' => ['m.semenov@s-tec-werkzeuge.de', 'S-TEC Kundenservice (Maxim Semenov)']];
$an = text($d['an'] ?? '', 20);
$betreff = 'Anfrage über triplex-q.com: ' . (text($d['betreff'] ?? '', 120) ?: 'Kontakt');
if (isset(KONTAKT_PARTNER[$an])) {
    [$partnerMail, $partnerName] = KONTAKT_PARTNER[$an];
    $ok = mail_senden($partnerMail, $betreff, "Neue Anfrage für $partnerName über www.triplex-q.com

" . $text, $email);
    // Kopie an TRIPLEX-Q, damit dort sichtbar ist, was an den Partner geht
    mail_senden(EMPFAENGER_KONTAKT, '[Kopie an S-TEC] ' . $betreff, "Diese Anfrage wurde an $partnerName ($partnerMail) weitergeleitet.

" . $text, $email);
} else {
    $ok = mail_senden(EMPFAENGER_KONTAKT, $betreff, $text, $email);
}
json_aus(['ok' => $ok], $ok ? 200 : 500);
