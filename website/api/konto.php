<?php
/**
 * TRIPLEX-Q – Kundenkonto: Registrierung, Anmeldung, Stammdaten, Bestellhistorie.
 * Alle Aufrufe per POST mit JSON, Antwort ebenfalls JSON.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$aktion = $_GET['aktion'] ?? '';
$d = eingabe();
sitzung_start();

/** Sicherheitskennung für Formulare (gegen fremde Seiten, die Aktionen auslösen) */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$schreibend = in_array($aktion, ['registrieren', 'anmelden', 'abmelden', 'profil', 'passwort', 'passwort-vergessen', 'passwort-neu'], true);
if ($schreibend && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_aus(['ok' => false, 'meldung' => 'Nur POST'], 405);
}
if ($schreibend && !in_array($aktion, ['anmelden', 'registrieren', 'passwort-vergessen', 'passwort-neu'], true)
    && !hash_equals($_SESSION['csrf'], (string) ($d['csrf'] ?? ''))) {
    json_aus(['ok' => false, 'meldung' => 'Sitzung abgelaufen, bitte Seite neu laden.'], 403);
}

try {
    db();
} catch (Throwable $e) {
    json_aus(['ok' => false, 'meldung' => 'Kundenkonto ist auf diesem Server noch nicht eingerichtet.'], 503);
}

function pflicht(array $d, array $felder): void
{
    foreach ($felder as $f) {
        if (text($d[$f] ?? '') === '') {
            json_aus(['ok' => false, 'meldung' => 'Bitte alle Pflichtfelder ausfüllen.', 'feld' => $f], 422);
        }
    }
}

switch ($aktion) {

    case 'ich':
        $k = angemeldeter_kunde();
        admin_sitzung($k);
        json_aus(['ok' => true, 'angemeldet' => (bool) $k, 'kunde' => $k ? kunde_oeffentlich($k) : null, 'csrf' => $_SESSION['csrf'],
            'csrf_admin' => !empty($_SESSION['admin']) ? $_SESSION['csrf_admin'] : null]);

    case 'registrieren':
        if (!empty($d['website'])) {
            json_aus(['ok' => true]);           // Spam-Falle
        }
        pflicht($d, ['email', 'passwort', 'vorname', 'nachname']);
        $email = filter_var(text($d['email']), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            json_aus(['ok' => false, 'meldung' => 'Bitte eine gültige E-Mail-Adresse angeben.', 'feld' => 'email'], 422);
        }
        if (mb_strlen((string) $d['passwort']) < 8) {
            json_aus(['ok' => false, 'meldung' => 'Das Passwort braucht mindestens 8 Zeichen.', 'feld' => 'passwort'], 422);
        }
        $vorhanden = db()->prepare('SELECT * FROM kunden WHERE email = ?');
        $vorhanden->execute([$email]);
        $alt = $vorhanden->fetch();
        if ($alt && (int) $alt['passwort_neu_noetig'] === 1) {
            passwort_link_senden($alt, true);
            json_aus(['ok' => false, 'passwort_neu' => true, 'feld' => 'email',
                'meldung' => 'Sie sind bereits Kunde bei uns – Ihr Konto mit Adressen, Bestellungen und Preisen ist schon da. '
                    . 'Wir haben Ihnen eine E-Mail mit einem Link geschickt, über den Sie Ihr neues Passwort festlegen.'], 409);
        }
        if ($alt) {
            json_aus(['ok' => false, 'meldung' => 'Für diese E-Mail-Adresse gibt es bereits ein Konto. Bitte anmelden oder Passwort zurücksetzen.', 'feld' => 'email'], 409);
        }
        $token = bin2hex(random_bytes(24));
        $s = db()->prepare('INSERT INTO kunden (email, passwort_hash, firma, vorname, nachname, strasse, plz, ort, land, telefon, ustid, token, token_zweck, token_bis)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"email",NOW() + INTERVAL 7 DAY)');
        $s->execute([$email, password_hash((string) $d['passwort'], PASSWORD_DEFAULT), text($d['firma'] ?? '', 120),
            text($d['vorname'], 80), text($d['nachname'], 80), text($d['strasse'] ?? '', 150), text($d['plz'] ?? '', 20),
            text($d['ort'] ?? '', 100), text($d['land'] ?? 'Deutschland', 60), text($d['telefon'] ?? '', 60), text($d['ustid'] ?? '', 40), $token]);
        $id = (int) db()->lastInsertId();
        $_SESSION['kunde_id'] = $id;
        session_regenerate_id(true);
        $link = SEITEN_URL . '/konto.html?bestaetigen=' . $token;
        mail_senden($email, 'Willkommen bei TRIPLEX-Q',
            "Guten Tag " . text($d['vorname'], 80) . " " . text($d['nachname'], 80) . ",\n\n"
            . "Ihr Kundenkonto bei TRIPLEX-Q ist angelegt. Bitte bestätigen Sie Ihre E-Mail-Adresse:\n$link\n\n"
            . "Sie können sich ab sofort anmelden und bestellen.\n\n"
            . "Mit freundlichen Grüßen\nPräzisionswerkzeuge Schmidt e.K. – Home of TRIPLEX-Q\n"
            . "Ackerstraße 12, 98544 Zella-Mehlis, Tel. +49 (0) 3682 / 483662\n");
        mail_senden(EMPFAENGER_BESTELLUNG, 'Neues Kundenkonto: ' . $email,
            "Neue Registrierung im Webshop\n\nE-Mail: $email\nFirma: " . text($d['firma'] ?? '', 120)
            . "\nName: " . text($d['vorname'], 80) . ' ' . text($d['nachname'], 80) . "\n");
        $k = angemeldeter_kunde();
        json_aus(['ok' => true, 'kunde' => kunde_oeffentlich($k), 'csrf' => $_SESSION['csrf']]);

    case 'anmelden':
        $email = filter_var(text($d['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $kennung = ($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . ($email ?: '?');
        if (gesperrt_wegen_versuchen($kennung)) {
            json_aus(['ok' => false, 'meldung' => 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.'], 429);
        }
        $s = db()->prepare('SELECT * FROM kunden WHERE email = ?');
        $s->execute([$email ?: '']);
        $k = $s->fetch();
        if ($k && (int) $k['passwort_neu_noetig'] === 1 && (int) $k['gesperrt'] === 0) {
            // Aus dem alten Shop übernommen: altes Passwort gibt es hier nicht – Link zum Festlegen schicken
            passwort_link_senden($k, true);
            json_aus(['ok' => false, 'passwort_neu' => true,
                'meldung' => 'Willkommen im neuen TRIPLEX-Q Shop! Ihr Konto mit Adressen, bisherigen Bestellungen und Ihren Preisen '
                    . 'ist bereits übernommen. Aus Sicherheitsgründen legen Sie bitte einmalig ein neues Passwort fest – '
                    . 'den Link dazu haben wir soeben an ' . $k['email'] . ' geschickt.']);
        }
        if (!$k || !password_verify((string) ($d['passwort'] ?? ''), $k['passwort_hash'])) {
            versuch_merken($kennung);
            usleep(400000);
            json_aus(['ok' => false, 'meldung' => 'E-Mail-Adresse oder Passwort stimmt nicht.'], 401);
        }
        if ((int) $k['gesperrt'] === 1) {
            json_aus(['ok' => false, 'meldung' => 'Dieses Konto ist gesperrt. Bitte wenden Sie sich an uns.'], 403);
        }
        session_regenerate_id(true);
        $_SESSION['kunde_id'] = (int) $k['id'];
        db()->prepare('UPDATE kunden SET letzter_login = NOW() WHERE id = ?')->execute([$k['id']]);
        admin_sitzung($k);
        json_aus(['ok' => true, 'kunde' => kunde_oeffentlich($k), 'csrf' => $_SESSION['csrf'],
            'csrf_admin' => !empty($_SESSION['admin']) ? $_SESSION['csrf_admin'] : null]);

    case 'abmelden':
        $_SESSION = [];
        session_destroy();
        json_aus(['ok' => true]);

    case 'profil':
        $k = angemeldeter_kunde();
        if (!$k) {
            json_aus(['ok' => false, 'meldung' => 'Bitte zuerst anmelden.'], 401);
        }
        pflicht($d, ['vorname', 'nachname']);
        db()->prepare('UPDATE kunden SET firma=?, vorname=?, nachname=?, strasse=?, plz=?, ort=?, land=?, telefon=?, ustid=?, lieferadresse=? WHERE id=?')
            ->execute([text($d['firma'] ?? '', 120), text($d['vorname'], 80), text($d['nachname'], 80), text($d['strasse'] ?? '', 150),
                text($d['plz'] ?? '', 20), text($d['ort'] ?? '', 100), text($d['land'] ?? 'Deutschland', 60), text($d['telefon'] ?? '', 60),
                text($d['ustid'] ?? '', 40), mehrzeilig($d['lieferadresse'] ?? '', 600), $k['id']]);
        json_aus(['ok' => true, 'kunde' => kunde_oeffentlich(angemeldeter_kunde())]);

    case 'passwort':
        $k = angemeldeter_kunde();
        if (!$k) {
            json_aus(['ok' => false, 'meldung' => 'Bitte zuerst anmelden.'], 401);
        }
        if (!password_verify((string) ($d['alt'] ?? ''), $k['passwort_hash'])) {
            json_aus(['ok' => false, 'meldung' => 'Das bisherige Passwort stimmt nicht.', 'feld' => 'alt'], 403);
        }
        if (mb_strlen((string) ($d['neu'] ?? '')) < 8) {
            json_aus(['ok' => false, 'meldung' => 'Das neue Passwort braucht mindestens 8 Zeichen.', 'feld' => 'neu'], 422);
        }
        db()->prepare('UPDATE kunden SET passwort_hash = ? WHERE id = ?')
            ->execute([password_hash((string) $d['neu'], PASSWORD_DEFAULT), $k['id']]);
        json_aus(['ok' => true, 'meldung' => 'Passwort geändert.']);

    case 'passwort-vergessen':
        $email = filter_var(text($d['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if ($email) {
            $s = db()->prepare('SELECT * FROM kunden WHERE email = ?');
            $s->execute([$email]);
            if ($k = $s->fetch()) {
                passwort_link_senden($k, (int) $k['passwort_neu_noetig'] === 1);
            }
        }
        // Immer dieselbe Antwort, damit man nicht herausfinden kann, welche Adressen ein Konto haben
        json_aus(['ok' => true, 'meldung' => 'Falls ein Konto zu dieser Adresse besteht, ist die E-Mail unterwegs.']);

    case 'passwort-neu':
        $token = text($d['token'] ?? '', 64);
        if (mb_strlen((string) ($d['passwort'] ?? '')) < 8) {
            json_aus(['ok' => false, 'meldung' => 'Das Passwort braucht mindestens 8 Zeichen.'], 422);
        }
        $s = db()->prepare('SELECT id FROM kunden WHERE token = ? AND token_zweck = "passwort" AND token_bis > NOW()');
        $s->execute([$token]);
        $k = $s->fetch();
        if (!$k) {
            json_aus(['ok' => false, 'meldung' => 'Der Link ist abgelaufen. Bitte fordern Sie einen neuen an.'], 410);
        }
        // Der Link beweist, dass die E-Mail-Adresse dem Kunden gehört: bestätigt, Pflicht zum neuen Passwort erledigt
        db()->prepare('UPDATE kunden SET passwort_hash = ?, token = NULL, token_zweck = NULL, token_bis = NULL,
                       passwort_neu_noetig = 0, email_bestaetigt = 1, letzter_login = NOW() WHERE id = ?')
            ->execute([password_hash((string) $d['passwort'], PASSWORD_DEFAULT), $k['id']]);
        // direkt angemeldet – der Kunde landet in seinem Konto mit Bestellungen und Preisen
        session_regenerate_id(true);
        $_SESSION['kunde_id'] = (int) $k['id'];
        $k = angemeldeter_kunde();
        admin_sitzung($k);
        json_aus(['ok' => true, 'meldung' => 'Passwort gespeichert – Sie sind angemeldet.', 'kunde' => kunde_oeffentlich($k),
            'csrf' => $_SESSION['csrf'], 'csrf_admin' => !empty($_SESSION['admin']) ? $_SESSION['csrf_admin'] : null]);

    case 'bestaetigen':
        $token = text($_GET['token'] ?? '', 64);
        $s = db()->prepare('SELECT id FROM kunden WHERE token = ? AND token_zweck = "email" AND token_bis > NOW()');
        $s->execute([$token]);
        if ($k = $s->fetch()) {
            db()->prepare('UPDATE kunden SET email_bestaetigt = 1, token = NULL, token_zweck = NULL, token_bis = NULL WHERE id = ?')->execute([$k['id']]);
            json_aus(['ok' => true, 'meldung' => 'Vielen Dank, Ihre E-Mail-Adresse ist bestätigt.']);
        }
        json_aus(['ok' => false, 'meldung' => 'Dieser Bestätigungslink ist nicht mehr gültig.'], 410);

    case 'bestellungen':
        $k = angemeldeter_kunde();
        if (!$k) {
            json_aus(['ok' => false, 'meldung' => 'Bitte zuerst anmelden.'], 401);
        }
        $s = db()->prepare('SELECT id, nummer, erstellt, netto, versand, mwst, gesamt, status, versandart, rabatt_prozent FROM bestellungen WHERE kunde_id = ? ORDER BY id DESC LIMIT 50');
        $s->execute([$k['id']]);
        $bestellungen = $s->fetchAll();
        if ($bestellungen) {
            $ids = implode(',', array_map(static fn($b) => (int) $b['id'], $bestellungen));
            $p = db()->query("SELECT bestellung_id, sku, bezeichnung, auswahl, menge, einzelpreis, gesamtpreis FROM positionen WHERE bestellung_id IN ($ids)")->fetchAll();
            foreach ($bestellungen as &$b) {
                $b['positionen'] = array_values(array_filter($p, static fn($x) => (int) $x['bestellung_id'] === (int) $b['id']));
            }
        }
        json_aus(['ok' => true, 'bestellungen' => $bestellungen]);

    default:
        json_aus(['ok' => false, 'meldung' => 'Unbekannte Aktion'], 400);
}
