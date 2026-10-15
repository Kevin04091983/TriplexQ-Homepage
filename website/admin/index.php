<?php
/**
 * TRIPLEX-Q – Verwaltung: Bestellungen und Kunden.
 * Anmeldung mit den Daten aus api/einstellungen.php (ADMIN_BENUTZER / ADMIN_HASH).
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/db.php';
sitzung_start();

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Aktuelle Preise, gefiltert nach Suchbegriff (Artikelnummer, Bezeichnung, Ausführung) */
function preise_gefiltert(string $q): array
{
    $alle = preise_aktuell();
    $q = mb_strtolower(trim($q));
    if ($q === '') {
        return $alle;
    }
    $worte = preg_split('/\s+/', $q);
    return array_filter($alle, static function ($z, $sku) use ($worte) {
        $text = mb_strtolower($sku . ' ' . $z[1] . ' ' . $z[2]);
        foreach ($worte as $w) {
            if (!str_contains($text, $w)) { return false; }
        }
        return true;
    }, ARRAY_FILTER_USE_BOTH);
}

$fehler = '';
if (($_POST['tun'] ?? '') === 'anmelden') {
    $kennung = 'admin|' . ($_SERVER['REMOTE_ADDR'] ?? '?');
    if (gesperrt_wegen_versuchen($kennung)) {
        $fehler = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
    } elseif (hash_equals(ADMIN_BENUTZER, (string) ($_POST['benutzer'] ?? '')) && password_verify((string) ($_POST['passwort'] ?? ''), ADMIN_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
        header('Location: ?');
        exit;
    } else {
        versuch_merken($kennung);
        usleep(400000);
        $fehler = 'Benutzername oder Passwort stimmt nicht.';
    }
}
if (isset($_GET['abmelden'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ?');
    exit;
}
$angemeldet = !empty($_SESSION['admin']);

/* ---------------------------------------------------------------- Änderungen */
$hinweis = '';
if ($angemeldet && $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf_admin'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    try {
        if (($_POST['tun'] ?? '') === 'kunde') {
            $gruppe = (int) ($_POST['preisgruppe'] ?? 0);
            db()->prepare('UPDATE kunden SET rabatt_prozent = ?, gesperrt = ?, notiz = ?, preisgruppe_id = ? WHERE id = ?')
                ->execute([max(0, min(90, (float) str_replace(',', '.', (string) $_POST['rabatt']))),
                    empty($_POST['gesperrt']) ? 0 : 1, mehrzeilig($_POST['notiz'] ?? '', 1000), $gruppe ?: null, (int) $_POST['id']]);
            $hinweis = 'Kunde gespeichert.';
        } elseif (($_POST['tun'] ?? '') === 'sammel_gruppe') {
            $ids = array_map('intval', is_array($_POST['auswahl'] ?? null) ? $_POST['auswahl'] : []);
            $gruppe = (int) ($_POST['preisgruppe'] ?? 0);
            if (!$ids) {
                throw new InvalidArgumentException('Bitte zuerst Kunden anhaken.');
            }
            $platz = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE kunden SET preisgruppe_id = ? WHERE id IN ($platz)")->execute([$gruppe ?: null, ...$ids]);
            $hinweis = count($ids) . ' Kunde(n) ' . ($gruppe ? 'der Kundengruppe zugeordnet.' : 'aus der Kundengruppe genommen.');
        } elseif (($_POST['tun'] ?? '') === 'preise') {
            $n = preise_speichern(is_array($_POST['preis'] ?? null) ? $_POST['preis'] : [], (string) ($_SESSION['admin_name'] ?? ADMIN_BENUTZER));
            $hinweis = count($n) . ' Preis(e) gespeichert.';
        } elseif (($_POST['tun'] ?? '') === 'prozent') {
            $faktor = 1 + ((float) str_replace(',', '.', (string) ($_POST['prozent'] ?? '0'))) / 100;
            if ($faktor <= 0.5 || $faktor >= 2) {
                throw new InvalidArgumentException('Bitte eine Änderung zwischen -50 % und +100 % angeben.');
            }
            $neu = [];
            foreach (preise_gefiltert((string) ($_POST['q'] ?? '')) as $sku => $z) {
                if ($z[0] !== null) {
                    $neu[$sku] = number_format(round($z[0] * $faktor, 2), 2, '.', '');
                }
            }
            $n = preise_speichern($neu, (string) ($_SESSION['admin_name'] ?? ADMIN_BENUTZER));
            $hinweis = count($n) . ' Preis(e) um ' . h((string) $_POST['prozent']) . ' % geändert.';
        } elseif (($_POST['tun'] ?? '') === 'import' && is_uploaded_file($_FILES['datei']['tmp_name'] ?? '')) {
            $neu = [];
            $inhalt = (string) file_get_contents($_FILES['datei']['tmp_name']);
            $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt);
            if (!mb_check_encoding($inhalt, 'UTF-8')) {
                $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');   // Excel speichert oft so
            }
            $zeilen = preg_split('/\r\n|\r|\n/', $inhalt);
            $trenner = substr_count($zeilen[0] ?? '', ';') >= substr_count($zeilen[0] ?? '', ',') ? ';' : ',';
            $kopf = str_getcsv($zeilen[0] ?? '', $trenner, '"', '');
            $spSku = 0;
            $spPreis = count($kopf) - 1;
            foreach ($kopf as $i => $t) {
                $t = strtolower(trim($t));
                if (in_array($t, ['artikelnummer', 'artikel-nr', 'artikel-nr.', 'sku', 'nummer'], true)) { $spSku = $i; }
                if (in_array($t, ['preis', 'neuer preis', 'preis netto'], true)) { $spPreis = $i; }
            }
            $basis = preise_basis();
            foreach ($zeilen as $nr => $zeile) {
                if (trim($zeile) === '') { continue; }
                $f = str_getcsv($zeile, $trenner, '"', '');
                $sku = trim($f[$spSku] ?? '');
                if ($nr === 0 && !isset($basis[$sku])) { continue; }      // Kopfzeile
                if (!isset($basis[$sku])) {
                    throw new InvalidArgumentException('Zeile ' . ($nr + 1) . ": unbekannte Artikelnummer $sku");
                }
                $neu[$sku] = trim($f[$spPreis] ?? '');
            }
            $n = preise_speichern($neu, (string) ($_SESSION['admin_name'] ?? ADMIN_BENUTZER));
            $hinweis = count($neu) . ' Zeilen gelesen, ' . count($n) . ' Preis(e) geändert.';
        } elseif (($_POST['tun'] ?? '') === 'status') {
            db()->prepare('UPDATE bestellungen SET status = ? WHERE id = ?')
                ->execute([text($_POST['status'] ?? 'neu', 20), (int) $_POST['id']]);
            $hinweis = 'Status gespeichert.';
        }
    } catch (InvalidArgumentException $e) {
        $fehler = $e->getMessage() . ' – es wurde nichts gespeichert.';
    } catch (Throwable $e) {
        $fehler = 'Speichern fehlgeschlagen: ' . $e->getMessage();
    }
}

/* ---------------------------------------------------------------- CSV-Export */
if ($angemeldet && ($_GET['export'] ?? '') !== '') {
    if ($_GET['export'] === 'preise') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="triplexq-preise-' . date('Y-m-d') . '.csv"');
        $aus = fopen('php://output', 'w');
        fwrite($aus, "\xEF\xBB\xBF");
        fputcsv($aus, ['Artikelnummer', 'Bezeichnung', 'Ausfuehrung', 'Listenpreis', 'Preis'], ';', '"', '');
        $basis = preise_basis();
        foreach (preise_aktuell() as $sku => $z) {
            fputcsv($aus, [$sku, $z[1], $z[2], $basis[$sku][0] === null ? '' : number_format((float) $basis[$sku][0], 2, ',', ''),
                $z[0] === null ? '' : number_format((float) $z[0], 2, ',', '')], ';', '"', '');
        }
        exit;
    }
    $was = $_GET['export'] === 'kunden' ? 'kunden' : 'bestellungen';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="triplexq-' . $was . '-' . date('Y-m-d') . '.csv"');
    $aus = fopen('php://output', 'w');
    fwrite($aus, "\xEF\xBB\xBF");
    if ($was === 'kunden') {
        fputcsv($aus, ['Nr', 'E-Mail', 'Firma', 'Vorname', 'Nachname', 'Strasse', 'PLZ', 'Ort', 'Land', 'Telefon', 'USt-IdNr', 'Rabatt %', 'Bestaetigt', 'Erstellt'], ';', '"', '');
        foreach (db()->query('SELECT * FROM kunden ORDER BY id') as $k) {
            fputcsv($aus, [$k['id'], $k['email'], $k['firma'], $k['vorname'], $k['nachname'], $k['strasse'], $k['plz'], $k['ort'],
                $k['land'], $k['telefon'], $k['ustid'], $k['rabatt_prozent'], $k['email_bestaetigt'] ? 'ja' : 'nein', $k['erstellt']], ';', '"', '');
        }
    } else {
        fputcsv($aus, ['Nummer', 'Datum', 'Kunde', 'E-Mail', 'Artikel-Nr', 'Bezeichnung', 'Ausfuehrung', 'Menge', 'Einzelpreis', 'Gesamtpreis', 'Status'], ';', '"', '');
        $sql = 'SELECT b.nummer, b.erstellt, b.email, b.anschrift, b.status, p.sku, p.bezeichnung, p.auswahl, p.menge, p.einzelpreis, p.gesamtpreis
                FROM bestellungen b JOIN positionen p ON p.bestellung_id = b.id ORDER BY b.erstellt DESC, b.id DESC, p.id';
        foreach (db()->query($sql) as $z) {
            fputcsv($aus, [$z['nummer'], $z['erstellt'], str_replace("\n", ' / ', $z['anschrift']), $z['email'], $z['sku'],
                $z['bezeichnung'], $z['auswahl'], $z['menge'], $z['einzelpreis'], $z['gesamtpreis'], $z['status']], ';', '"', '');
        }
    }
    exit;
}

$seite = $_GET['seite'] ?? 'bestellungen';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Verwaltung | TRIPLEX-Q</title>
<link rel="icon" href="../assets/img/logo/favicon-32.png">
<style>
  :root { --blau:#003b9f; --navy:#06163f; --rot:#e40001; --grau:#f4f7fc; --linie:#dde4f0; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.5 system-ui,Segoe UI,sans-serif; background:var(--grau); color:#0c1830; }
  header { background:var(--navy); color:#fff; padding:14px 22px; display:flex; align-items:center; gap:22px; flex-wrap:wrap; }
  header b { font-size:1.15rem; letter-spacing:.04em; }
  header a { color:#c9d5f2; text-decoration:none; padding:6px 12px; border-radius:99px; }
  header a:hover, header a.aktiv { background:rgba(255,255,255,.14); color:#fff; }
  header .rechts { margin-left:auto; }
  main { max-width:1200px; margin:26px auto; padding:0 18px; }
  h1 { font-size:1.6rem; margin:0 0 18px; }
  table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--linie); border-radius:12px; overflow:hidden; }
  th, td { padding:10px 12px; border-bottom:1px solid var(--linie); text-align:left; vertical-align:top; font-size:.93rem; }
  th { background:var(--navy); color:#fff; font-weight:600; }
  tr:last-child td { border-bottom:0; }
  .karte { background:#fff; border:1px solid var(--linie); border-radius:12px; padding:18px; margin-bottom:18px; }
  input, select, textarea { font:inherit; padding:8px 10px; border:1.5px solid var(--linie); border-radius:8px; background:#fff; }
  button { font:inherit; font-weight:600; padding:9px 16px; border:0; border-radius:99px; background:var(--blau); color:#fff; cursor:pointer; }
  button.rot { background:var(--rot); }
  .hinweis { background:#e7f5ec; border:1px solid #bfe3cd; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  .fehler { background:#fdeaea; border:1px solid #f3bcbc; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  .anmelden { max-width:380px; margin:12vh auto; }
  .anmelden label { display:block; margin-bottom:12px; }
  .anmelden input { width:100%; }
  .pos { color:#4a5876; font-size:.88rem; }
  .r { text-align:right; white-space:nowrap; }
  .werkzeug { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .sammel { position:sticky; top:0; z-index:5; box-shadow:0 6px 18px rgba(6,22,63,.08); }
  .konditionen { display:grid; grid-template-columns:1fr 90px; gap:8px 10px; align-items:end; min-width:300px; }
  .konditionen label { display:grid; gap:3px; font-size:.78rem; font-weight:600; color:#4a5876; }
  .konditionen label.breit { grid-column:1 / -1; }
  .konditionen label.inline { display:flex; align-items:center; gap:6px; font-weight:500; }
  .konditionen select, .konditionen input[name] { width:100%; }
  .konditionen button { justify-self:end; }
  .marke { display:inline-block; font-size:.76rem; font-weight:700; padding:2px 8px; border-radius:99px; }
  .marke.rot { background:#fdeaea; color:#b50001; } .marke.gruen { background:#e7f5ec; color:#0f8a4f; }
</style>
</head>
<body>
<?php if (!$angemeldet): ?>
<main class="anmelden">
  <div class="karte">
    <h1>TRIPLEX-Q Verwaltung</h1>
    <?php if ($fehler): ?><p class="fehler"><?= h($fehler) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="tun" value="anmelden">
      <label>Benutzername<input name="benutzer" autocomplete="username" required autofocus></label>
      <label>Passwort<input name="passwort" type="password" autocomplete="current-password" required></label>
      <button type="submit">Anmelden</button>
    </form>
  </div>
</main>
<?php else: ?>
<header>
  <b>TRIPLEX-Q Verwaltung</b>
  <a href="?seite=bestellungen" class="<?= $seite === 'bestellungen' ? 'aktiv' : '' ?>">Bestellungen</a>
  <a href="?seite=kunden" class="<?= $seite === 'kunden' ? 'aktiv' : '' ?>">Kunden</a>
  <a href="?seite=preise" class="<?= $seite === 'preise' ? 'aktiv' : '' ?>">Preise</a>
  <a href="uebernahme.php">Übernahme alter Shop</a>
  <a href="../index.html" target="_blank">Zur Website</a>
  <span class="rechts"><a href="?abmelden=1">Abmelden</a></span>
</header>
<main>
<?php if ($hinweis): ?><p class="hinweis"><?= h($hinweis) ?></p><?php endif; ?>
<?php if ($fehler): ?><p class="fehler"><?= h($fehler) ?></p><?php endif; ?>
<?php
try {
    if ($seite === 'preise') {
        $q = (string) ($_GET['q'] ?? '');
        $treffer = preise_gefiltert($q);
        $basis = preise_basis();
        $anzeigen = array_slice($treffer, 0, 400, true);
        $protokoll = db()->query('SELECT sku, alt, neu, wer, zeit FROM preis_protokoll ORDER BY id DESC LIMIT 30')->fetchAll();
        $euroFeld = static fn($w) => $w === null ? '' : number_format((float) $w, 2, ',', '');
        ?>
        <h1>Preise <span class="pos">(<?= count($treffer) ?> von <?= count($basis) ?> Artikeln)</span>
          <a href="?export=preise" style="font-size:.9rem">als CSV herunterladen</a></h1>
        <div class="karte werkzeug">
          <form method="get" class="werkzeug">
            <input type="hidden" name="seite" value="preise">
            <input name="q" value="<?= h($q) ?>" placeholder="Suche: Artikelnummer, Name, Ø …" size="36" autofocus>
            <button type="submit">Suchen</button>
          </form>
          <form method="post" class="werkzeug" onsubmit="return confirm('Alle <?= count($treffer) ?> gefundenen Preise ändern?')">
            <input type="hidden" name="tun" value="prozent">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input name="prozent" size="6" placeholder="+3" inputmode="decimal" required> %
            <button type="submit" class="rot">Alle gefundenen ändern</button>
          </form>
          <form method="post" enctype="multipart/form-data" class="werkzeug">
            <input type="hidden" name="tun" value="import">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
            <input type="file" name="datei" accept=".csv,text/csv" required>
            <button type="submit">CSV einlesen</button>
          </form>
        </div>
        <p class="pos">Preise netto. Leeres Feld = „Preis auf Anfrage“. Rot umrandet = weicht vom Listenpreis ab.
          CSV-Import: Spalten „Artikelnummer“ und „Preis“, getrennt mit Semikolon – am einfachsten den Export bearbeiten und wieder einlesen.</p>
        <form method="post">
          <input type="hidden" name="tun" value="preise">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
          <table>
            <tr><th>Artikel-Nr.</th><th>Bezeichnung</th><th>Ausführung</th><th class="r">Listenpreis</th><th class="r">Preis netto</th></tr>
            <?php foreach ($anzeigen as $sku => $z): $anders = $z[0] !== $basis[$sku][0]; ?>
            <tr>
              <td><?= h((string) $sku) ?></td>
              <td><?= h($z[1]) ?></td>
              <td><?= h($z[2]) ?></td>
              <td class="r pos"><?= h($euroFeld($basis[$sku][0])) ?></td>
              <td class="r"><input name="preis[<?= h((string) $sku) ?>]" value="<?= h($euroFeld($z[0])) ?>" size="9" inputmode="decimal"
                   style="text-align:right<?= $anders ? ';border-color:#e40001;font-weight:600' : '' ?>"> €</td>
            </tr>
            <?php endforeach; ?>
          </table>
          <?php if (count($treffer) > count($anzeigen)): ?><p class="pos">Es werden die ersten 400 Treffer angezeigt – bitte die Suche eingrenzen.</p><?php endif; ?>
          <p><button type="submit">Preise speichern</button></p>
        </form>
        <h2 style="margin-top:28px">Letzte Änderungen</h2>
        <table>
          <tr><th>Zeit</th><th>Artikel-Nr.</th><th class="r">alt</th><th class="r">neu</th><th>von</th></tr>
          <?php foreach ($protokoll as $pz): ?>
          <tr><td><?= h(date('d.m.Y H:i', strtotime((string) $pz['zeit']))) ?></td><td><?= h($pz['sku']) ?></td>
              <td class="r"><?= $pz['alt'] === null ? 'auf Anfrage' : h(number_format((float) $pz['alt'], 2, ',', '.')) . ' €' ?></td>
              <td class="r"><?= $pz['neu'] === null ? 'auf Anfrage' : h(number_format((float) $pz['neu'], 2, ',', '.')) . ' €' ?></td>
              <td class="pos"><?= h($pz['wer']) ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php
    } elseif ($seite === 'kunden') {
        $gruppen = db()->query('SELECT g.id, g.name, (SELECT COUNT(*) FROM kunden k WHERE k.preisgruppe_id = g.id) AS anzahl FROM preisgruppen g ORDER BY g.name')->fetchAll();
        $filter = (string) ($_GET['gruppe'] ?? '');
        $suche = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
        $bedingung = $filter === 'ohne' ? 'WHERE k.preisgruppe_id IS NULL' : ($filter !== '' ? 'WHERE k.preisgruppe_id = ' . (int) $filter : '');
        $kunden = db()->query("SELECT k.*, (SELECT COUNT(*) FROM bestellungen b WHERE b.kunde_id = k.id) AS anzahl,
                                      (SELECT COALESCE(SUM(b.netto),0) FROM bestellungen b WHERE b.kunde_id = k.id) AS umsatz
                               FROM kunden k $bedingung ORDER BY k.firma, k.nachname")->fetchAll();
        if ($suche !== '') {
            $kunden = array_values(array_filter($kunden, static fn($k) => str_contains(mb_strtolower($k['email'] . ' ' . $k['firma'] . ' ' . $k['vorname'] . ' ' . $k['nachname'] . ' ' . $k['ort'] . ' ' . $k['altkunde_nr']), $suche)));
        }
        ?>
        <h1>Kunden (<?= count($kunden) ?>) <a href="?export=kunden" style="font-size:.9rem">als CSV herunterladen</a></h1>
        <form method="get" class="karte werkzeug">
          <input type="hidden" name="seite" value="kunden">
          <input name="q" value="<?= h($suche) ?>" placeholder="Suche: Firma, Name, E-Mail, Ort, Kundennr." size="34">
          <select name="gruppe">
            <option value="">Alle Kundengruppen</option>
            <option value="ohne" <?= $filter === 'ohne' ? 'selected' : '' ?>>ohne Kundengruppe</option>
            <?php foreach ($gruppen as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $filter === (string) $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?> (<?= (int) $g['anzahl'] ?>)</option><?php endforeach; ?>
          </select>
          <button type="submit">Filtern</button>
        </form>
        <form method="post" id="sammel" class="karte werkzeug sammel">
          <input type="hidden" name="tun" value="sammel_gruppe">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
          <b>Angehakte Kunden</b>
          <select name="preisgruppe" aria-label="Kundengruppe für angehakte Kunden">
            <option value="">– keine Gruppe –</option>
            <?php foreach ($gruppen as $g): ?><option value="<?= (int) $g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?>
          </select>
          <button type="submit">Kundengruppe zuordnen</button>
          <label class="pos"><input type="checkbox" onclick="document.querySelectorAll('input[form=sammel][name^=auswahl]').forEach(c => c.checked = this.checked)"> alle auf dieser Seite anhaken</label>
        </form>
        <table class="kundenliste">
          <tr><th></th><th>Kunde</th><th>Kontakt / Status</th><th class="r">Bestell.</th><th class="r">Umsatz netto</th><th>Konditionen</th></tr>
          <?php foreach ($kunden as $k): ?>
          <tr>
            <td><input type="checkbox" form="sammel" name="auswahl[]" value="<?= (int) $k['id'] ?>" aria-label="Kunde anhaken"></td>
            <td><strong><?= h($k['firma'] ?: $k['vorname'] . ' ' . $k['nachname']) ?></strong><br>
                <span class="pos"><?= h($k['vorname'] . ' ' . $k['nachname']) ?><br><?= h($k['strasse']) ?>, <?= h($k['plz'] . ' ' . $k['ort']) ?>
                <?= $k['land'] !== 'Deutschland' ? ' · ' . h($k['land']) : '' ?>
                <?= $k['ustid'] ? '<br>USt-IdNr. ' . h($k['ustid']) : '' ?>
                <?= $k['altkunde_nr'] !== '' ? '<br>Kd.-Nr. alter Shop: ' . h($k['altkunde_nr']) : '' ?></span></td>
            <td><a href="mailto:<?= h($k['email']) ?>"><?= h($k['email']) ?></a><br><span class="pos"><?= h($k['telefon']) ?><br>
                <?php if ($k['passwort_neu_noetig']): ?>
                  <span class="marke rot">aus altem Shop · Passwort noch offen</span><?= $k['eingeladen'] ? '<br>eingeladen am ' . h(date('d.m.Y', strtotime((string) $k['eingeladen']))) : '' ?>
                <?php elseif ($k['quelle'] === 'altshop'): ?>
                  <span class="marke gruen">aus altem Shop · aktiv</span>
                <?php else: ?>
                  <?= $k['email_bestaetigt'] ? 'E-Mail bestätigt' : 'E-Mail nicht bestätigt' ?>
                <?php endif; ?>
                <br>Konto seit <?= h(date('d.m.Y', strtotime((string) $k['erstellt']))) ?></span></td>
            <td class="r"><?= (int) $k['anzahl'] ?></td>
            <td class="r"><?= h(number_format((float) $k['umsatz'], 2, ',', '.')) ?> €</td>
            <td>
              <form method="post" class="konditionen">
                <input type="hidden" name="tun" value="kunde">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
                <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                <label>Kundengruppe
                  <select name="preisgruppe">
                    <option value="">– keine (Listenpreis) –</option>
                    <?php foreach ($gruppen as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $k['preisgruppe_id'] === (int) $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?>
                  </select></label>
                <label>Rabatt %
                  <input name="rabatt" value="<?= h(number_format((float) $k['rabatt_prozent'], 2, ',', '')) ?>" size="5" inputmode="decimal"></label>
                <label class="breit">Notiz (nur intern)
                  <input name="notiz" value="<?= h($k['notiz']) ?>" placeholder="z. B. Ansprechpartner Einkauf"></label>
                <label class="inline"><input type="checkbox" name="gesperrt" value="1" <?= $k['gesperrt'] ? 'checked' : '' ?>> Konto sperren</label>
                <button type="submit">Speichern</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php
    } else {
        $bestellungen = db()->query('SELECT * FROM bestellungen ORDER BY erstellt DESC, id DESC LIMIT 200')->fetchAll();
        $positionen = [];
        if ($bestellungen) {
            $ids = implode(',', array_map(static fn($b) => (int) $b['id'], $bestellungen));
            foreach (db()->query("SELECT * FROM positionen WHERE bestellung_id IN ($ids) ORDER BY id") as $p) {
                $positionen[(int) $p['bestellung_id']][] = $p;
            }
        }
        ?>
        <h1>Bestellungen (<?= count($bestellungen) ?>) <a href="?export=bestellungen" style="font-size:.9rem">als CSV herunterladen</a></h1>
        <table>
          <tr><th>Nummer / Datum</th><th>Kunde</th><th>Artikel</th><th class="r">Summe</th><th>Status</th></tr>
          <?php foreach ($bestellungen as $b): ?>
          <tr>
            <td><strong><?= h($b['nummer']) ?></strong><br><span class="pos"><?= h(date('d.m.Y H:i', strtotime((string) $b['erstellt']))) ?><br>
              <?= $b['versandart'] === 'abholung' ? 'Selbstabholung' : 'Versand' ?><?= $b['rabatt_prozent'] > 0 ? '<br>Rabatt ' . h(number_format((float) $b['rabatt_prozent'], 2, ',', '.')) . ' %' : '' ?></span></td>
            <td><?= nl2br(h($b['anschrift'])) ?><br><a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a>
              <?= $b['lieferadresse'] ? '<br><span class="pos"><strong>Lieferadresse:</strong><br>' . nl2br(h($b['lieferadresse'])) . '</span>' : '' ?>
              <?= $b['bemerkung'] ? '<br><span class="pos"><strong>Bemerkung:</strong> ' . h($b['bemerkung']) . '</span>' : '' ?></td>
            <td><?php foreach ($positionen[(int) $b['id']] ?? [] as $p): ?>
                  <?= (int) $p['menge'] ?> × <?= h($p['sku']) ?> – <?= h($p['bezeichnung']) ?><?= $p['auswahl'] ? ' (' . h($p['auswahl']) . ')' : '' ?>
                  <span class="pos">à <?= h(number_format((float) $p['einzelpreis'], 2, ',', '.')) ?> €</span><br>
                <?php endforeach; ?></td>
            <td class="r"><?= h(number_format((float) $b['netto'], 2, ',', '.')) ?> € netto<br>
              <span class="pos">+ <?= h(number_format((float) $b['versand'], 2, ',', '.')) ?> € Versand<br>
              <strong><?= h(number_format((float) $b['gesamt'], 2, ',', '.')) ?> € brutto</strong></span></td>
            <td>
              <form method="post" class="werkzeug">
                <input type="hidden" name="tun" value="status">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                <select name="status">
                  <?php foreach (['neu' => 'neu', 'bearbeitung' => 'in Bearbeitung', 'versendet' => 'versendet', 'abgeschlossen' => 'abgeschlossen', 'storniert' => 'storniert'] as $w => $t): ?>
                    <option value="<?= $w ?>" <?= $b['status'] === $w ? 'selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit">Setzen</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php
    }
} catch (Throwable $e) {
    echo '<p class="fehler">Datenbank nicht erreichbar. Bitte in <code>api/einstellungen.php</code> die Zugangsdaten eintragen und <code>api/schema.sql</code> einspielen.<br>' . h($e->getMessage()) . '</p>';
}
?>
</main>
<?php endif; ?>
</body>
</html>
