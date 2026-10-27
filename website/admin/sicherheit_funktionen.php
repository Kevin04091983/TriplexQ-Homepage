<?php
/**
 * TRIPLEX-Q Verwaltung – Sicherheits-Funktionen (Wächter-Prüfungen, eigene Datensicherung, zweite Sperre,
 * Ergebnisse des täglichen Laufs). Wird von admin/index.php, waechter_seite.php und cron_taeglich.php eingebunden.
 * Gibt selbst nichts aus; direkt aufgerufen nur 404.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

const SICHERUNG_ORDNER = __DIR__ . '/sicherungen';
const SPERRE_MARKE = '# TRIPLEX-Q zweite Sperre';

/* ---------------------------------------------------------------- Datensicherung */
function sicherung_ordner(): string
{
    if (!is_dir(SICHERUNG_ORDNER)) {
        mkdir(SICHERUNG_ORDNER, 0700, true);
    }
    // doppelt gesperrt: Apache-Regel + leere Startseite
    if (!is_file(SICHERUNG_ORDNER . '/.htaccess')) {
        file_put_contents(SICHERUNG_ORDNER . '/.htaccess', "Require all denied\n");
    }
    if (!is_file(SICHERUNG_ORDNER . '/index.html')) {
        file_put_contents(SICHERUNG_ORDNER . '/index.html', '');
    }
    return SICHERUNG_ORDNER;
}

/** Komplette Datenbank als SQL (gzip) sichern; liefert den Dateinamen */
function sicherung_erstellen(): string
{
    $pdo = db();
    $aus = "-- TRIPLEX-Q Datensicherung vom " . date('d.m.Y H:i:s') . "\n-- Wiederherstellen: in phpMyAdmin importieren\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabelle) {
        $t = str_replace('`', '', (string) $tabelle);
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $aus .= "\nDROP TABLE IF EXISTS `$t`;\n$create;\n";
        $paket = [];
        foreach ($pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM) as $zeile) {
            $paket[] = '(' . implode(',', array_map(static fn($w) => $w === null ? 'NULL' : $pdo->quote((string) $w), $zeile)) . ')';
            if (count($paket) >= 200) {
                $aus .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $paket) . ";\n";
                $paket = [];
            }
        }
        if ($paket) {
            $aus .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $paket) . ";\n";
        }
    }
    $aus .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    $name = 'sicherung-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
    $pfad = sicherung_ordner() . '/' . $name;
    file_put_contents($pfad, gzencode($aus, 9), LOCK_EX);
    @chmod($pfad, 0600);
    return $name;
}

/** Vorhandene Sicherungen, neueste zuerst: [[name, groesse, zeit], …] */
function sicherungen(): array
{
    $liste = [];
    foreach (glob(SICHERUNG_ORDNER . '/sicherung-*.sql.gz') ?: [] as $pfad) {
        $liste[] = ['name' => basename($pfad), 'groesse' => (int) filesize($pfad), 'zeit' => (int) filemtime($pfad)];
    }
    usort($liste, static fn($a, $b) => $b['zeit'] <=> $a['zeit']);
    return $liste;
}

/** Sicherungen älter als $tage löschen – die 7 neuesten bleiben immer */
function sicherungen_aufraeumen(int $tage = 30): int
{
    $geloescht = 0;
    foreach (array_slice(sicherungen(), 7) as $s) {
        if ($s['zeit'] < time() - $tage * 86400 && @unlink(SICHERUNG_ORDNER . '/' . $s['name'])) {
            $geloescht++;
        }
    }
    return $geloescht;
}

function sicherung_pfad(string $name): ?string
{
    if (!preg_match('/^sicherung-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{8}\.sql\.gz$/', $name)) {
        return null;
    }
    $pfad = SICHERUNG_ORDNER . '/' . $name;
    return is_file($pfad) ? $pfad : null;
}

/* ---------------------------------------------------------------- Zweite Sperre (Browser-Passwort vor /admin/) */
function zweite_sperre_aktiv(): bool
{
    return is_file(__DIR__ . '/.htpasswd') && str_contains((string) @file_get_contents(__DIR__ . '/.htaccess'), SPERRE_MARKE);
}

function zweite_sperre_setzen(string $benutzer, string $passwort): void
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $benutzer)) {
        throw new InvalidArgumentException('Benutzername: 3–40 Zeichen, nur Buchstaben, Ziffern, Punkt, Strich.');
    }
    if ($fehler = passwort_schwach($passwort, 12)) {
        throw new InvalidArgumentException($fehler);
    }
    if (is_file(__DIR__ . '/.htaccess') && !str_contains((string) file_get_contents(__DIR__ . '/.htaccess'), SPERRE_MARKE)) {
        throw new RuntimeException('admin/.htaccess existiert schon mit anderem Inhalt – bitte Kevin/Claude Bescheid geben.');
    }
    file_put_contents(__DIR__ . '/.htpasswd', $benutzer . ':' . password_hash($passwort, PASSWORD_BCRYPT) . "\n", LOCK_EX);
    @chmod(__DIR__ . '/.htpasswd', 0600);
    file_put_contents(__DIR__ . '/.htaccess', SPERRE_MARKE . " (Wächter → Zweite Sperre; zum Ausschalten dort oder per SFTP löschen)\n"
        . "AuthType Basic\nAuthName \"TRIPLEX-Q Verwaltung\"\nAuthUserFile \"" . __DIR__ . "/.htpasswd\"\nRequire valid-user\n", LOCK_EX);
}

function zweite_sperre_aus(): void
{
    if (str_contains((string) @file_get_contents(__DIR__ . '/.htaccess'), SPERRE_MARKE)) {
        @unlink(__DIR__ . '/.htaccess');
    }
    @unlink(__DIR__ . '/.htpasswd');
}

/* ---------------------------------------------------------------- Ergebnisse des täglichen Laufs */
function waechter_lauf_tabelle(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS waechter_laeufe (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, zeit DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        punkte TINYINT UNSIGNED NOT NULL, kritisch SMALLINT UNSIGNED NOT NULL, achtung SMALLINT UNSIGNED NOT NULL,
        probleme TEXT NOT NULL, INDEX (zeit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function letzter_lauf(): ?array
{
    try {
        waechter_lauf_tabelle();
        return db()->query('SELECT * FROM waechter_laeufe ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ---------------------------------------------------------------- Wächter-Prüfungen */
/**
 * Alle Prüfungen ausführen. $zeitplan = true beim nächtlichen Lauf (ohne Browser-Sitzung).
 * Liefert pruefungen, protokoll, punkte, gesamt, zaehle.
 */
function waechter_pruefen(bool $zeitplan = false): array
{
    $WURZEL = dirname(__DIR__);
    $BASIS = rtrim(defined('SEITEN_URL') ? SEITEN_URL : 'https://www.triplex-q.com', '/');
    $HOST = (string) parse_url($BASIS, PHP_URL_HOST);
    $pruefungen = [];
    $neu = static function (string $gruppe, string $titel, string $stand, string $text, string $tipp = '') use (&$pruefungen): void {
        $pruefungen[] = compact('gruppe', 'titel', 'stand', 'text', 'tipp');   // stand: gut | achtung | kritisch | info
    };
    $tagZeit = static fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '–';

    /** Seite von außen abrufen (wie ein Besucher): [Status, Kopfzeilen (klein), Länge des Inhalts, Inhalt-Anfang] */
    $holen = static function (string $url): array {
        if (!function_exists('curl_init')) {
            return [0, [], 0, ''];
        }
        $kopf = [];
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'TRIPLEX-Q Waechter',
            CURLOPT_HEADERFUNCTION => static function ($c, $zeile) use (&$kopf) {
                if (str_contains($zeile, ':')) {
                    [$n, $w] = explode(':', $zeile, 2);
                    $kopf[strtolower(trim($n))] = trim($w);
                }
                return strlen($zeile);
            }]);
        $inhalt = (string) curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        return [$code, $kopf, strlen($inhalt), substr($inhalt, 0, 4000)];
    };

    /* ======================================================= 1. Verschlüsselung */
    $https = $zeitplan || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$zeitplan) {
        $neu('Verschlüsselung', 'HTTPS', $https ? 'gut' : 'kritisch',
            $https ? 'Diese Verbindung ist verschlüsselt (https). Aufrufe über http werden automatisch auf https umgeleitet.'
                : 'Die Verwaltung wurde unverschlüsselt aufgerufen!', $https ? '' : 'Nur über https://www.triplex-q.com/admin/ arbeiten.');
    }
    [$c80] = $holen('http://' . $HOST . '/');
    $neu('Verschlüsselung', 'Umleitung http → https', in_array($c80, [301, 302, 308], true) ? 'gut' : ($c80 ? 'achtung' : 'info'),
        $c80 ? "Ein Aufruf über http antwortet mit $c80" . (in_array($c80, [301, 302, 308], true) ? ' (Umleitung auf https).' : ' – keine Umleitung!')
            : 'Konnte vom Server aus nicht geprüft werden.');

    $tageGueltig = null;
    if (function_exists('openssl_x509_parse')) {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $HOST, 'verify_peer' => false]]);
        $s = @stream_socket_client('ssl://' . $HOST . ':443', $en, $es, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($s) {
            $info = openssl_x509_parse(stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? '');
            fclose($s);
            if ($info) {
                $tageGueltig = (int) floor(($info['validTo_time_t'] - time()) / 86400);
                $aussteller = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '?');
                $neu('Verschlüsselung', 'SSL-Zertifikat', $tageGueltig > 21 ? 'gut' : ($tageGueltig > 7 ? 'achtung' : 'kritisch'),
                    "Gültig bis " . date('d.m.Y', $info['validTo_time_t']) . " (noch $tageGueltig Tage), ausgestellt von $aussteller.",
                    $tageGueltig > 21 ? '' : 'Im Strato-Kundenbereich unter Sicherheit → SSL-Zertifikate prüfen/verlängern.');
            }
        }
    }
    if ($tageGueltig === null) {
        $neu('Verschlüsselung', 'SSL-Zertifikat', 'info', 'Konnte vom Server aus nicht abgefragt werden.');
    }

    /* ======================================================= 2. Sicherheits-Header */
    [$cStart, $kopfStart, , $anfang] = $holen($BASIS . '/');
    $sollKopf = [
        'strict-transport-security' => ['HSTS (Browser merken sich: nur https)', 'achtung'],
        'x-content-type-options' => ['Kein Erraten von Dateitypen (nosniff)', 'achtung'],
        'x-frame-options' => ['Kein Einbetten in fremde Seiten (Klick-Betrug)', 'achtung'],
        'content-security-policy' => ['Content-Security-Policy', 'achtung'],
        'referrer-policy' => ['Referrer-Policy (Datenschutz)', 'info'],
        'permissions-policy' => ['Kamera/Mikrofon/Standort gesperrt', 'info'],
    ];
    foreach ($sollKopf as $name => [$text, $sonst]) {
        $da = isset($kopfStart[$name]);
        $neu('Sicherheits-Header', $text, $da ? 'gut' : ($cStart ? $sonst : 'info'),
            $da ? '<code>' . h($name . ': ' . mb_substr($kopfStart[$name], 0, 90)) . '</code>' : ($cStart ? 'Fehlt.' : 'Startseite nicht abrufbar.'));
    }
    if (isset($kopfStart['x-powered-by'])) {
        $neu('Sicherheits-Header', 'Server verrät PHP-Version', 'achtung', h('X-Powered-By: ' . $kopfStart['x-powered-by']));
    }

    /* ======================================================= 3. Geschützte Dateien (von außen geprüft) */
    $geschuetzt = [
        'api/einstellungen.php' => ['Zugangsdaten (Datenbank, Admin)', 'leer'],
        'api/schema.sql' => ['Datenbank-Aufbau', 'gesperrt'],
        'admin/integritaet.json' => ['Prüfliste des Wächters', 'gesperrt'],
        'admin/uebernahme_funktionen.php' => ['interne Übernahme-Funktionen', 'gesperrt'],
        'admin/auswertung_seite.php' => ['Auswertung (nur über Verwaltung)', 'gesperrt'],
        'admin/waechter_seite.php' => ['Wächter (nur über Verwaltung)', 'gesperrt'],
        '.htaccess' => ['Server-Regeln', 'gesperrt'],
        'einrichtung/' => ['Einrichtungs-Assistent', 'gesperrt'],
        'admin/passwort-hash.php' => ['Passwort-Hilfsskript', 'gesperrt'],
    ];
    $offenGefunden = 0;
    foreach ($geschuetzt as $pfad => [$name, $art]) {
        [$code, , $laenge] = $holen($BASIS . '/' . $pfad);
        $ok = $art === 'leer' ? ($code !== 200 || $laenge === 0) : ($code !== 200);
        if (!$code) {
            $neu('Geschützte Dateien', $name, 'info', "<code>/$pfad</code> konnte nicht geprüft werden.");
            continue;
        }
        if (!$ok) { $offenGefunden++; }
        $neu('Geschützte Dateien', $name, $ok ? 'gut' : 'kritisch',
            "<code>/$pfad</code> → " . ($ok ? ($code === 200 ? 'liefert keinen Inhalt' : "von außen gesperrt ($code)") : "ÖFFENTLICH LESBAR ($code, $laenge Bytes)!"),
            $ok ? '' : 'Sofort melden – Datei vom Server entfernen bzw. Schutz in der .htaccess wiederherstellen.');
    }
    $neu('Geschützte Dateien', 'Einrichtungs-Assistent entfernt', is_dir($WURZEL . '/einrichtung') ? 'kritisch' : 'gut',
        is_dir($WURZEL . '/einrichtung') ? 'Der Ordner <code>einrichtung/</code> liegt noch auf dem Server.' : 'Der Assistent hat sich nach der Einrichtung gelöscht.');
    $rechte = @fileperms($WURZEL . '/api/einstellungen.php');
    if ($rechte !== false) {
        $oktal = substr(sprintf('%o', $rechte), -3);
        $neu('Geschützte Dateien', 'Dateirechte der Zugangsdaten', ($rechte & 0x0004) ? 'achtung' : 'gut',
            "<code>api/einstellungen.php</code> hat die Rechte $oktal" . (($rechte & 0x0004) ? ' – für alle lesbar.' : ' – nur für die Seite selbst lesbar.'),
            ($rechte & 0x0004) ? 'Rechte auf 600 setzen (lasse ich gern per Upload-Werkzeug machen).' : '');
    }

    /* ======================================================= 4. Datei-Integrität */
    $pruefliste = @json_decode((string) @file_get_contents(__DIR__ . '/integritaet.json'), true);
    if (!$pruefliste || empty($pruefliste['dateien'])) {
        $neu('Datei-Integrität', 'Prüfliste', 'achtung', 'Keine Prüfliste vorhanden – sie entsteht beim nächsten Hochladen.');
    } else {
        $soll = $pruefliste['dateien'];
        $geaendert = $fehlt = $fremd = $fremdPhp = [];
        // entstehen auf dem Server selbst (Zugangsdaten, Prüfliste, Sicherungen, zweite Sperre) – nicht Teil des Uploads
        $ausnahmen = ['api/einstellungen.php', 'admin/integritaet.json', 'admin/.htaccess', 'admin/.htpasswd'];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($WURZEL, FilesystemIterator::SKIP_DOTS));
        $ist = [];
        foreach ($it as $datei) {
            if (!$datei->isFile()) { continue; }
            $rel = str_replace('\\', '/', substr($datei->getPathname(), strlen($WURZEL) + 1));
            if (in_array($rel, $ausnahmen, true) || str_starts_with($rel, 'admin/sicherungen/')) { continue; }
            $ist[$rel] = true;
            if (!isset($soll[$rel])) {
                if (preg_match('/\.(php\d?|phtml|phar|cgi|pl)$/i', $rel)) {
                    $fremdPhp[] = $rel;
                } else {
                    $fremd[] = $rel;
                }
            } elseif (hash_file('sha256', $datei->getPathname()) !== $soll[$rel]) {
                $geaendert[] = $rel;
            }
        }
        foreach ($soll as $rel => $_) {
            if (!isset($ist[$rel]) && !str_starts_with($rel, 'einrichtung/')) { $fehlt[] = $rel; }
        }
        $liste = static fn(array $l) => '<br><code>' . h(implode(', ', array_slice($l, 0, 8))) . (count($l) > 8 ? ' …' : '') . '</code>';
        $neu('Datei-Integrität', 'Stand der Dateien', 'info', count($soll) . ' Dateien laut Prüfliste vom ' . $tagZeit($pruefliste['erstellt'] ?? '')
            . (!empty($pruefliste['git']) ? ' (Version <code>' . h($pruefliste['git']) . '</code>)' : '') . '.');
        $neu('Datei-Integrität', 'Unerwartete Programmdateien', $fremdPhp ? 'kritisch' : 'gut',
            $fremdPhp ? count($fremdPhp) . ' PHP-Datei(en), die nicht von uns hochgeladen wurden – typisches Zeichen für einen Einbruch!' . $liste($fremdPhp)
                : 'Keine fremden PHP-Dateien auf dem Server.', $fremdPhp ? 'Sofort melden – nicht öffnen, nicht löschen, ich prüfe sie.' : '');
        $neu('Datei-Integrität', 'Veränderte Dateien', $geaendert ? 'kritisch' : 'gut',
            $geaendert ? count($geaendert) . ' Datei(en) weichen vom hochgeladenen Stand ab.' . $liste($geaendert) : 'Alle Dateien sind unverändert.',
            $geaendert ? 'Melden – wurde etwas von Hand geändert? Sonst neu hochladen und Ursache prüfen.' : '');
        $neu('Datei-Integrität', 'Fehlende / zusätzliche Dateien', ($fehlt || $fremd) ? 'achtung' : 'gut',
            ($fehlt ? count($fehlt) . ' fehlen' . $liste($fehlt) . '<br>' : '') . ($fremd ? count($fremd) . ' zusätzlich (keine Programme)' . $liste($fremd) : '')
            ?: 'Alles vollständig, nichts Zusätzliches.');
    }

    /* ======================================================= 5. Admin-Zugänge */
    $admins = array_map('strtolower', defined('ADMIN_EMAILS') ? ADMIN_EMAILS : []);
    foreach ($admins as $mail) {
        $s = db()->prepare('SELECT email_bestaetigt, gesperrt, letzter_login FROM kunden WHERE email = ?');
        $s->execute([$mail]);
        $k = $s->fetch();
        $neu('Admin-Zugänge', 'Admin-Konto ' . h($mail), $k && $k['email_bestaetigt'] && !$k['gesperrt'] ? 'gut' : 'achtung',
            $k ? ('E-Mail ' . ($k['email_bestaetigt'] ? 'bestätigt' : 'NICHT bestätigt') . ', letzte Anmeldung ' . $tagZeit($k['letzter_login'])) : 'Konto existiert nicht.');
    }
    $notfall = defined('ADMIN_HASH') && !str_contains((string) ADMIN_HASH, 'ERSETZEN');
    $neu('Admin-Zugänge', 'Notfall-Zugang „' . h(defined('ADMIN_BENUTZER') ? ADMIN_BENUTZER : '?') . '“', $notfall ? 'gut' : 'achtung',
        $notfall ? 'Eingerichtet (Passwort nur als sicherer Hash gespeichert).' : 'Nicht eingerichtet.');
    $neu('Admin-Zugänge', 'Schutz gegen Passwort-Raten', 'gut', 'Nach 10 Fehlversuchen in 15 Minuten wird die Anmeldung für diese Adresse gesperrt; jede falsche Eingabe wird zusätzlich gebremst.');
    totp_spalten();
    foreach ($admins as $mail) {
        $s = db()->prepare('SELECT * FROM kunden WHERE email = ?');
        $s->execute([$mail]);
        $k = $s->fetch() ?: null;
        $neu('Admin-Zugänge', 'Zwei-Faktor-Anmeldung ' . h($mail), admin_2fa_aktiv($k) ? 'gut' : 'achtung',
            admin_2fa_aktiv($k) ? 'Eingeschaltet – Anmeldung nur mit Passwort UND Code aus der App.'
                : (!empty($k['totp_geheimnis']) ? 'Eingerichtet, aber noch ausgeschaltet.' : 'Noch nicht eingerichtet – Admin-Anmeldung nur mit Passwort.'),
            admin_2fa_aktiv($k) ? '' : 'Werkzeug unten auf dieser Seite: „Zwei-Faktor-Anmeldung“ – einrichten und einschalten, wann ihr wollt.');
    }
    $neu('Admin-Zugänge', 'Zweite Sperre vor der Verwaltung', zweite_sperre_aktiv() ? 'gut' : 'info',
        zweite_sperre_aktiv() ? 'Eingeschaltet – vor der Anmeldung fragt der Browser ein zusätzliches Passwort ab.' : 'Ausgeschaltet (optional, Werkzeug unten).');
    $alarm = alarm_empfaenger();
    $neu('Admin-Zugänge', 'Alarm-Mails', $alarm ? 'gut' : 'achtung',
        $alarm ? 'Gehen an ' . h(implode(', ', $alarm)) . ' – bei neuem Gerät, Fehlversuch-Serien, Sperren und rotem Wächter.' : 'Kein Empfänger hinterlegt.');

    /* ======================================================= 6. Anmeldungen & Angriffe */
    $protokoll = [];
    $zahl = static fn(string $sql) => (int) db()->query($sql)->fetchColumn();
    try {
        $fehlerAdmin24 = $zahl("SELECT COUNT(*) FROM sicherheit_protokoll WHERE art = 'admin_fehler' AND zeit > NOW() - INTERVAL 1 DAY");
        $fehlerKunde24 = $zahl("SELECT COUNT(*) FROM sicherheit_protokoll WHERE art = 'kunde_fehler' AND zeit > NOW() - INTERVAL 1 DAY");
        $sperren7 = $zahl("SELECT COUNT(*) FROM sicherheit_protokoll WHERE art = 'gesperrt' AND zeit > NOW() - INTERVAL 7 DAY");
        $neu('Anmeldungen', 'Fehlversuche Verwaltung (24 Std.)', $fehlerAdmin24 >= 20 ? 'kritisch' : ($fehlerAdmin24 >= 5 ? 'achtung' : 'gut'),
            "$fehlerAdmin24 falsche Admin-Anmeldungen in den letzten 24 Stunden.", $fehlerAdmin24 >= 5 ? 'Mehrere Fehlversuche – Admin-Passwort stark halten, ggf. ändern.' : '');
        $neu('Anmeldungen', 'Fehlversuche Kundenkonten (24 Std.)', $fehlerKunde24 >= 50 ? 'achtung' : 'gut', "$fehlerKunde24 falsche Kunden-Anmeldungen in den letzten 24 Stunden.");
        $neu('Anmeldungen', 'Gesperrte Anmeldeversuche (7 Tage)', $sperren7 >= 10 ? 'achtung' : 'gut', "$sperren7 Mal hat die Sperre nach zu vielen Fehlversuchen gegriffen.");
        $protokoll = db()->query("SELECT zeit, art, wer, ip, info FROM sicherheit_protokoll WHERE art IN ('admin_ok', 'admin_fehler', 'gesperrt', 'alarm')
                                  ORDER BY id DESC LIMIT 25")->fetchAll();
    } catch (Throwable $e) {
        $neu('Anmeldungen', 'Sicherheitsprotokoll', 'info', 'Noch keine Einträge – das Protokoll beginnt mit der nächsten Anmeldung.');
    }
    $aktiveSperren = $zahl("SELECT COUNT(*) FROM (SELECT kennung FROM anmeldeversuche WHERE zeit > NOW() - INTERVAL 15 MINUTE GROUP BY kennung HAVING COUNT(*) >= 10) x");
    $neu('Anmeldungen', 'Gerade gesperrt', $aktiveSperren ? 'achtung' : 'gut', $aktiveSperren ? "$aktiveSperren Adresse(n) sind wegen zu vieler Fehlversuche 15 Minuten gesperrt." : 'Niemand ist gerade gesperrt.');
    $gesperrteKonten = $zahl('SELECT COUNT(*) FROM kunden WHERE gesperrt = 1');
    $neu('Anmeldungen', 'Gesperrte Kundenkonten', 'info', "$gesperrteKonten Kundenkonto/-konten von euch gesperrt (Verwaltung → Kunden).");

    /* ======================================================= 7. Server & PHP */
    $neu('Server & PHP', 'PHP-Version', version_compare(PHP_VERSION, '8.2', '>=') ? 'gut' : 'achtung', 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.2', '>=') ? ' – erhält Sicherheits-Updates.' : ' – veraltet.'),
        version_compare(PHP_VERSION, '8.2', '>=') ? '' : 'Im Strato-Kundenbereich unter PHP-Version auf 8.4 stellen.');
    $fehlerAnzeigen = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
    $neu('Server & PHP', 'Fehlermeldungen für Besucher', $fehlerAnzeigen ? 'achtung' : 'gut', $fehlerAnzeigen ? 'PHP zeigt Fehler öffentlich an (verrät Interna).' : 'Ausgeschaltet – Besucher sehen keine technischen Fehlermeldungen.');
    $neu('Server & PHP', 'Fremden Code nachladen', filter_var(ini_get('allow_url_include'), FILTER_VALIDATE_BOOLEAN) ? 'kritisch' : 'gut',
        filter_var(ini_get('allow_url_include'), FILTER_VALIDATE_BOOLEAN) ? 'allow_url_include ist AN.' : 'Ausgeschaltet (allow_url_include = aus).');
    $neu('Server & PHP', 'Anmelde-Cookies', 'gut', 'Sitzungs-Cookie nur über https, für JavaScript unsichtbar (HttpOnly), SameSite=Lax; neue Sitzungs-ID bei jeder Anmeldung; alle Formulare mit Schutz gegen Fremdaufrufe (CSRF).');
    $neu('Server & PHP', 'Passwörter der Kunden', 'gut', 'Nur als sicherer Hash gespeichert (password_hash) – auch wir können sie nicht lesen.');
    $neu('Server & PHP', 'Datenbank-Zugriffe', 'gut', 'Alle Abfragen mit vorbereiteten Anweisungen – Schutz gegen SQL-Einschleusung.');

    /* ======================================================= 8. E-Mail-Absender (Schutz vor gefälschten Mails) */
    if (function_exists('dns_get_record')) {
        foreach (array_unique(['triplex-q.com', 'triplex-q.de', preg_replace('/^.*@/', '', (string) (preg_match('/<([^>]+)>/', (string) ABSENDER, $m) ? $m[1] : ABSENDER))]) as $dom) {
            $txt = array_map(static fn($r) => $r['txt'] ?? '', @dns_get_record($dom, DNS_TXT) ?: []);
            $spf = array_values(array_filter($txt, static fn($t) => str_starts_with(strtolower($t), 'v=spf1')));
            $dmarc = array_values(array_filter(array_map(static fn($r) => $r['txt'] ?? '', @dns_get_record('_dmarc.' . $dom, DNS_TXT) ?: []),
                static fn($t) => str_starts_with(strtolower($t), 'v=dmarc1')));
            $neu('E-Mail-Schutz', "SPF für $dom", $spf ? 'gut' : 'achtung', $spf ? '<code>' . h(mb_substr($spf[0], 0, 120)) . '</code>' : 'Kein SPF-Eintrag – Mails mit eurem Absender lassen sich leichter fälschen und landen eher im Spam.',
                $spf ? '' : "Im Strato-Kundenbereich: Domains → $dom → DNS → TXT- und CNAME-Records → neuer TXT-Eintrag (Name leer) mit dem Wert <code>v=spf1 redirect=smtp.rzone.de</code>.");
            $neu('E-Mail-Schutz', "DMARC für $dom", $dmarc ? 'gut' : 'achtung', $dmarc ? '<code>' . h(mb_substr($dmarc[0], 0, 120)) . '</code>' : 'Kein DMARC-Eintrag – Empfänger wissen nicht, wie mit gefälschten Mails umzugehen ist.',
                $dmarc ? '' : 'TXT-Record _dmarc.' . $dom . ' anlegen (Wert gebe ich dir).');
        }
    }

    /* ======================================================= 9. Datenbank & Google */
    try {
        $tabellen = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $neu('Datenbank & Sichtbarkeit', 'Datenbank', 'gut', 'Verbunden, ' . count($tabellen) . ' Tabellen, ' . $zahl('SELECT COUNT(*) FROM kunden') . ' Kunden, ' . $zahl('SELECT COUNT(*) FROM bestellungen') . ' Bestellungen.');
    } catch (Throwable $e) {
        $neu('Datenbank & Sichtbarkeit', 'Datenbank', 'kritisch', 'Keine Verbindung: ' . h($e->getMessage()));
    }
    $liste = sicherungen();
    $alter = $liste ? (int) floor((time() - $liste[0]['zeit']) / 3600) : null;
    $neu('Datenbank & Sichtbarkeit', 'Eigene Datensicherung', $alter === null ? 'achtung' : ($alter <= 30 ? 'gut' : 'achtung'),
        $liste ? count($liste) . ' Sicherung(en), die neueste von ' . date('d.m.Y H:i', $liste[0]['zeit']) . ' Uhr (' . number_format($liste[0]['groesse'] / 1024, 0, ',', '.') . ' KB). Zusätzlich sichert Strato selbst (BackupControl).'
            : 'Noch keine eigene Sicherung vorhanden.', $alter === null || $alter > 30 ? 'Unten auf „Jetzt sichern“ klicken – nach Einrichtung des Zeitplans passiert das jede Nacht automatisch.' : '');
    $lauf = letzter_lauf();
    $neu('Datenbank & Sichtbarkeit', 'Täglicher automatischer Lauf', $lauf && strtotime($lauf['zeit']) > time() - 30 * 3600 ? 'gut' : 'achtung',
        $lauf ? 'Letzter Lauf ' . date('d.m.Y H:i', strtotime($lauf['zeit'])) . ' Uhr: ' . (int) $lauf['punkte'] . ' Punkte, ' . (int) $lauf['kritisch'] . ' kritisch.'
            : 'Noch kein automatischer Lauf.', $lauf && strtotime($lauf['zeit']) > time() - 30 * 3600 ? '' : 'Im Strato-Kundenbereich einen Cron-Job auf <code>/triplex-q/admin/cron_taeglich.php</code> (täglich) anlegen – Anleitung von Kevin/Claude.');
    $live = $HOST === 'www.triplex-q.com';
    $noindex = str_contains(strtolower($anfang . implode(' ', $kopfStart)), 'noindex');
    $neu('Datenbank & Sichtbarkeit', 'Google-Sichtbarkeit', $live && $noindex ? 'achtung' : 'gut', $live ? ($noindex ? 'Die Startseite ist für Google gesperrt (noindex)!' : 'Startseite ist für Google sichtbar, Verwaltung und Warenkorb sind ausgeschlossen.') : 'Testadresse.');

    /* ======================================================= Auswertung */
    $zaehle = array_count_values(array_column($pruefungen, 'stand'));
    $kritisch = $zaehle['kritisch'] ?? 0;
    $achtung = $zaehle['achtung'] ?? 0;
    $gut = $zaehle['gut'] ?? 0;
    $punkte = (int) round($gut / max(1, $gut + $achtung + $kritisch) * 100);
    $gesamt = $kritisch ? ['kritisch', 'Handlungsbedarf', 'Mindestens ein kritischer Punkt – bitte sofort ansehen.']
        : ($achtung ? ['achtung', 'Gut geschützt – mit Verbesserungspotenzial', 'Keine akuten Probleme. Die gelben Punkte machen die Seite noch sicherer.']
            : ['gut', 'Sehr gut geschützt', 'Alle Prüfungen bestanden.']);
    return compact('pruefungen', 'protokoll', 'punkte', 'gesamt', 'zaehle', 'kritisch', 'achtung', 'gut');
}
