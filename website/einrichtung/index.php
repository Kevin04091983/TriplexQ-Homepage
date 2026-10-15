<?php
/**
 * TRIPLEX-Q – Einrichtungs-Assistent (einmalig nach dem ersten Hochladen).
 *
 * Prüft den Server, testet die Datenbank, legt die Tabellen an, richtet das Admin-Konto ein und
 * schreibt api/einstellungen.php. Funktioniert nur, solange es api/einstellungen.php noch nicht gibt,
 * und löscht sich nach erfolgreicher Einrichtung selbst.
 */
declare(strict_types=1);

$WEB = dirname(__DIR__);
$ZIEL = $WEB . '/api/einstellungen.php';
$SCHEMA = $WEB . '/api/schema.sql';

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Nur echte Aufrufe vom eigenen Rechner gelten als lokal (Testumgebung) – der Host-Name allein lässt sich fälschen
$lokal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// ---------- Sperre: schon eingerichtet ----------
if (is_file($ZIEL)) {
    http_response_code(403);
    seite('Bereits eingerichtet', '<p>Die Seite ist bereits eingerichtet. Der Assistent ist gesperrt.</p>
      <p>Zur Verwaltung: <a href="/admin/">/admin/</a> · Zum Kundenkonto: <a href="/konto.html">/konto.html</a></p>');
    exit;
}

session_name('tq_einrichtung');
session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

// ---------- Server-Prüfung ----------
$pruefungen = [
    ['PHP 8.1 oder neuer', version_compare(PHP_VERSION, '8.1', '>='), 'gefunden: PHP ' . PHP_VERSION],
    ['PDO MySQL (Datenbank)', extension_loaded('pdo_mysql'), ''],
    ['Verschlüsselte Verbindung (https)', $https || $lokal, $https ? '' : ($lokal ? 'lokal – ohne https erlaubt' : 'SSL für die Domain im Strato-Kundenbereich aktivieren')],
    ['Ordner api/ beschreibbar', is_writable($WEB . '/api'), ''],
    ['Datei api/schema.sql vorhanden', is_file($SCHEMA), ''],
    ['mail() verfügbar', function_exists('mail'), ''],
];
$serverOk = !in_array(false, array_column($pruefungen, 1), true);

$fehler = [];
$w = [
    'db_host' => '', 'db_port' => '', 'db_name' => '', 'db_user' => '',
    'seiten_url' => ($https ? 'https://' : 'http://') . preg_replace('/[^a-z0-9.:\-]/i', '', $_SERVER['HTTP_HOST'] ?? ''),
    'admin_email' => '', 'admin_vorname' => '', 'admin_nachname' => '',
    'absender' => 'shop@triplex-q.de', 'empf_bestellung' => 'order@wkz-schmidt.de', 'empf_kontakt' => 'kontakt@triplex-q.de',
    'mail_aus' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($w as $k => $_) {
        $w[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $pw = (string) ($_POST['admin_pw'] ?? '');
    $pw2 = (string) ($_POST['admin_pw2'] ?? '');

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $fehler[] = 'Sitzung abgelaufen – bitte die Seite neu laden.';
    }
    if (!$serverOk) {
        $fehler[] = 'Die Server-Prüfung ist noch nicht vollständig grün.';
    }
    // Nur Datenbanken von Strato (bzw. lokal zum Testen) – so kann niemand Fremdes die Seite auf eine eigene Datenbank umbiegen
    if (!preg_match('/^[a-z0-9.\-]+$/i', $w['db_host'])
        || !($lokal || preg_match('/\.(webspace-host\.com|strato\.de|stratoserver\.net)$/i', $w['db_host']))) {
        $fehler[] = 'Datenbank-Server: bitte den Servernamen aus dem Strato-Kundenbereich eintragen (endet auf „.webspace-host.com“).';
    }
    if ($w['db_port'] !== '' && !ctype_digit($w['db_port'])) {
        $fehler[] = 'Port: nur Ziffern (normalerweise leer lassen).';
    }
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $w['db_name']) || !preg_match('/^[A-Za-z0-9_\-]+$/', $w['db_user'])) {
        $fehler[] = 'Datenbankname und Benutzer: bitte genau so wie im Strato-Kundenbereich eintragen.';
    }
    if (!preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', $w['seiten_url'])) {
        $fehler[] = 'Adresse der Seite: z. B. https://triplex-q.de (ohne Schrägstrich am Ende).';
    }
    foreach (['admin_email' => 'Admin-E-Mail', 'absender' => 'Absender', 'empf_bestellung' => 'Empfänger Bestellungen', 'empf_kontakt' => 'Empfänger Kontakt'] as $k => $name) {
        if (!filter_var($w[$k], FILTER_VALIDATE_EMAIL)) {
            $fehler[] = $name . ': keine gültige E-Mail-Adresse.';
        }
    }
    if ($w['admin_vorname'] === '' || $w['admin_nachname'] === '') {
        $fehler[] = 'Bitte Vor- und Nachname für das Admin-Konto angeben.';
    }
    if (mb_strlen($pw) < 10) {
        $fehler[] = 'Admin-Passwort: mindestens 10 Zeichen.';
    } elseif ($pw !== $pw2) {
        $fehler[] = 'Die beiden Admin-Passwörter stimmen nicht überein.';
    }

    $pdo = null;
    if (!$fehler) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $w['db_host'] . ($w['db_port'] !== '' ? ';port=' . $w['db_port'] : '') . ';dbname=' . $w['db_name'] . ';charset=utf8mb4',
                $w['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );
        } catch (Throwable $e) {
            $fehler[] = 'Keine Verbindung zur Datenbank. Bitte Server, Datenbankname, Benutzer und Passwort prüfen. (' . h($e->getMessage()) . ')';
        }
    }

    if (!$fehler && $pdo) {
        try {
            // 1) Tabellen anlegen (vorhandene bleiben unverändert)
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($SCHEMA));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $befehl) {
                $pdo->exec($befehl);
            }
            // 2) Admin-Konto (normales Kundenkonto, E-Mail bestätigt)
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $s = $pdo->prepare('SELECT id FROM kunden WHERE email = ?');
            $s->execute([mb_strtolower($w['admin_email'])]);
            if ($id = $s->fetchColumn()) {
                $pdo->prepare('UPDATE kunden SET passwort_hash = ?, email_bestaetigt = 1, passwort_neu_noetig = 0, gesperrt = 0 WHERE id = ?')
                    ->execute([$hash, $id]);
            } else {
                $pdo->prepare('INSERT INTO kunden (email, passwort_hash, firma, vorname, nachname, strasse, plz, ort, telefon, email_bestaetigt)
                               VALUES (?, ?, "Präzisionswerkzeuge Schmidt e.K.", ?, ?, "Ackerstraße 12", "98544", "Zella-Mehlis", "", 1)')
                    ->execute([mb_strtolower($w['admin_email']), $hash, $w['admin_vorname'], $w['admin_nachname']]);
            }
            // 3) Einstellungen schreiben
            $v = fn($x) => var_export($x, true);
            $absender = 'TRIPLEX-Q Webshop <' . $w['absender'] . '>';
            $inhalt = "<?php\n/* Erzeugt vom Einrichtungs-Assistenten am " . date('d.m.Y H:i') . ". Enthält Zugangsdaten – nicht weitergeben. */\n\n"
                . "// ---------- E-Mail ----------\n"
                . 'const EMPFAENGER_BESTELLUNG = ' . $v($w['empf_bestellung']) . ";\n"
                . 'const EMPFAENGER_KONTAKT = ' . $v($w['empf_kontakt']) . ";\n"
                . 'const ABSENDER = ' . $v($absender) . ";\n"
                . "// true = es werden keine E-Mails verschickt (Testbetrieb)\n"
                . 'const MAIL_AUS = ' . ($w['mail_aus'] ? 'true' : 'false') . ";\n\n"
                . "// ---------- Shop ----------\n"
                . "const VERSAND_NETTO = 7.90;\nconst MWST = 0.19;\n"
                . 'const SEITEN_URL = ' . $v(rtrim($w['seiten_url'], '/')) . ";\n\n"
                . "// ---------- Datenbank ----------\n"
                . 'const DB_HOST = ' . $v($w['db_host']) . ";\n"
                . ($w['db_port'] !== '' ? 'const DB_PORT = ' . (int) $w['db_port'] . ";\n" : '')
                . 'const DB_NAME = ' . $v($w['db_name']) . ";\n"
                . 'const DB_USER = ' . $v($w['db_user']) . ";\n"
                . 'const DB_PASS = ' . $v($dbPass) . ";\n\n"
                . "// ---------- Admin ----------\n"
                . "// Wer sich mit einer dieser E-Mail-Adressen anmeldet, ist Admin (Preise, Verwaltung).\n"
                . 'const ADMIN_EMAILS = [' . $v(mb_strtolower($w['admin_email'])) . "];\n"
                . "// Notfall-Zugang zur Verwaltung (/admin/) ohne Kundenkonto – gleiches Passwort wie das Admin-Konto\n"
                . "const ADMIN_BENUTZER = 'triplexq';\n"
                . 'const ADMIN_HASH = ' . $v($hash) . ";\n";
            if (file_put_contents($ZIEL, $inhalt, LOCK_EX) === false) {
                throw new RuntimeException('api/einstellungen.php konnte nicht geschrieben werden (Schreibrechte?).');
            }
            @chmod($ZIEL, 0600);

            // 4) Testmail
            $mailHinweis = 'Mailversand ist ausgeschaltet (Testbetrieb).';
            if (!$w['mail_aus']) {
                $ok = @mail($w['admin_email'], '=?UTF-8?B?' . base64_encode('TRIPLEX-Q: Einrichtung abgeschlossen') . '?=',
                    "Die neue TRIPLEX-Q Seite ist eingerichtet.\n\nAdmin-Konto: " . $w['admin_email'] . "\nAnmelden: " . rtrim($w['seiten_url'], '/') . "/konto.html\n",
                    'From: ' . $absender . "\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0", '-f' . $w['absender']);
                $mailHinweis = $ok ? 'Eine Testmail an ' . h($w['admin_email']) . ' ist unterwegs. Kommt sie nicht an (auch Spam prüfen), das Postfach ' . h($w['absender']) . ' im Strato-Kundenbereich prüfen.'
                    : 'Die Testmail konnte nicht verschickt werden. Bitte prüfen, ob das Postfach ' . h($w['absender']) . ' bei Strato angelegt ist.';
            }

            // 5) Aufräumen: Hilfsdatei und Assistent entfernen
            @unlink($WEB . '/admin/passwort-hash.php');
            $weg = @unlink(__FILE__);
            @rmdir(__DIR__);
            $url = h(rtrim($w['seiten_url'], '/'));
            seite('Fertig eingerichtet ✔', "<p><strong>Die Seite ist eingerichtet.</strong></p>
              <ul><li>Tabellen in der Datenbank angelegt</li><li>Admin-Konto <b>" . h($w['admin_email']) . "</b> eingerichtet (E-Mail bestätigt)</li>
              <li>Einstellungen gespeichert</li><li>{$mailHinweis}</li>
              <li>" . ($weg ? 'Der Assistent hat sich gelöscht.' : '<b>Bitte den Ordner <code>einrichtung/</code> per SFTP löschen.</b>') . "</li></ul>
              <p><a class=\"btn\" href=\"{$url}/konto.html\">Jetzt als Admin anmelden</a> &nbsp; <a href=\"{$url}/admin/\">Verwaltung öffnen</a></p>");
            exit;
        } catch (Throwable $e) {
            @unlink($ZIEL);
            $fehler[] = 'Einrichtung abgebrochen: ' . h($e->getMessage());
        }
    }
}

// ---------- Formular ----------
$liste = '';
foreach ($pruefungen as [$name, $ok, $info]) {
    $liste .= '<li class="' . ($ok ? 'ok' : 'nein') . '">' . ($ok ? '✔' : '✖') . ' ' . h($name) . ($info ? ' <small>' . h($info) . '</small>' : '') . '</li>';
}
$fehlerHtml = $fehler ? '<div class="fehler"><b>Bitte prüfen:</b><ul><li>' . implode('</li><li>', $fehler) . '</li></ul></div>' : '';
$f = fn($k) => h($w[$k]);
$csrf = h($_SESSION['csrf']);
$mailAus = $w['mail_aus'] ? 'checked' : '';
seite('Einrichtung', <<<HTML
  <p>Einmalige Einrichtung der neuen TRIPLEX-Q Seite. Die Angaben zur Datenbank stehen im Strato-Kundenbereich unter
  <i>Datenbanken und Webspace → Datenbankverwaltung</i>.</p>
  <h2>1. Server</h2><ul class="pruef">{$liste}</ul>
  {$fehlerHtml}
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="{$csrf}">
    <h2>2. Datenbank</h2>
    <label>Datenbank-Server (Host)<input name="db_host" value="{$f('db_host')}" placeholder="database-5000000000.webspace-host.com" required></label>
    <div class="zwei"><label>Datenbankname<input name="db_name" value="{$f('db_name')}" placeholder="dbs12345678" required></label>
    <label>Benutzer<input name="db_user" value="{$f('db_user')}" placeholder="dbu1234567" required></label></div>
    <div class="zwei"><label>Datenbank-Passwort<input type="password" name="db_pass" autocomplete="new-password" required></label>
    <label>Port <small>(normalerweise leer lassen)</small><input name="db_port" value="{$f('db_port')}" inputmode="numeric"></label></div>

    <h2>3. Ihr Admin-Konto</h2>
    <p class="klein">Damit melden Sie sich ganz normal auf der Kontoseite an und können Preise direkt auf der Seite ändern.</p>
    <label>E-Mail-Adresse<input type="email" name="admin_email" value="{$f('admin_email')}" required></label>
    <div class="zwei"><label>Vorname<input name="admin_vorname" value="{$f('admin_vorname')}" required></label>
    <label>Nachname<input name="admin_nachname" value="{$f('admin_nachname')}" required></label></div>
    <div class="zwei"><label>Passwort <small>(mind. 10 Zeichen)</small><input type="password" name="admin_pw" autocomplete="new-password" required minlength="10"></label>
    <label>Passwort wiederholen<input type="password" name="admin_pw2" autocomplete="new-password" required minlength="10"></label></div>

    <h2>4. E-Mail und Adresse</h2>
    <label>Adresse der Seite<input name="seiten_url" value="{$f('seiten_url')}" required></label>
    <label>Absender der Shop-Mails <small>(Postfach muss bei Strato angelegt sein)</small><input type="email" name="absender" value="{$f('absender')}" required></label>
    <div class="zwei"><label>Bestellungen gehen an<input type="email" name="empf_bestellung" value="{$f('empf_bestellung')}" required></label>
    <label>Kontaktanfragen gehen an<input type="email" name="empf_kontakt" value="{$f('empf_kontakt')}" required></label></div>
    <label class="check"><input type="checkbox" name="mail_aus" value="1" {$mailAus}> Testbetrieb: keine E-Mails verschicken</label>

    <button type="submit">Einrichten</button>
  </form>
HTML);

function seite(string $titel, string $inhalt): void
{
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>' . h($titel) . ' | TRIPLEX-Q</title><style>
  :root { --blau:#003b9f; --rot:#e40001; --navy:#06163f; --grau:#edf1f8; --linie:#dde4f0; }
  * { box-sizing:border-box; } body { margin:0; font:16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--grau); color:var(--navy); }
  header { background:var(--navy); color:#fff; padding:18px 20px; } header b { font-style:italic; letter-spacing:.02em; } header span { color:#ff5a5b; }
  main { max-width:760px; margin:24px auto; background:#fff; border-radius:16px; padding:28px clamp(16px,4vw,36px); box-shadow:0 12px 32px rgb(6 22 63/.09); }
  h1 { margin:0 0 .4em; font-size:1.7rem; } h2 { font-size:1.1rem; margin:1.8em 0 .6em; padding-top:1em; border-top:1px solid var(--linie); }
  label { display:grid; gap:4px; font-weight:600; font-size:.9rem; margin:0 0 12px; } small, .klein { font-weight:400; color:#4a5876; font-size:.85rem; }
  input { font:inherit; padding:10px 12px; border:1.5px solid var(--linie); border-radius:8px; width:100%; }
  input:focus { outline:3px solid #3d7bff55; border-color:var(--blau); }
  .zwei { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; } @media (max-width:600px) { .zwei { grid-template-columns:1fr; } }
  label.check { display:flex; align-items:center; gap:8px; font-weight:500; } label.check input { width:auto; }
  button, .btn { display:inline-block; background:var(--rot); color:#fff; border:0; border-radius:99px; padding:12px 26px; font:600 1rem system-ui, sans-serif; cursor:pointer; text-decoration:none; margin-top:10px; }
  .pruef { list-style:none; padding:0; } .pruef li { padding:4px 0; } .ok { color:#0f8a4f; } .nein { color:var(--rot); font-weight:600; }
  .fehler { background:#fdeaea; border-left:4px solid var(--rot); padding:10px 16px; border-radius:8px; margin:16px 0; }
  code { background:var(--grau); padding:1px 5px; border-radius:4px; }
</style></head><body><header><b>TRIPLEX<span>-</span>Q</b> · Einrichtung</header><main><h1>' . h($titel) . '</h1>' . $inhalt . '</main></body></html>';
}
