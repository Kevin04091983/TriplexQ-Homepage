<?php
/**
 * Hilfsseite: erzeugt den Passwort-Hash für die Verwaltung.
 * Wunschpasswort eingeben, Hash kopieren, in api/einstellungen.php bei ADMIN_HASH eintragen –
 * danach diese Datei bitte vom Server löschen.
 */
declare(strict_types=1);
header('X-Robots-Tag: noindex');
$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passwort']) && $_POST['passwort'] !== '') {
    $hash = password_hash((string) $_POST['passwort'], PASSWORD_DEFAULT);
}
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Passwort-Hash erzeugen</title>
<style>body{font:15px/1.6 system-ui,sans-serif;max-width:640px;margin:8vh auto;padding:0 20px;color:#0c1830}
input,button{font:inherit;padding:10px 12px;border-radius:8px;border:1.5px solid #dde4f0}
button{background:#003b9f;color:#fff;border:0;font-weight:600;cursor:pointer}
code{display:block;word-break:break-all;background:#f4f7fc;border:1px solid #dde4f0;padding:14px;border-radius:8px;margin-top:14px}</style>
</head><body>
<h1>Passwort-Hash für die Verwaltung</h1>
<form method="post">
  <input name="passwort" type="text" placeholder="Wunschpasswort" size="32" required autofocus>
  <button type="submit">Hash erzeugen</button>
</form>
<?php if ($hash): ?>
<p>In <code style="display:inline;padding:2px 6px">api/einstellungen.php</code> bei <b>ADMIN_HASH</b> eintragen:</p>
<code><?= htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') ?></code>
<p><strong>Danach diese Datei vom Server löschen.</strong></p>
<?php endif; ?>
</body></html>
