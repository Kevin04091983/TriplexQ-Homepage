<?php
/**
 * TRIPLEX-Q – Übernahme aus dem alten Shop: Kunden, alte Bestellungen, Kundenpreise; Einladungen verschicken.
 * Ablauf je Datei: CSV hochladen → Vorschau mit erkannter Spaltenzuordnung (änderbar) → übernehmen.
 * Mehrfaches Einlesen derselben Datei legt nichts doppelt an.
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/db.php';
sitzung_start();
if (empty($_SESSION['admin'])) {
    header('Location: ./');
    exit;
}
@set_time_limit(300);
require_once __DIR__ . '/uebernahme_funktionen.php';

/* ------------------------------------------------------------------ Anfragen verarbeiten */
$meldung = '';
$fehler = '';
$bericht = null;
$art = array_key_exists($_GET['art'] ?? '', TITEL) ? $_GET['art'] : 'kunden';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $fehler = 'Sitzung abgelaufen, bitte Seite neu laden.';
    } elseif (($_POST['tun'] ?? '') === 'hochladen' && is_uploaded_file($_FILES['datei']['tmp_name'] ?? '')) {
        $ziel = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tq-import-' . bin2hex(random_bytes(8)) . '.csv';
        move_uploaded_file($_FILES['datei']['tmp_name'], $ziel);
        $_SESSION['import'][$art] = ['pfad' => $ziel, 'name' => (string) $_FILES['datei']['name']];
        header('Location: ?art=' . $art);
        exit;
    } elseif (in_array($_POST['tun'] ?? '', ['pruefen', 'uebernehmen'], true) && !empty($_SESSION['import'][$art]['pfad'])) {
        [$kopf, $zeilen] = csv_lesen($_SESSION['import'][$art]['pfad']);
        $GLOBALS['import_pfad'] = $_SESSION['import'][$art]['pfad'];
        $map = [];
        foreach (FELDER[$art] ?? [] as $feld => $x) {
            $v = $_POST['map'][$feld] ?? '';
            $map[$feld] = $v === '' ? null : (int) $v;
        }
        $fehlt = array_filter(PFLICHT[$art], static fn($f) => $map[$f] === null);
        if ($fehlt) {
            $fehler = 'Bitte zuordnen: ' . implode(', ', array_map(static fn($f) => FELDER[$art][$f][0], $fehlt));
        } else {
            try {
                $bericht = uebernehmen($art, $zeilen, $map, $_POST['tun'] === 'pruefen');
                $meldung = $_POST['tun'] === 'pruefen'
                    ? 'Probelauf – es wurde noch nichts gespeichert.'
                    : TITEL[$art] . ' übernommen.';
                if ($_POST['tun'] === 'uebernehmen') {
                    @unlink($_SESSION['import'][$art]['pfad']);
                    unset($_SESSION['import'][$art]);
                }
            } catch (Throwable $e) {
                $fehler = 'Übernahme abgebrochen, es wurde nichts gespeichert: ' . $e->getMessage();
            }
        }
    } elseif (($_POST['tun'] ?? '') === 'verwerfen') {
        @unlink($_SESSION['import'][$art]['pfad'] ?? '');
        unset($_SESSION['import'][$art]);
    } elseif (($_POST['tun'] ?? '') === 'einladen') {
        $offen = db()->query('SELECT * FROM kunden WHERE passwort_neu_noetig = 1 AND eingeladen IS NULL AND gesperrt = 0 ORDER BY id LIMIT 50')->fetchAll();
        $n = 0;
        foreach ($offen as $k) {
            passwort_link_senden($k, true);
            db()->prepare('UPDATE kunden SET eingeladen = NOW() WHERE id = ?')->execute([$k['id']]);
            $n++;
        }
        $meldung = "$n Einladung(en) verschickt.";
    }
}

$zahlen = db()->query('SELECT
    SUM(passwort_neu_noetig = 1) AS offen,
    SUM(passwort_neu_noetig = 1 AND eingeladen IS NULL) AS nicht_eingeladen,
    SUM(quelle = "altshop") AS altkunden,
    SUM(passwort_neu_noetig = 0 AND quelle = "altshop") AS aktiv
  FROM kunden')->fetch();
$altBestellungen = (int) db()->query('SELECT COUNT(*) FROM bestellungen WHERE quelle = "altshop"')->fetchColumn();
$sonderpreise = (int) db()->query('SELECT COUNT(*) FROM kundenpreise')->fetchColumn();
$gruppenpreise = (int) db()->query('SELECT COUNT(*) FROM preisgruppen_preise')->fetchColumn();
$ohneGruppe = (int) db()->query('SELECT COUNT(*) FROM kunden WHERE preisgruppe_id IS NULL AND quelle = "altshop"')->fetchColumn();

$upload = $_SESSION['import'][$art] ?? null;
$kopf = $zeilen = [];
$map = [];
if ($upload && is_file($upload['pfad'])) {
    [$kopf, $zeilen] = csv_lesen($upload['pfad']);
    $map = zuordnen($art, $kopf);
    if (!empty($_POST['map'])) {
        foreach (FELDER[$art] ?? [] as $feld => $x) {
            $v = $_POST['map'][$feld] ?? '';
            $map[$feld] = $v === '' ? null : (int) $v;
        }
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Übernahme alter Shop | TRIPLEX-Q</title>
<link rel="icon" href="../assets/img/logo/favicon-32.png">
<style>
  :root { --blau:#003b9f; --navy:#06163f; --rot:#e40001; --grau:#f4f7fc; --linie:#dde4f0; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.5 system-ui,Segoe UI,sans-serif; background:var(--grau); color:#0c1830; }
  header { background:var(--navy); color:#fff; padding:14px 22px; display:flex; align-items:center; gap:22px; flex-wrap:wrap; }
  header b { font-size:1.15rem; }
  header a { color:#c9d5f2; text-decoration:none; padding:6px 12px; border-radius:99px; }
  header a:hover, header a.aktiv { background:rgba(255,255,255,.14); color:#fff; }
  main { max-width:1200px; margin:26px auto; padding:0 18px; }
  h1 { font-size:1.6rem; margin:0 0 6px; }
  .karte { background:#fff; border:1px solid var(--linie); border-radius:12px; padding:18px 20px; margin-bottom:18px; }
  .schritte { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; }
  .schritte a { padding:9px 16px; border-radius:99px; border:1.5px solid var(--linie); background:#fff; text-decoration:none; color:#0c1830; font-weight:600; }
  .schritte a.aktiv { background:var(--navy); color:#fff; border-color:var(--navy); }
  .zahlen { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
  .zahlen div { background:var(--grau); border-radius:10px; padding:12px 14px; }
  .zahlen b { display:block; font-size:1.6rem; }
  table { width:100%; border-collapse:collapse; background:#fff; font-size:.9rem; }
  th, td { padding:8px 10px; border-bottom:1px solid var(--linie); text-align:left; vertical-align:top; }
  th { background:var(--navy); color:#fff; font-weight:600; }
  .rahmen { overflow:auto; border:1px solid var(--linie); border-radius:10px; max-height:420px; }
  select, input[type=file] { font:inherit; padding:7px 9px; border:1.5px solid var(--linie); border-radius:8px; background:#fff; }
  button { font:inherit; font-weight:600; padding:10px 18px; border:0; border-radius:99px; background:var(--blau); color:#fff; cursor:pointer; }
  button.rot { background:var(--rot); } button.grau { background:#7b879e; }
  .ok { background:#e7f5ec; border:1px solid #bfe3cd; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  .fehler { background:#fdeaea; border:1px solid #f3bcbc; padding:10px 14px; border-radius:10px; margin-bottom:16px; }
  .pos { color:#4a5876; font-size:.88rem; }
  .zuordnung { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px 18px; margin:12px 0 16px; }
  .zuordnung label { display:grid; gap:4px; font-size:.88rem; font-weight:600; }
  .zuordnung .pflicht { color:var(--rot); }
  .knoepfe { display:flex; gap:10px; flex-wrap:wrap; }
</style>
</head>
<body>
<header>
  <b>TRIPLEX-Q Verwaltung</b>
  <a href="./?seite=bestellungen">Bestellungen</a>
  <a href="./?seite=kunden">Kunden</a>
  <a href="./?seite=preise">Preise</a>
  <a href="uebernahme.php" class="aktiv">Übernahme alter Shop</a>
  <a href="../index.html" target="_blank">Zur Website</a>
</header>
<main>
  <h1>Übernahme aus dem alten Shop</h1>
  <p class="pos">Reihenfolge: 1. Kunden · 2. Kundengruppen-Preise · 3. Kundengruppen zuordnen (Verwaltung → Kunden) · 4. alte Bestellungen · 5. Einladungen verschicken.
    Jede Datei wird erst geprüft (Probelauf), dann übernommen. Doppeltes Einlesen legt nichts doppelt an; vorhandene Konten werden nur ergänzt, Passwörter nie überschrieben.</p>

  <?php if ($meldung): ?><p class="ok"><?= h($meldung) ?></p><?php endif; ?>
  <?php if ($fehler): ?><p class="fehler"><?= h($fehler) ?></p><?php endif; ?>

  <div class="karte zahlen">
    <div><b><?= (int) $zahlen['altkunden'] ?></b>übernommene Kunden</div>
    <div><b><?= (int) $zahlen['aktiv'] ?></b>davon mit neuem Passwort</div>
    <div><b><?= (int) $zahlen['offen'] ?></b>Passwort noch offen</div>
    <div><b><?= $altBestellungen ?></b>alte Bestellungen</div>
    <div><b><?= $gruppenpreise ?></b>Kundengruppen-Preise</div>
    <div><b><?= $ohneGruppe ?></b>Altkunden ohne Kundengruppe</div>
    <div><b><?= $sonderpreise ?></b>einzelne Kundenpreise</div>
  </div>

  <?php if ($bericht): ?>
  <div class="karte">
    <b><?= h($meldung) ?></b>
    <p><?= $bericht['neu'] ?> neu · <?= $bericht['aktualisiert'] ?> ergänzt · <?= $bericht['uebersprungen'] ?> übersprungen</p>
    <?php if ($bericht['hinweise']): ?><ul class="pos"><?php foreach ($bericht['hinweise'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="schritte">
    <?php foreach (TITEL as $a => $t): ?><a href="?art=<?= $a ?>" class="<?= $a === $art ? 'aktiv' : '' ?>"><?= h($t) ?></a><?php endforeach; ?>
  </nav>

  <div class="karte">
    <?php if (!$upload): ?>
      <form method="post" enctype="multipart/form-data" class="knoepfe" action="?art=<?= $art ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="tun" value="hochladen">
        <input type="file" name="datei" accept=".csv,text/csv,.txt" required>
        <button type="submit">CSV-Datei „<?= h(TITEL[$art]) ?>“ hochladen</button>
      </form>
      <p class="pos">Export aus dem alten Shop-Admin als CSV. Spalten werden automatisch erkannt und können vor der Übernahme angepasst werden.</p>
    <?php else: ?>
      <p><b><?= h($upload['name']) ?></b> – <?= count($zeilen) ?> Datenzeilen<?= $art !== 'gruppenpreise' ? ', ' . count($kopf) . ' Spalten' : '' ?></p>
      <form method="post" action="?art=<?= $art ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
        <?php if ($art === 'gruppenpreise'): [$gp, $gpHinweise] = gruppenpreise_lesen($upload['pfad']); ?>
        <p>Erkannte Kundengruppen:</p>
        <ul><?php foreach ($gp as $g => $pr): ?><li><b><?= h($g) ?></b>: <?= count($pr) ?> Artikelpreise</li><?php endforeach; ?></ul>
        <?php if ($gpHinweise): ?><p class="pos"><?= count($gpHinweise) ?> Hinweise, z. B.: <?= h(implode(' · ', array_slice($gpHinweise, 0, 4))) ?></p><?php endif; ?>
        <?php else: ?>
        <div class="zuordnung">
          <?php foreach (FELDER[$art] as $feld => [$titel]): ?>
          <label><?= h($titel) ?><?= in_array($feld, PFLICHT[$art], true) ? ' <span class="pflicht">*</span>' : '' ?>
            <select name="map[<?= $feld ?>]">
              <option value="">– nicht vorhanden –</option>
              <?php foreach ($kopf as $i => $k): ?><option value="<?= $i ?>" <?= $map[$feld] === $i ? 'selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?>
            </select>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="pos">Vorschau der ersten Zeilen mit dieser Zuordnung:</p>
        <div class="rahmen"><table>
          <tr><?php foreach (FELDER[$art] as $feld => [$titel]): if ($map[$feld] === null) continue; ?><th><?= h($titel) ?></th><?php endforeach; ?></tr>
          <?php foreach (array_slice($zeilen, 0, 8) as $z): ?>
          <tr><?php foreach (FELDER[$art] as $feld => $x): if ($map[$feld] === null) continue; ?><td><?= h(wert($z, $map[$feld])) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
        <p class="knoepfe" style="margin-top:16px">
          <button type="submit" name="tun" value="pruefen">Probelauf (nichts speichern)</button>
          <button type="submit" name="tun" value="uebernehmen" class="rot" onclick="return confirm('<?= h(TITEL[$art]) ?> jetzt übernehmen?')">Übernehmen</button>
          <button type="submit" name="tun" value="verwerfen" class="grau" formnovalidate>Andere Datei wählen</button>
        </p>
      </form>
    <?php endif; ?>
  </div>

  <div class="karte">
    <h2 style="margin-top:0">Einladungen verschicken</h2>
    <p>Übernommene Kunden bekommen eine E-Mail „Unser neuer TRIPLEX-Q Shop – bitte Passwort festlegen“ mit persönlichem Link (14 Tage gültig).
      Wer die Mail nicht nutzt, bekommt den Link automatisch, sobald er sich anmelden will.</p>
    <p><b><?= (int) $zahlen['nicht_eingeladen'] ?></b> Kunden noch nicht eingeladen. Versand in Paketen zu je 50 (Schutz vor Mail-Sperren).</p>
    <form method="post" onsubmit="return confirm('Jetzt bis zu 50 Einladungen verschicken?')">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_admin']) ?>">
      <input type="hidden" name="tun" value="einladen">
      <button type="submit" class="rot" <?= (int) $zahlen['nicht_eingeladen'] === 0 ? 'disabled' : '' ?>>Nächste 50 Einladungen senden</button>
    </form>
  </div>
</main>
</body>
</html>
