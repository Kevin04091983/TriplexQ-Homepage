<?php
/** Gemeinsame Grundlagen: Datenbank, Sitzung, kleine Helfer. */
declare(strict_types=1);
require_once __DIR__ . '/einstellungen.php';

// Bestellnummern, Mails und Datenbank immer in deutscher Zeit (Sommer-/Winterzeit automatisch)
date_default_timezone_set('Europe/Berlin');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function sitzung_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $sicher = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $sicher, 'samesite' => 'Lax']);
    session_name('tq_sitzung');
    session_start();
}

function json_aus(array $daten, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

function eingabe(): array
{
    $roh = file_get_contents('php://input');
    if ($roh === false || strlen($roh) > 200000) {
        return [];
    }
    $d = json_decode($roh, true);
    return is_array($d) ? $d : [];
}

function text($wert, int $max = 190): string
{
    if (!is_string($wert)) {
        return '';
    }
    return mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', $wert)), 0, $max);
}

function mehrzeilig($wert, int $max = 1000): string
{
    return is_string($wert) ? mb_substr(str_replace("\0", '', trim($wert)), 0, $max) : '';
}

function euro(float $b): string
{
    return number_format($b, 2, ',', '.') . ' €';
}

function mail_senden(string $an, string $betreff, string $text, ?string $antwortAn = null): bool
{
    if (defined('MAIL_AUS') && MAIL_AUS) {
        return true;                                   // Testbetrieb: nichts verschicken
    }
    $kopf = 'From: ' . ABSENDER . "\r\n";
    // Antworten der Kunden landen im normalen Postfach, auch wenn der technische Absender ein anderer ist
    // (Strato verschickt vom Webspace nur mit Absendern aus demselben Paket, live: shop@triplex-q.com)
    $antwortAn = $antwortAn ?: (defined('ANTWORT_AN') ? ANTWORT_AN : EMPFAENGER_KONTAKT);
    if ($antwortAn) {
        $kopf .= 'Reply-To: ' . $antwortAn . "\r\n";
    }
    $kopf .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nMIME-Version: 1.0";
    // Absender auch als Umschlag-Absender (-f), sonst lehnen manche Mailserver die Mail ab
    $umschlag = preg_match('/<([^>]+)>/', ABSENDER, $m) ? $m[1] : ABSENDER;
    $extra = filter_var($umschlag, FILTER_VALIDATE_EMAIL) ? '-f' . $umschlag : '';
    return @mail($an, '=?UTF-8?B?' . base64_encode($betreff) . '?=', $text, $kopf, $extra);
}

/** Zu viele Fehlversuche? (Schutz gegen Passwort-Raten) */
function gesperrt_wegen_versuchen(string $kennung): bool
{
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM anmeldeversuche WHERE kennung = ? AND zeit > (NOW() - INTERVAL 15 MINUTE)');
        $s->execute([$kennung]);
        return (int) $s->fetchColumn() >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

/** IP-Adresse gekürzt (Datenschutz): 203.0.113.0 bzw. 2001:db8:85a3:: */
function ip_kurz(?string $ip = null): string
{
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_replace('/\.\d+$/', '.0', $ip);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
    }
    return '?';
}

/**
 * Sicherheitsprotokoll für den Wächter (180 Tage): admin_ok, admin_fehler, kunde_fehler, gesperrt.
 * Schlägt nie fehl – Protokollieren darf eine Anmeldung nicht verhindern.
 */
function sicherheit_merken(string $art, string $wer = '', string $info = ''): void
{
    static $tabelle = false;
    try {
        if (!$tabelle) {
            db()->exec("CREATE TABLE IF NOT EXISTS sicherheit_protokoll (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, zeit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                art VARCHAR(30) NOT NULL, wer VARCHAR(190) NOT NULL DEFAULT '', ip VARCHAR(64) NOT NULL DEFAULT '',
                info VARCHAR(255) NOT NULL DEFAULT '', INDEX (art, zeit), INDEX (zeit)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $tabelle = true;
        }
        db()->prepare('INSERT INTO sicherheit_protokoll (art, wer, ip, info) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($art, 0, 30), mb_substr($wer, 0, 190), ip_kurz(), mb_substr($info, 0, 255)]);
        if ($art === 'admin_fehler') {
            $n = (int) db()->query("SELECT COUNT(*) FROM sicherheit_protokoll WHERE art = 'admin_fehler' AND zeit > (NOW() - INTERVAL 60 MINUTE)")->fetchColumn();
            if ($n >= 5) {
                alarm_senden('fehlversuche', "$n fehlgeschlagene Admin-Anmeldungen in der letzten Stunde",
                    "In der letzten Stunde wurde $n Mal versucht, sich mit falschen Daten als Admin anzumelden.\n"
                    . "Letzter Versuch: Benutzer „{$wer}“ über {$info}, Netz " . ip_kurz() . " (gekürzt).\n\n"
                    . "Nach 10 Fehlversuchen sperrt die Seite die Anmeldung automatisch für 15 Minuten.\n"
                    . "Wenn ihr das nicht wart: Admin-Passwort stark halten – die Übersicht steht im Wächter.");
            }
        } elseif ($art === 'gesperrt') {
            alarm_senden('sperre', 'Anmeldung wegen zu vieler Fehlversuche gesperrt',
                "Die Seite hat gerade eine Anmeldung gesperrt (zu viele falsche Passwörter).\n"
                . "Betroffen: „{$wer}“ über {$info}, Netz " . ip_kurz() . " (gekürzt).\n\nDie Sperre löst sich nach 15 Minuten von selbst.");
        }
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM sicherheit_protokoll WHERE zeit < (NOW() - INTERVAL 180 DAY)');
        }
    } catch (Throwable $e) {
    }
}

function versuch_merken(string $kennung): void
{
    try {
        db()->prepare('INSERT INTO anmeldeversuche (kennung) VALUES (?)')->execute([$kennung]);
        db()->exec('DELETE FROM anmeldeversuche WHERE zeit < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
    }
}

function angemeldeter_kunde(): ?array
{
    sitzung_start();
    if (empty($_SESSION['kunde_id'])) {
        return null;
    }
    $s = db()->prepare('SELECT * FROM kunden WHERE id = ? AND gesperrt = 0');
    $s->execute([$_SESSION['kunde_id']]);
    $kunde = $s->fetch();
    return $kunde ?: null;
}

/** Admin = Kundenkonto mit einer E-Mail aus ADMIN_EMAILS und bestätigter Adresse */
function ist_admin_kunde(?array $k): bool
{
    if (!$k || !(int) $k['email_bestaetigt']) {
        return false;
    }
    $liste = array_map('strtolower', defined('ADMIN_EMAILS') ? ADMIN_EMAILS : []);
    return in_array(strtolower((string) $k['email']), $liste, true);
}

/** Admin-Rechte in der Sitzung setzen bzw. entziehen (gilt auch für die Verwaltung unter /admin/) */
function admin_sitzung(?array $k): void
{
    if (ist_admin_kunde($k) && admin_2fa_aktiv($k) && empty($_SESSION['admin_2fa_ok'])) {
        // Zwei-Faktor eingeschaltet: Admin-Rechte gibt es erst nach dem Code (Anmeldung in der Verwaltung)
        if (!empty($_SESSION['admin_ueber_konto'])) {
            unset($_SESSION['admin'], $_SESSION['admin_name'], $_SESSION['admin_ueber_konto']);
        }
        return;
    }
    if (ist_admin_kunde($k)) {
        $_SESSION['admin'] = true;
        $_SESSION['admin_name'] = $k['email'];
        $_SESSION['admin_ueber_konto'] = true;
        if (empty($_SESSION['csrf_admin'])) {
            $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
        }
    } elseif (!empty($_SESSION['admin_ueber_konto'])) {
        // Admin-Rechte kamen vom Kundenkonto, das nicht mehr Admin ist: entziehen
        unset($_SESSION['admin'], $_SESSION['admin_name'], $_SESSION['admin_ueber_konto']);
    }
}

/* =====================================================================
   Sicherheitspaket: Alarm-Mails, neue Geräte, geleakte Passwörter, Zwei-Faktor (TOTP)
   ===================================================================== */

/** Empfänger der Alarm-Mails: ALARM_EMAILS (einstellungen.php) oder die Admin-Adressen */
function alarm_empfaenger(): array
{
    return array_values(array_filter(array_map('trim', defined('ALARM_EMAILS') ? ALARM_EMAILS : (defined('ADMIN_EMAILS') ? ADMIN_EMAILS : []))));
}

/**
 * Alarm-Mail an die Admins – höchstens einmal je $schluessel und Sperrzeit (gegen Mail-Fluten).
 * Wird im Sicherheitsprotokoll als 'alarm' vermerkt.
 */
function alarm_senden(string $schluessel, string $betreff, string $text, int $sperreMinuten = 60): bool
{
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM sicherheit_protokoll WHERE art = 'alarm' AND info = ? AND zeit > (NOW() - INTERVAL ? MINUTE)");
        $s->execute([mb_substr($schluessel, 0, 255), $sperreMinuten]);
        if ((int) $s->fetchColumn() > 0) {
            return false;
        }
    } catch (Throwable $e) {
    }
    $ok = false;
    $fuss = "\n\n—\nWächter der TRIPLEX-Q Website · " . date('d.m.Y H:i') . " Uhr\n"
        . "Übersicht: " . (defined('SEITEN_URL') ? SEITEN_URL : 'https://www.triplex-q.com') . "/admin/?seite=waechter\n";
    foreach (alarm_empfaenger() as $an) {
        $ok = mail_senden($an, '[TRIPLEX-Q Sicherheit] ' . $betreff, $text . $fuss) || $ok;
    }
    sicherheit_merken('alarm', implode(', ', alarm_empfaenger()), $schluessel);
    return $ok;
}

/** Kurzbeschreibung des Browsers für Alarm-Mails (z. B. "Chrome auf Windows") */
function geraet_beschreibung(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
        : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser')));
    $system = preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/iPhone|iPad/', $ua) ? 'iPhone/iPad'
        : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Mac OS X/', $ua) ? 'Mac' : (preg_match('/Linux/', $ua) ? 'Linux' : 'unbekannt'))));
    return "$browser auf $system";
}

/**
 * Admin hat sich angemeldet: bekanntes Gerät (Cookie) oder neues? Neues Gerät -> Alarm-Mail + merken.
 * Im Cookie liegt nur ein Zufallswert; in der Datenbank nur dessen Hash.
 */
function admin_geraet_pruefen(string $wer): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS admin_geraete (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, wer VARCHAR(190) NOT NULL, token_hash CHAR(64) NOT NULL,
            geraet VARCHAR(80) NOT NULL DEFAULT '', ip VARCHAR(64) NOT NULL DEFAULT '',
            erstellt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, zuletzt DATETIME NULL, UNIQUE (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $token = (string) ($_COOKIE['tq_geraet'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $s = db()->prepare('SELECT id FROM admin_geraete WHERE token_hash = ? AND wer = ?');
            $s->execute([hash('sha256', $token), $wer]);
            if ($id = $s->fetchColumn()) {
                db()->prepare('UPDATE admin_geraete SET zuletzt = NOW(), ip = ? WHERE id = ?')->execute([ip_kurz(), $id]);
                return;
            }
        }
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO admin_geraete (wer, token_hash, geraet, ip, zuletzt) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$wer, hash('sha256', $token), geraet_beschreibung(), ip_kurz()]);
        $sicher = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('tq_geraet', $token, ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => $sicher, 'httponly' => true, 'samesite' => 'Lax']);
        alarm_senden('geraet:' . $wer . ':' . hash('crc32b', $token), 'Admin-Anmeldung von einem neuen Gerät',
            "Soeben hat sich jemand mit dem Admin-Zugang „{$wer}“ angemeldet – von einem Gerät, das bisher nicht bekannt war.\n\n"
            . "Zeit:   " . date('d.m.Y H:i') . " Uhr\nGerät:  " . geraet_beschreibung() . "\nNetz:   " . ip_kurz() . " (gekürzt)\n\n"
            . "Wart ihr das? Dann ist alles in Ordnung – dieses Gerät wird ab jetzt wiedererkannt.\n"
            . "Wenn nicht: sofort das Admin-Passwort ändern und Kevin Bescheid geben.", 1);
    } catch (Throwable $e) {
    }
}

/**
 * Kommt das Passwort in bekannten Datenlecks vor? (haveibeenpwned, k-Anonymität:
 * es verlassen nur die ersten 5 Zeichen des SHA-1-Hashs den Server, nie das Passwort.)
 * Liefert die Anzahl der Funde, 0 = nicht gefunden oder Dienst nicht erreichbar.
 */
function passwort_geleakt(string $passwort): int
{
    if ($passwort === '' || !function_exists('curl_init')) {
        return 0;
    }
    $sha = strtoupper(sha1($passwort));
    $c = curl_init('https://api.pwnedpasswords.com/range/' . substr($sha, 0, 5));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Add-Padding: true'], CURLOPT_USERAGENT => 'TRIPLEX-Q Passwortpruefung']);
    $antwort = (string) curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    if ($code !== 200) {
        return 0;
    }
    foreach (preg_split('/\r?\n/', $antwort) as $zeile) {
        [$rest, $anzahl] = array_pad(explode(':', trim($zeile)), 2, '0');
        if ($rest === substr($sha, 5)) {
            return (int) $anzahl;
        }
    }
    return 0;
}

/** Fehlermeldung, wenn ein neues Passwort zu schwach ist – sonst null */
function passwort_schwach(string $passwort, int $mindestens = 8): ?string
{
    if (mb_strlen($passwort) < $mindestens) {
        return "Das Passwort braucht mindestens $mindestens Zeichen.";
    }
    if (passwort_geleakt($passwort) > 0) {
        return 'Dieses Passwort taucht in bekannten Datenlecks im Internet auf und ist deshalb unsicher. Bitte ein anderes wählen.';
    }
    return null;
}

/* ---------------- Zwei-Faktor-Anmeldung (TOTP, RFC 6238 – Google/Microsoft Authenticator u. a.) */
function base32_kodieren(string $daten): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($daten) as $z) {
        $bits .= str_pad(decbin(ord($z)), 8, '0', STR_PAD_LEFT);
    }
    $aus = '';
    foreach (str_split($bits, 5) as $gruppe) {
        $aus .= $abc[bindec(str_pad($gruppe, 5, '0'))];
    }
    return $aus;
}

function base32_dekodieren(string $text): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text))) as $z) {
        $bits .= str_pad(decbin(strpos($abc, $z)), 5, '0', STR_PAD_LEFT);
    }
    $aus = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $aus .= chr(bindec($byte));
        }
    }
    return $aus;
}

function totp_code(string $geheimnis, ?int $zeit = null): string
{
    $zaehler = intdiv($zeit ?? time(), 30);
    $hash = hash_hmac('sha1', pack('N*', 0, $zaehler), base32_dekodieren($geheimnis), true);
    $versatz = ord($hash[19]) & 0x0f;
    $wert = ((ord($hash[$versatz]) & 0x7f) << 24) | (ord($hash[$versatz + 1]) << 16) | (ord($hash[$versatz + 2]) << 8) | ord($hash[$versatz + 3]);
    return str_pad((string) ($wert % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Code prüfen (±30 s Toleranz für ungenaue Handy-Uhren) */
function totp_pruefen(string $geheimnis, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $geheimnis === '') {
        return false;
    }
    foreach ([-1, 0, 1] as $schritt) {
        if (hash_equals(totp_code($geheimnis, time() + $schritt * 30), $code)) {
            return true;
        }
    }
    return false;
}

/** Spalten für die Zwei-Faktor-Anmeldung bei älteren Datenbanken ergänzen */
function totp_spalten(): void
{
    static $erledigt = false;
    if ($erledigt) {
        return;
    }
    try {
        db()->exec('ALTER TABLE kunden ADD COLUMN IF NOT EXISTS totp_geheimnis VARCHAR(64) NULL, ADD COLUMN IF NOT EXISTS totp_aktiv TINYINT(1) NOT NULL DEFAULT 0');
        $erledigt = true;
    } catch (Throwable $e) {
    }
}

/** Ist für dieses Admin-Konto die Zwei-Faktor-Anmeldung eingeschaltet? */
function admin_2fa_aktiv(?array $k): bool
{
    return $k && !empty($k['totp_aktiv']) && !empty($k['totp_geheimnis']);
}

/** Gibt es irgendein Admin-Konto mit eingeschalteter Zwei-Faktor-Anmeldung? (dann braucht auch der Notfall-Zugang einen Code) */
function irgendein_admin_2fa(): ?array
{
    try {
        $s = db()->query('SELECT * FROM kunden WHERE totp_aktiv = 1 AND totp_geheimnis IS NOT NULL');
        foreach ($s as $k) {
            if (ist_admin_kunde($k)) {
                return $k;
            }
        }
    } catch (Throwable $e) {
    }
    return null;
}

/** Listenpreise aus preise.json: [sku => [preis, name, auswahl]] */
function preise_basis(): array
{
    static $basis = null;
    if ($basis === null) {
        $basis = json_decode((string) @file_get_contents(__DIR__ . '/preise.json'), true) ?: [];
    }
    return $basis;
}

/** In der Verwaltung geänderte Preise: [sku => preis|null] (leer, wenn keine Datenbank) */
function preise_geaendert(): array
{
    try {
        $aus = [];
        foreach (db()->query('SELECT sku, preis FROM preise') as $z) {
            $aus[$z['sku']] = $z['preis'] === null ? null : (float) $z['preis'];
        }
        return $aus;
    } catch (Throwable $e) {
        return [];
    }
}

/** Aktuell gültige Preise: Listenpreis, überschrieben durch Änderungen aus der Datenbank */
function preise_aktuell(): array
{
    $alle = preise_basis();
    foreach (preise_geaendert() as $sku => $preis) {
        if (isset($alle[$sku])) {
            $alle[$sku][0] = $preis;
        }
    }
    return $alle;
}

/**
 * Preise speichern und protokollieren. $neu = [sku => preis|null|""] (null/"" = auf Anfrage).
 * Gibt die tatsächlich geänderten Preise zurück. Wirft InvalidArgumentException bei Fehlern (nichts wird gespeichert).
 */
function preise_speichern(array $neu, string $wer): array
{
    $basis = preise_basis();
    $aktuell = preise_aktuell();
    $geaendert = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $setzen = $pdo->prepare('INSERT INTO preise (sku, preis, geaendert_von) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE preis = VALUES(preis), geaendert = NOW(), geaendert_von = VALUES(geaendert_von)');
        $merken = $pdo->prepare('INSERT INTO preis_protokoll (sku, alt, neu, wer) VALUES (?,?,?,?)');
        foreach ($neu as $sku => $wert) {
            $sku = (string) $sku;
            if (!isset($basis[$sku])) {
                throw new InvalidArgumentException("Unbekannte Artikelnummer: $sku");
            }
            if ($wert === null || trim((string) $wert) === '') {
                $preis = null;
            } else {
                $roh = str_replace([' ', '€'], '', (string) $wert);
                if (str_contains($roh, ',')) {
                    $roh = str_replace(['.', ','], ['', '.'], $roh);   // 1.234,56 -> 1234.56
                }
                if (!is_numeric($roh)) {
                    throw new InvalidArgumentException("Ungültiger Preis für $sku: $wert");
                }
                $preis = round((float) $roh, 2);
                if ($preis <= 0 || $preis > 100000) {
                    throw new InvalidArgumentException("Ungültiger Preis für $sku: $wert");
                }
            }
            $alt = $aktuell[$sku][0] === null ? null : (float) $aktuell[$sku][0];
            if (($alt === null && $preis === null) || ($alt !== null && $preis !== null && abs($alt - $preis) < 0.005)) {
                continue;
            }
            $setzen->execute([$sku, $preis, $wer]);
            $merken->execute([$sku, $alt, $preis, $wer]);
            $geaendert[$sku] = $preis;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $geaendert;
}

/**
 * Feste Preise für diesen Kunden: [sku => preis].
 * Erst die Preise seiner Kundengruppe, darüber seine eigenen Artikelpreise (die gewinnen).
 */
function kundenpreise(int $kundeId): array
{
    try {
        $aus = [];
        $s = db()->prepare('SELECT gp.sku, gp.preis FROM kunden k JOIN preisgruppen_preise gp ON gp.gruppe_id = k.preisgruppe_id WHERE k.id = ?');
        $s->execute([$kundeId]);
        foreach ($s as $z) {
            $aus[(string) $z['sku']] = (float) $z['preis'];
        }
        $s = db()->prepare('SELECT sku, preis FROM kundenpreise WHERE kunde_id = ?');
        $s->execute([$kundeId]);
        foreach ($s as $z) {
            $aus[(string) $z['sku']] = (float) $z['preis'];
        }
        return $aus;
    } catch (Throwable $e) {
        return [];
    }
}

/** Preis, den dieser Kunde zahlt: eigener Artikelpreis > Preis seiner Kundengruppe > Listenpreis abzüglich Prozent-Rabatt */
function preis_fuer_kunde(?array $kunde, string $sku, float $listenpreis, ?array $sonderpreise = null): float
{
    if ($kunde) {
        $sonderpreise ??= kundenpreise((int) $kunde['id']);
        if (isset($sonderpreise[$sku])) {
            return round($sonderpreise[$sku], 2);
        }
        return round($listenpreis * (1 - (float) $kunde['rabatt_prozent'] / 100), 2);
    }
    return round($listenpreis, 2);
}

/**
 * Link zum (Neu-)Setzen des Passworts schicken.
 * $altkunde = true: Willkommenstext für aus dem alten Shop übernommene Kunden, Link 14 Tage gültig.
 * Liefert false, wenn in den letzten 5 Minuten schon ein Link verschickt wurde (Schutz vor Mail-Flut).
 */
function passwort_link_senden(array $k, bool $altkunde = false): bool
{
    $gueltig = $altkunde ? '14 DAY' : '2 HOUR';
    $s = db()->prepare("SELECT (token IS NOT NULL AND token_zweck = 'passwort' AND token_bis > NOW() + INTERVAL $gueltig - INTERVAL 5 MINUTE) FROM kunden WHERE id = ?");
    $s->execute([$k['id']]);
    if ((int) $s->fetchColumn() === 1) {
        return false;
    }
    $token = bin2hex(random_bytes(24));
    db()->prepare("UPDATE kunden SET token = ?, token_zweck = 'passwort', token_bis = NOW() + INTERVAL $gueltig WHERE id = ?")
        ->execute([$token, $k['id']]);
    $link = SEITEN_URL . '/konto.html?neues-passwort=' . $token;
    $name = trim($k['vorname'] . ' ' . $k['nachname']);
    if ($altkunde) {
        $betreff = 'Unser neuer TRIPLEX-Q Shop – bitte Passwort festlegen';
        $text = "Guten Tag $name,\n\n"
            . "unser Webshop ist umgezogen und hat ein neues Gesicht bekommen. Ihr Kundenkonto haben wir mit Ihren\n"
            . "Adressen, Ihren bisherigen Bestellungen und Ihren Konditionen übernommen.\n\n"
            . "Aus Sicherheitsgründen konnten wir Ihr bisheriges Passwort nicht übertragen. Bitte legen Sie über den\n"
            . "folgenden Link einmalig ein neues Passwort fest (gültig 14 Tage):\n$link\n\n"
            . "Danach melden Sie sich wie gewohnt mit Ihrer E-Mail-Adresse an.\n"
            . "Läuft der Link ab, fordern Sie auf der Anmeldeseite einfach über „Passwort vergessen“ einen neuen an.\n\n"
            . "Mit freundlichen Grüßen\nPräzisionswerkzeuge Schmidt e.K. – Home of TRIPLEX-Q\n"
            . "Ackerstraße 12, 98544 Zella-Mehlis, Tel. +49 (0) 3682 / 483662\n";
    } else {
        $betreff = 'Neues Passwort für Ihr TRIPLEX-Q Konto';
        $text = "Guten Tag $name,\n\nüber den folgenden Link vergeben Sie ein neues Passwort. Der Link gilt zwei Stunden:\n$link\n\n"
            . "Wenn Sie das nicht angefordert haben, können Sie diese E-Mail ignorieren.\n\n"
            . "Mit freundlichen Grüßen\nPräzisionswerkzeuge Schmidt e.K. – Home of TRIPLEX-Q\n";
    }
    mail_senden($k['email'], $betreff, $text);
    return true;
}

function kunde_oeffentlich(array $k): array
{
    return [
        'admin' => ist_admin_kunde($k) && (!admin_2fa_aktiv($k) || !empty($_SESSION['admin_2fa_ok'])),
        'admin_code_noetig' => ist_admin_kunde($k) && admin_2fa_aktiv($k) && empty($_SESSION['admin_2fa_ok']),
        'sonderpreise' => (object) kundenpreise((int) $k['id']),
        'id' => (int) $k['id'], 'email' => $k['email'], 'firma' => $k['firma'],
        'vorname' => $k['vorname'], 'nachname' => $k['nachname'], 'strasse' => $k['strasse'],
        'plz' => $k['plz'], 'ort' => $k['ort'], 'land' => $k['land'], 'telefon' => $k['telefon'],
        'ustid' => $k['ustid'], 'lieferadresse' => $k['lieferadresse'],
        'rabatt' => (float) $k['rabatt_prozent'], 'email_bestaetigt' => (bool) $k['email_bestaetigt'],
    ];
}
