<?php
/**
 * TRIPLEX-Q Verwaltung – Wächter: prüft live, wie sicher die Seite gerade ist (nur Admin).
 * Wird aus admin/index.php eingebunden; direkt aufgerufen gibt es nur 404.
 *
 * Prüft: Verschlüsselung + Zertifikat, Sicherheits-Header, geschützte Dateien (von außen abgefragt),
 * Datei-Integrität gegen die Prüfliste vom letzten Hochladen, Zugangsdaten-Datei, PHP-Einstellungen,
 * Admin-Zugänge, Anmeldungen/Fehlversuche/Sperren, Mail-Absender (SPF/DMARC), Datenbank, Google-Sichtbarkeit.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || empty($_SESSION['admin'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/sicherheit_funktionen.php';
extract(waechter_pruefen());
$tagZeit = static fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '–';
$gruppen = [];
foreach ($pruefungen as $p) { $gruppen[$p['gruppe']][] = $p; }
$symbol = ['gut' => '✔', 'achtung' => '!', 'kritisch' => '✖', 'info' => 'i'];
$artName = ['admin_ok' => 'Admin angemeldet', 'admin_fehler' => 'Admin-Anmeldung fehlgeschlagen', 'gesperrt' => 'Gesperrt (zu viele Versuche)'];

/** Stand einer Gruppe von Prüfungen (Titel beginnt mit $anfang): alle gut → true, sonst false, keine gefunden → null */
$alleGut = static function (string $anfang) use ($pruefungen): ?bool {
    $treffer = array_filter($pruefungen, static fn($p) => str_starts_with(strip_tags(html_entity_decode($p['titel'])), $anfang));
    return $treffer ? !array_filter($treffer, static fn($p) => $p['stand'] !== 'gut') : null;
};
/** Domain Guard: Sperre der Registrierungsstelle für triplex-q.com (RDAP), z. B. "client transfer prohibited" */
$domainGuard = (static function (): ?bool {
    if (!function_exists('curl_init')) {
        return null;
    }
    $c = curl_init('https://rdap.verisign.com/com/v1/domain/triplex-q.com');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
    $d = json_decode((string) curl_exec($c), true);
    if (!is_array($d) || !isset($d['status'])) {
        return null;
    }
    return (bool) array_filter((array) $d['status'], static fn($st) => str_contains((string) $st, 'prohibited'));
})();
$laufOk = $alleGut('Täglicher automatischer Lauf');
$spfOk = $alleGut('SPF für');
$zweiFaktorAn = (bool) irgendein_admin_2fa();
// [Baustein, Stand-Text, Farbe gruen|gelb|rot, Erklärung]
$vorschlaege = [
    ['Alarm-Mails', 'eingebaut', 'gruen', 'Neues Gerät, Fehlversuch-Serien, Sperren, roter Wächter, Änderungen an Sperre/Zwei-Faktor.'],
    ['Täglicher Wächter-Lauf', $laufOk ? 'läuft' : 'Cron-Job fehlt', $laufOk ? 'gruen' : 'rot',
        $laufOk ? 'Läuft jede Nacht (admin/cron_taeglich.php): Sicherung + Prüfung + Alarm.' : 'Skript admin/cron_taeglich.php im Strato-Kundenbereich als täglichen Cron-Job eintragen.'],
    ['Eigene Datensicherung', 'eingebaut', 'gruen', 'Jede Nacht automatisch (30 Tage), jederzeit „Jetzt sichern“ und Download.'],
    ['Zweite Sperre vor der Verwaltung', zweite_sperre_aktiv() ? 'eingeschaltet' : 'eingebaut', 'gruen', zweite_sperre_aktiv() ? 'Der Browser fragt vor der Verwaltung ein zusätzliches Passwort ab.' : 'Optional – unten ein- und ausschaltbar (derzeit aus).'],
    ['Passwort-Prüfung (Datenlecks)', 'eingebaut', 'gruen', 'Kunden- und Admin-Passwörter aus bekannten Datenlecks werden abgelehnt (anonymes Verfahren).'],
    ['Strengere Content-Security-Policy', 'eingebaut', 'gruen', 'Skripte nur von der eigenen Seite, Videos nur von YouTube (nocookie) und Instagram.'],
    ['Zwei-Faktor-Anmeldung', $zweiFaktorAn ? 'eingeschaltet' : 'vorbereitet', $zweiFaktorAn ? 'gruen' : 'gelb',
        $zweiFaktorAn ? 'Bei der Anmeldung wird zusätzlich der Code aus der App abgefragt.' : 'Werkzeug ist fertig – einrichten und einschalten, wann ihr wollt.'],
    ['SPF-Eintrag', $spfOk ? 'erledigt' : 'bei Strato', $spfOk ? 'gruen' : 'rot',
        $spfOk ? 'triplex-q.com und triplex-q.de: v=spf1 redirect=smtp.rzone.de – nur Strato darf in eurem Namen Mails senden.' : 'Ein DNS-Eintrag je Domain (v=spf1 redirect=smtp.rzone.de).'],
    ['Strato Domain Guard', $domainGuard ? 'aktiv' : 'bei Strato', $domainGuard ? 'gruen' : 'gelb',
        $domainGuard ? 'triplex-q.com ist bei der Registrierungsstelle gegen Übertragung gesperrt.'
            : 'Empfohlen: sperrt die Domain gegen unerlaubte Übertragung – Strato → Domains → Domain → Reiter „Domain Guard“'
              . ($domainGuard === null ? ' (Stand konnte nicht abgefragt werden).' : '.')],
];
$csrf = h((string) ($_SESSION['csrf_admin'] ?? ''));
$sicherungen = sicherungen();
$lauf = letzter_lauf();
totp_spalten();
$adminKonten = [];
foreach (array_map('strtolower', ADMIN_EMAILS) as $mail) {
    $st = db()->prepare('SELECT * FROM kunden WHERE email = ?');
    $st->execute([$mail]);
    $adminKonten[$mail] = $st->fetch() ?: null;
}
$cronPfad = '/triplex-q/admin/cron_taeglich.php';
?>?>
<h1>Wächter <span class="pos">– Sicherheit der Seite, geprüft am <?= h(date('d.m.Y')) ?> um <?= h(date('H:i')) ?> Uhr</span></h1>

<div class="karte waechter-kopf <?= h($gesamt[0]) ?>">
  <div class="waechter-ring" style="--p:<?= $punkte ?>"><b><?= $punkte ?></b><small>von 100</small></div>
  <div>
    <h2><?= h($gesamt[1]) ?></h2>
    <p><?= h($gesamt[2]) ?></p>
    <p class="waechter-zahlen"><span class="w-gut">✔ <?= $gut ?> in Ordnung</span> <span class="w-achtung">! <?= $achtung ?> Hinweise</span>
      <span class="w-kritisch">✖ <?= $kritisch ?> kritisch</span> <span class="pos">· <?= $zaehle['info'] ?? 0 ?> Infos</span></p>
    <a class="knopf-link" href="?seite=waechter">Jetzt neu prüfen</a>
  </div>
</div>

<div class="waechter-gruppen">
<?php foreach ($gruppen as $name => $liste): ?>
  <div class="karte">
    <h2><?= h($name) ?></h2>
    <?php foreach ($liste as $p): ?>
      <div class="w-zeile <?= h($p['stand']) ?>">
        <span class="w-punkt"><?= $symbol[$p['stand']] ?></span>
        <div><strong><?= $p['titel'] ?></strong><br><span class="pos"><?= $p['text'] ?></span>
          <?= $p['tipp'] ? '<br><span class="w-tipp">→ ' . $p['tipp'] . '</span>' : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="karte">
  <h2>Admin-Anmeldungen <span class="pos">(letzte 25, IP-Adressen gekürzt)</span></h2>
  <table class="kompakt">
    <tr><th>Zeit</th><th>Ereignis</th><th>Wer</th><th>Wo</th><th>IP</th></tr>
    <?php foreach ($protokoll as $z): ?>
      <tr class="<?= $z['art'] === 'admin_ok' ? '' : 'w-rot' ?>"><td><?= h($tagZeit($z['zeit'])) ?></td><td><?= h($artName[$z['art']] ?? $z['art']) ?></td>
        <td><?= h($z['wer']) ?></td><td><?= h($z['info']) ?></td><td><?= h($z['ip']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$protokoll): ?><tr><td colspan="5" class="pos">Noch keine Einträge – das Protokoll beginnt mit der nächsten Anmeldung.</td></tr><?php endif; ?>
  </table>
</div>


<h2 class="abschnitt-titel">Werkzeuge</h2>
<div class="waechter-gruppen">
  <div class="karte">
    <h2>Datensicherung</h2>
    <p class="pos">Komplette Datenbank (Kunden, Bestellungen, Preise, Einstellungen) als Datei. Jede Nacht automatisch, 30 Tage aufbewahrt.
      Wiederherstellen: in phpMyAdmin importieren (macht Kevin/Claude).</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="sicherung_jetzt">
      <button type="submit">Jetzt sichern</button></form>
    <table class="kompakt" style="margin-top:12px">
      <tr><th>Sicherung</th><th class="r">Größe</th><th></th></tr>
      <?php foreach (array_slice($sicherungen, 0, 10) as $sich): ?>
        <tr><td><?= h(date('d.m.Y H:i', $sich['zeit'])) ?> Uhr</td><td class="r"><?= h(number_format($sich['groesse'] / 1024, 0, ',', '.')) ?> KB</td>
          <td class="r"><a href="?seite=waechter&amp;sicherung=<?= h(rawurlencode($sich['name'])) ?>">Herunterladen</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$sicherungen): ?><tr><td colspan="3" class="pos">Noch keine Sicherung.</td></tr><?php endif; ?>
    </table>
    <?php if (count($sicherungen) > 10): ?><p class="pos">… und <?= count($sicherungen) - 10 ?> ältere.</p><?php endif; ?>
  </div>

  <div class="karte">
    <h2>Alarm-Mails</h2>
    <p class="pos">Gehen an: <strong><?= h(implode(', ', alarm_empfaenger()) ?: '–') ?></strong></p>
    <ul class="pos" style="padding-left:18px">
      <li>Admin-Anmeldung von einem <strong>neuen Gerät</strong></li>
      <li>5 oder mehr falsche Admin-Anmeldungen in einer Stunde</li>
      <li>Anmeldung wegen zu vieler Fehlversuche gesperrt</li>
      <li>Nächtlicher Wächter: kritischer Punkt oder neues Problem</li>
      <li>Zwei-Faktor oder zweite Sperre ein-/ausgeschaltet</li>
    </ul>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="alarm_test">
      <button type="submit" class="hell">Test-Alarm senden</button></form>
  </div>

  <div class="karte">
    <h2>Täglicher automatischer Lauf</h2>
    <?php if ($lauf): ?>
      <p>Letzter Lauf: <strong><?= h(date('d.m.Y H:i', strtotime((string) $lauf['zeit']))) ?> Uhr</strong> – <?= (int) $lauf['punkte'] ?> Punkte,
        <?= (int) $lauf['kritisch'] ?> kritisch, <?= (int) $lauf['achtung'] ?> Hinweise.</p>
    <?php else: ?>
      <p class="w-tipp">Noch nicht eingerichtet.</p>
    <?php endif; ?>
    <p class="pos">Einmalig im Strato-Kundenbereich (Paket Webshop Now Pro) → <strong>Datenbanken und Webspace → Cron-Jobs</strong> →
      neuen Cron-Job anlegen: Skript <code><?= h($cronPfad) ?></code>, täglich, z. B. 3:00 Uhr, PHP-Version 8.4.
      Der Lauf sichert die Datenbank, prüft alles und meldet sich nur bei Problemen.</p>
  </div>

  <div class="karte">
    <h2>Zweite Sperre vor der Verwaltung</h2>
    <?php if (zweite_sperre_aktiv()): ?>
      <p><span class="marke gruen">eingeschaltet</span> Vor der Verwaltung fragt der Browser ein zusätzliches Passwort ab.</p>
      <form method="post" onsubmit="return confirm('Zweite Sperre wirklich ausschalten?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="sperre_aus">
        <button type="submit" class="hell">Ausschalten</button></form>
    <?php else: ?>
      <p class="pos">Optional: Der Browser fragt vor der Verwaltung ein <strong>zweites, eigenes Passwort</strong> ab – noch bevor die Anmeldeseite erscheint.
        Passwort gut aufbewahren! (Vergessen? Kevin/Claude können die Sperre über den Server wieder entfernen.)</p>
      <form method="post" class="sperre-form" autocomplete="off"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="sperre_an">
        <label>Benutzername<input name="benutzer" required minlength="3" maxlength="40" pattern="[A-Za-z0-9._-]+"></label>
        <label>Passwort (mind. 12 Zeichen)<input type="password" name="passwort" required minlength="12" autocomplete="new-password"></label>
        <label>Passwort wiederholen<input type="password" name="passwort2" required minlength="12" autocomplete="new-password"></label>
        <button type="submit">Zweite Sperre einschalten</button></form>
    <?php endif; ?>
  </div>

  <div class="karte">
    <h2>Zwei-Faktor-Anmeldung <span class="pos">(vorbereitet)</span></h2>
    <p class="pos">Zusätzlich zum Passwort ein 6-stelliger Code aus einer App (Google Authenticator, Microsoft Authenticator o. ä.).
      Erst einrichten (QR-Code scannen), dann einschalten, wann ihr wollt. Solange ausgeschaltet, ändert sich bei der Anmeldung nichts.</p>
    <?php foreach ($adminKonten as $mail => $k): ?>
      <div class="zf-konto">
        <strong><?= h($mail) ?></strong>
        <?php if (!$k): ?><span class="pos"> – Konto existiert nicht.</span>
        <?php elseif (!empty($_SESSION['2fa_neu'][$mail])):
            $geheim = (string) $_SESSION['2fa_neu'][$mail];
            $uri = 'otpauth://totp/' . rawurlencode('TRIPLEX-Q:' . $mail) . '?secret=' . $geheim . '&issuer=' . rawurlencode('TRIPLEX-Q') . '&digits=6&period=30'; ?>
          <p class="pos">1. App öffnen → „Konto hinzufügen“ → QR-Code scannen:</p>
          <div class="zf-qr" data-qr="<?= h($uri) ?>"></div>
          <p class="pos">Oder Schlüssel von Hand eingeben: <code><?= h(trim(chunk_split($geheim, 4, ' '))) ?></code></p>
          <form method="post" class="werkzeug"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="2fa_speichern">
            <input type="hidden" name="email" value="<?= h($mail) ?>">
            <label class="pos">2. Code aus der App: <input name="code" inputmode="numeric" maxlength="7" size="8" required autocomplete="one-time-code"></label>
            <button type="submit">Prüfen und speichern</button></form>
        <?php elseif (admin_2fa_aktiv($k)): ?>
          <span class="marke gruen">eingeschaltet</span>
          <form method="post" class="werkzeug" onsubmit="return confirm('Zwei-Faktor-Anmeldung für <?= h($mail) ?> ausschalten?')"><input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="tun" value="2fa_aus"><input type="hidden" name="email" value="<?= h($mail) ?>">
            <button type="submit" class="hell">Ausschalten</button></form>
        <?php elseif (!empty($k['totp_geheimnis'])): ?>
          <span class="marke gelb">eingerichtet – aus</span>
          <form method="post" class="werkzeug"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="2fa_an">
            <input type="hidden" name="email" value="<?= h($mail) ?>">
            <label class="pos">Code aus der App: <input name="code" inputmode="numeric" maxlength="7" size="8" required autocomplete="one-time-code"></label>
            <button type="submit">Einschalten</button></form>
          <form method="post" class="werkzeug"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="2fa_loeschen">
            <input type="hidden" name="email" value="<?= h($mail) ?>"><button type="submit" class="hell">Einrichtung löschen</button></form>
        <?php else: ?>
          <span class="marke">nicht eingerichtet</span>
          <form method="post" class="werkzeug"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="tun" value="2fa_start">
            <input type="hidden" name="email" value="<?= h($mail) ?>"><button type="submit">Einrichten</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script src="js/qrcode.js"></script>
<script src="js/waechter.js"></script>

<div class="karte">
  <h2>Sicherheits-Bausteine</h2>
  <p class="pos">Was eingebaut ist und was noch bei Strato zu tun ist – der Stand wird bei jedem Aufruf neu geprüft.</p>
  <table class="kompakt">
    <tr><th>Baustein</th><th>Stand</th><th>Was es macht</th></tr>
    <?php foreach ($vorschlaege as [$titel, $nutzen, $farbe, $text]): ?>
      <tr><td><strong><?= h($titel) ?></strong></td><td><span class="marke <?= $farbe ?>"><?= h($nutzen) ?></span></td><td><?= h($text) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
