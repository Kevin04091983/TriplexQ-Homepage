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
$ok = mail_senden(EMPFAENGER_KONTAKT, 'Anfrage über triplex-q.com: ' . (text($d['betreff'] ?? '', 120) ?: 'Kontakt'), $text, $email);
json_aus(['ok' => $ok], $ok ? 200 : 500);
