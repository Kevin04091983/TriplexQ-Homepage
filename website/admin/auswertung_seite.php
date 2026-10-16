<?php
/**
 * TRIPLEX-Q Verwaltung – Auswertung (nur Admin; wird aus admin/index.php eingebunden, nicht direkt aufrufbar).
 * Umsatz, Top-Kunden, Bestseller, Produktlinien, schlafende Kunden, Länder, Umstieg auf den neuen Shop.
 * Grundlage: alle Bestellungen außer stornierten (neuer Shop + übernommene aus dem alten Shop), Beträge netto.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || empty($_SESSION['admin'])) {
    http_response_code(404);
    exit;
}

$euro = static fn($w) => number_format((float) $w, 2, ',', '.') . ' €';
$ganz = static fn($w) => number_format((float) $w, 0, ',', '.');
$tag = static fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '–';
$keineWare = ['RABATT', 'GUTSCHEIN', 'ZAHLUNG'];
// "Schaftform: HB, Schneidendurchmesser 4,00-20,00mm: 12,00" -> "HB · Ø 12,00"
$kurz = static fn(?string $a) => trim(preg_replace(['/Schneidendurchmesser[^:]*:\s*(Ø\s*)?/u', '/Schaftform:\s*/u', '/Beschichtung:\s*/u', '/,\s+/u'],
    ['Ø ', '', '', ' · '], (string) $a));
$ohneWare = "p.sku NOT IN ('" . implode("','", $keineWare) . "')";

/* ---------------- Zeitraum */
$heute = new DateTimeImmutable('today');
$jahr = (int) $heute->format('Y');
$zeitraeume = [
    '12m' => ['Letzte 12 Monate', $heute->modify('first day of this month')->modify('-11 months'), $heute->modify('+1 day')],
    'jahr' => ['Dieses Jahr (' . $jahr . ')', new DateTimeImmutable("$jahr-01-01"), new DateTimeImmutable(($jahr + 1) . '-01-01')],
    'vorjahr' => ['Vorjahr (' . ($jahr - 1) . ')', new DateTimeImmutable(($jahr - 1) . '-01-01'), new DateTimeImmutable("$jahr-01-01")],
    '30t' => ['Letzte 30 Tage', $heute->modify('-29 days'), $heute->modify('+1 day')],
    'alles' => ['Gesamter Zeitraum', new DateTimeImmutable('2000-01-01'), $heute->modify('+1 day')],
];
$wahl = array_key_exists($_GET['zeitraum'] ?? '', $zeitraeume) ? $_GET['zeitraum'] : '12m';
[$titel, $von, $bis] = $zeitraeume[$wahl];
// gleich lange Vorperiode zum Vergleichen
$tage = (int) $von->diff($bis)->days;
$vonVor = $von->modify("-$tage days");
$v = [$von->format('Y-m-d H:i:s'), $bis->format('Y-m-d H:i:s')];
$vVor = [$vonVor->format('Y-m-d H:i:s'), $von->format('Y-m-d H:i:s')];
$basisBed = "b.status <> 'storniert' AND b.erstellt >= ? AND b.erstellt < ?";

$abfrage = static function (string $sql, array $werte = []) {
    $s = db()->prepare($sql);
    $s->execute($werte);
    return $s;
};

/* ---------------- Kennzahlen */
$kennzahlen = static function (array $werte) use ($abfrage, $basisBed, $ohneWare): array {
    $k = $abfrage("SELECT COUNT(*) n, COALESCE(SUM(b.netto),0) umsatz, COUNT(DISTINCT COALESCE(b.kunde_id, b.email)) kunden
                   FROM bestellungen b WHERE $basisBed", $werte)->fetch();
    $k['stueck'] = (int) $abfrage("SELECT COALESCE(SUM(p.menge),0) FROM positionen p JOIN bestellungen b ON b.id = p.bestellung_id
                                   WHERE $basisBed AND $ohneWare", $werte)->fetchColumn();
    $k['neu'] = (int) $abfrage("SELECT COUNT(*) FROM (SELECT MIN(b.erstellt) erste FROM bestellungen b WHERE b.status <> 'storniert'
                                GROUP BY COALESCE(b.kunde_id, b.email)) x WHERE x.erste >= ? AND x.erste < ?", $werte)->fetchColumn();
    $k['schnitt'] = $k['n'] ? $k['umsatz'] / $k['n'] : 0;
    return $k;
};
$jetzt = $kennzahlen($v);
$vorher = $wahl === 'alles' ? null : $kennzahlen($vVor);
$trend = static function ($neu, $alt): string {
    if ($alt === null) { return ''; }
    if ((float) $alt == 0.0) { return (float) $neu > 0 ? '<span class="trend hoch">neu</span>' : ''; }
    $p = ((float) $neu - (float) $alt) / (float) $alt * 100;
    return '<span class="trend ' . ($p >= 0 ? 'hoch' : 'runter') . '">' . ($p >= 0 ? '▲ +' : '▼ ') . number_format($p, 0, ',', '.') . ' %</span>';
};

/* ---------------- Umsatz je Monat (letzte 24 Monate) und je Jahr */
$monate = [];
$start = $heute->modify('first day of this month')->modify('-23 months');
for ($i = 0; $i < 24; $i++) {
    $monate[$start->modify("+$i months")->format('Y-m')] = ['umsatz' => 0.0, 'n' => 0];
}
foreach ($abfrage("SELECT DATE_FORMAT(b.erstellt, '%Y-%m') m, SUM(b.netto) umsatz, COUNT(*) n FROM bestellungen b
                   WHERE b.status <> 'storniert' AND b.erstellt >= ? GROUP BY m", [$start->format('Y-m-d')]) as $r) {
    if (isset($monate[$r['m']])) { $monate[$r['m']] = ['umsatz' => (float) $r['umsatz'], 'n' => (int) $r['n']]; }
}
$maxMonat = max(1.0, ...array_values(array_column($monate, 'umsatz')));
$jahre = $abfrage("SELECT YEAR(b.erstellt) j, SUM(b.netto) umsatz, COUNT(*) n, COUNT(DISTINCT COALESCE(b.kunde_id, b.email)) kunden
                   FROM bestellungen b WHERE b.status <> 'storniert' GROUP BY j ORDER BY j DESC")->fetchAll();
$monatsnamen = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

/* ---------------- Top-Kunden */
$kundenName = static function (array $r): string {
    $name = trim((string) ($r['firma'] ?? ''));
    $person = trim(($r['vorname'] ?? '') . ' ' . ($r['nachname'] ?? ''));
    if ($name === '' && $person === '') { return strtok((string) ($r['anschrift'] ?? $r['email']), "\n") ?: (string) $r['email']; }
    return $name !== '' ? $name . ($person !== '' ? ' · ' . $person : '') : $person;
};
$topKunden = $abfrage("SELECT COALESCE(b.kunde_id, b.email) schluessel, MAX(b.kunde_id) kunde_id, MAX(b.email) email, MAX(b.anschrift) anschrift,
                              COUNT(*) n, SUM(b.netto) umsatz, MAX(b.erstellt) letzte, MIN(b.erstellt) erste,
                              MAX(k.firma) firma, MAX(k.vorname) vorname, MAX(k.nachname) nachname, MAX(k.ort) ort
                       FROM bestellungen b LEFT JOIN kunden k ON k.id = b.kunde_id
                       WHERE $basisBed GROUP BY schluessel ORDER BY umsatz DESC LIMIT 25", $v)->fetchAll();
// "Kunde seit" = allererste Bestellung überhaupt (nicht nur im gewählten Zeitraum)
foreach ($topKunden as &$tk) {
    $tk['erste'] = $tk['kunde_id']
        ? $abfrage("SELECT MIN(erstellt) FROM bestellungen WHERE kunde_id = ? AND status <> 'storniert'", [$tk['kunde_id']])->fetchColumn()
        : $abfrage("SELECT MIN(erstellt) FROM bestellungen WHERE kunde_id IS NULL AND email = ? AND status <> 'storniert'", [$tk['email']])->fetchColumn();
}
unset($tk);

/* ---------------- Bestseller (Artikel/Abmessung) */
$sort = ($_GET['sort'] ?? '') === 'stueck' ? 'stueck' : 'umsatz';
$artikel = $abfrage("SELECT p.sku, MAX(p.bezeichnung) bez, MAX(p.auswahl) auswahl, SUM(p.menge) stueck, SUM(p.gesamtpreis) umsatz,
                            COUNT(DISTINCT b.id) bestellungen, COUNT(DISTINCT COALESCE(b.kunde_id, b.email)) kunden
                     FROM positionen p JOIN bestellungen b ON b.id = p.bestellung_id
                     WHERE $basisBed AND $ohneWare GROUP BY p.sku ORDER BY $sort DESC", $v)->fetchAll();
$warenUmsatz = array_sum(array_column($artikel, 'umsatz')) ?: 1;

/* ---------------- Produktlinien: Abmessungen zum Produkt zusammenfassen */
$basis = preise_basis();
$produkte = [];
foreach ($artikel as $a) {
    $id = $basis[$a['sku']][3] ?? null;
    $name = $basis[$a['sku']][1] ?? preg_replace('/\s+Ø.*$/u', '', (string) $a['bez']);
    $schluessel = $id ?: 'x:' . $name;
    $produkte[$schluessel] ??= ['name' => $name, 'id' => $id, 'stueck' => 0, 'umsatz' => 0.0, 'abmessungen' => 0, 'top' => null];
    $produkte[$schluessel]['stueck'] += (int) $a['stueck'];
    $produkte[$schluessel]['umsatz'] += (float) $a['umsatz'];
    $produkte[$schluessel]['abmessungen']++;
    if (!$produkte[$schluessel]['top'] || (int) $a['stueck'] > $produkte[$schluessel]['top'][1]) {
        $produkte[$schluessel]['top'] = [$a['auswahl'] ?: $a['sku'], (int) $a['stueck']];
    }
}
uasort($produkte, static fn($a, $b) => $b[$sort] <=> $a[$sort]);
$maxProdukt = max(1.0, ...array_values(array_map(static fn($p) => (float) $p[$sort], $produkte ?: [["umsatz" => 1, "stueck" => 1]])));

/* ---------------- Schlafende Kunden (früher gut, seit 6 Monaten nichts) */
$schlafend = $abfrage("SELECT COALESCE(b.kunde_id, b.email) schluessel, MAX(b.kunde_id) kunde_id, MAX(b.email) email, MAX(b.anschrift) anschrift,
                              COUNT(*) n, SUM(b.netto) umsatz, MAX(b.erstellt) letzte,
                              MAX(k.firma) firma, MAX(k.vorname) vorname, MAX(k.nachname) nachname, MAX(k.telefon) telefon
                       FROM bestellungen b LEFT JOIN kunden k ON k.id = b.kunde_id
                       WHERE b.status <> 'storniert' GROUP BY schluessel
                       HAVING MAX(b.erstellt) < (NOW() - INTERVAL 180 DAY) AND (COUNT(*) >= 2 OR SUM(b.netto) >= 500)
                       ORDER BY umsatz DESC LIMIT 15")->fetchAll();

/* ---------------- Länder */
$laender = $abfrage("SELECT COALESCE(NULLIF(k.land, ''), 'unbekannt') landname, COUNT(*) n, SUM(b.netto) umsatz
                     FROM bestellungen b LEFT JOIN kunden k ON k.id = b.kunde_id
                     WHERE $basisBed GROUP BY landname ORDER BY umsatz DESC", $v)->fetchAll();

/* ---------------- Umstieg auf den neuen Shop */
$umstieg = db()->query("SELECT SUM(quelle = 'altshop') alt, SUM(quelle = 'altshop' AND passwort_neu_noetig = 0) aktiv,
                               SUM(quelle = 'shop') neu FROM kunden")->fetch();
$bestNeu = db()->query("SELECT COUNT(*) n, COALESCE(SUM(netto),0) umsatz FROM bestellungen WHERE quelle = 'shop' AND status <> 'storniert'")->fetch();

$link = static fn(array $mehr) => '?' . http_build_query(array_merge(['seite' => 'auswertung', 'zeitraum' => $wahl, 'sort' => $sort], $mehr));
$anteil = static fn($teil, $ganzes) => $ganzes > 0 ? number_format($teil / $ganzes * 100, 1, ',', '.') . ' %' : '–';
?>
<h1>Auswertung <span class="pos">– <?= h($titel) ?> (<?= h($tag($von->format('Y-m-d'))) ?> bis <?= h($tag($bis->modify('-1 day')->format('Y-m-d'))) ?>)</span></h1>
<form method="get" class="karte werkzeug">
  <input type="hidden" name="seite" value="auswertung">
  <input type="hidden" name="sort" value="<?= h($sort) ?>">
  <?php foreach ($zeitraeume as $s => [$name]): ?>
    <button type="submit" name="zeitraum" value="<?= h($s) ?>" class="<?= $s === $wahl ? '' : 'hell' ?>"><?= h($name) ?></button>
  <?php endforeach; ?>
  <span class="pos">Netto, ohne Versand, stornierte Bestellungen nicht mitgezählt. Enthält die übernommenen Bestellungen aus dem alten Shop.</span>
</form>

<div class="kacheln">
  <div class="kachel"><small>Umsatz netto</small><b><?= h($euro($jetzt['umsatz'])) ?></b><?= $vorher ? $trend($jetzt['umsatz'], $vorher['umsatz']) : '' ?></div>
  <div class="kachel"><small>Bestellungen</small><b><?= h($ganz($jetzt['n'])) ?></b><?= $vorher ? $trend($jetzt['n'], $vorher['n']) : '' ?></div>
  <div class="kachel"><small>Ø Bestellwert</small><b><?= h($euro($jetzt['schnitt'])) ?></b><?= $vorher ? $trend($jetzt['schnitt'], $vorher['schnitt']) : '' ?></div>
  <div class="kachel"><small>Kaufende Kunden</small><b><?= h($ganz($jetzt['kunden'])) ?></b><?= $vorher ? $trend($jetzt['kunden'], $vorher['kunden']) : '' ?></div>
  <div class="kachel"><small>Neukunden (erste Bestellung)</small><b><?= h($ganz($jetzt['neu'])) ?></b><?= $vorher ? $trend($jetzt['neu'], $vorher['neu']) : '' ?></div>
  <div class="kachel"><small>Verkaufte Werkzeuge</small><b><?= h($ganz($jetzt['stueck'])) ?> Stück</b><?= $vorher ? $trend($jetzt['stueck'], $vorher['stueck']) : '' ?></div>
</div>
<?php if ($vorher): ?><p class="pos" style="margin-top:-8px">▲▼ = Veränderung gegenüber dem gleich langen Zeitraum davor (<?= h($tag($vonVor->format('Y-m-d'))) ?> bis <?= h($tag($von->modify('-1 day')->format('Y-m-d'))) ?>).</p><?php endif; ?>

<div class="karte">
  <h2>Umsatz je Monat <span class="pos">(letzte 24 Monate)</span></h2>
  <div class="balken-monate">
    <?php foreach ($monate as $m => $w): [$jj, $mm] = explode('-', $m); ?>
      <div class="monat" title="<?= h($monatsnamen[(int) $mm - 1] . ' ' . $jj . ': ' . $euro($w['umsatz']) . ' · ' . $w['n'] . ' Bestellungen') ?>">
        <span class="wert"><?= $w['umsatz'] > 0 ? h($ganz($w['umsatz'] / 1000)) . 'k' : '' ?></span>
        <span class="saeule" style="height:<?= max(2, round($w['umsatz'] / $maxMonat * 100)) ?>%"></span>
        <small><?= h($monatsnamen[(int) $mm - 1]) ?><?= $mm === '01' ? '<br>' . h($jj) : '' ?></small>
      </div>
    <?php endforeach; ?>
  </div>
  <table class="kompakt">
    <tr><th>Jahr</th><th class="r">Umsatz netto</th><th class="r">Bestellungen</th><th class="r">Ø Bestellwert</th><th class="r">Kunden</th></tr>
    <?php foreach ($jahre as $j): ?>
      <tr><td><?= (int) $j['j'] ?></td><td class="r"><?= h($euro($j['umsatz'])) ?></td><td class="r"><?= (int) $j['n'] ?></td>
        <td class="r"><?= h($euro($j['n'] ? $j['umsatz'] / $j['n'] : 0)) ?></td><td class="r"><?= (int) $j['kunden'] ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="karte">
  <h2>Top-Kunden nach Umsatz <span class="pos">(<?= h($titel) ?>)</span></h2>
  <table>
    <tr><th>#</th><th>Kunde</th><th class="r">Bestellungen</th><th class="r">Umsatz netto</th><th class="r">Anteil</th><th class="r">Ø Bestellwert</th><th>Kunde seit</th><th>Letzte Bestellung</th></tr>
    <?php foreach ($topKunden as $i => $k): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><strong><?= h($kundenName($k)) ?></strong><br><span class="pos"><?= $k['ort'] ? h($k['ort']) . ' · ' : '' ?>
          <a href="?seite=kunden&amp;q=<?= h(rawurlencode((string) $k['email'])) ?>">Kunde</a> ·
          <a href="?seite=bestellungen&amp;q=<?= h(rawurlencode((string) $k['email'])) ?>">Bestellungen</a></span></td>
        <td class="r"><?= (int) $k['n'] ?></td>
        <td class="r"><strong><?= h($euro($k['umsatz'])) ?></strong></td>
        <td class="r"><?= h($anteil($k['umsatz'], $jetzt['umsatz'])) ?></td>
        <td class="r"><?= h($euro($k['umsatz'] / max(1, $k['n']))) ?></td>
        <td><?= h($tag($k['erste'])) ?></td>
        <td><?= h($tag($k['letzte'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$topKunden): ?><tr><td colspan="8" class="pos">Keine Bestellungen in diesem Zeitraum.</td></tr><?php endif; ?>
  </table>
</div>

<div class="karte">
  <h2>Bestseller – Produkte <span class="pos">(alle Abmessungen zusammen)</span></h2>
  <p class="werkzeug pos">Sortiert nach:
    <a href="<?= h($link(['sort' => 'umsatz'])) ?>" class="schalter <?= $sort === 'umsatz' ? 'an' : '' ?>">Umsatz</a>
    <a href="<?= h($link(['sort' => 'stueck'])) ?>" class="schalter <?= $sort === 'stueck' ? 'an' : '' ?>">Stückzahl</a></p>
  <table>
    <tr><th>#</th><th>Produkt</th><th style="width:34%"></th><th class="r">Stück</th><th class="r">Umsatz netto</th><th class="r">Anteil</th><th>Beliebteste Abmessung</th></tr>
    <?php $i = 0; foreach (array_slice($produkte, 0, 15, true) as $p): $i++; ?>
      <tr>
        <td><?= $i ?></td>
        <td><strong><?= $p['id'] ? '<a href="../produkte/' . h($p['id']) . '.html" target="_blank">' . h($p['name']) . '</a>' : h($p['name']) ?></strong>
          <br><span class="pos"><?= (int) $p['abmessungen'] ?> verkaufte Abmessungen</span></td>
        <td><span class="balken"><span style="width:<?= round($p[$sort] / $maxProdukt * 100) ?>%"></span></span></td>
        <td class="r"><?= h($ganz($p['stueck'])) ?></td>
        <td class="r"><?= h($euro($p['umsatz'])) ?></td>
        <td class="r"><?= h($anteil($p['umsatz'], $warenUmsatz)) ?></td>
        <td><?= h($kurz((string) $p['top'][0])) ?> <span class="pos">(<?= (int) $p['top'][1] ?> Stück)</span></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="karte">
  <h2>Bestseller – einzelne Artikel <span class="pos">(Top 25 nach <?= $sort === 'stueck' ? 'Stückzahl' : 'Umsatz' ?>)</span></h2>
  <table>
    <tr><th>#</th><th>Art.-Nr.</th><th>Artikel</th><th class="r">Stück</th><th class="r">Umsatz netto</th><th class="r">in Bestellungen</th><th class="r">Kunden</th></tr>
    <?php foreach (array_slice($artikel, 0, 25) as $i => $a): ?>
      <tr><td><?= $i + 1 ?></td><td><?= h($a['sku']) ?></td>
        <td><?= h($a['bez']) ?><?= $a['auswahl'] ? '<br><span class="pos">' . h($kurz($a['auswahl'])) . '</span>' : '' ?></td>
        <td class="r"><strong><?= h($ganz($a['stueck'])) ?></strong></td><td class="r"><?= h($euro($a['umsatz'])) ?></td>
        <td class="r"><?= (int) $a['bestellungen'] ?></td><td class="r"><?= (int) $a['kunden'] ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="zwei-spalten">
  <div class="karte">
    <h2>Nachfassen: Kunden, die länger nichts bestellt haben</h2>
    <p class="pos">Früher mind. 2 Bestellungen oder 500 € Umsatz – aber seit über 6 Monaten keine Bestellung mehr (gesamter Zeitraum).</p>
    <table class="kompakt">
      <tr><th>Kunde</th><th class="r">Umsatz</th><th>Zuletzt</th></tr>
      <?php foreach ($schlafend as $k): ?>
        <tr><td><strong><?= h($kundenName($k)) ?></strong><br>
          <a class="pos" href="mailto:<?= h((string) $k['email']) ?>"><?= h((string) $k['email']) ?></a><?= $k['telefon'] ? ' · <span class="pos">' . h($k['telefon']) . '</span>' : '' ?></td>
          <td class="r"><?= h($euro($k['umsatz'])) ?><br><span class="pos"><?= (int) $k['n'] ?> Best.</span></td>
          <td><?= h($tag($k['letzte'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$schlafend): ?><tr><td colspan="3" class="pos">Niemand – alle guten Kunden waren im letzten halben Jahr aktiv.</td></tr><?php endif; ?>
    </table>
  </div>
  <div>
    <div class="karte">
      <h2>Umsatz nach Land <span class="pos">(<?= h($titel) ?>)</span></h2>
      <table class="kompakt">
        <tr><th>Land</th><th class="r">Bestellungen</th><th class="r">Umsatz</th><th class="r">Anteil</th></tr>
        <?php foreach ($laender as $l): ?>
          <tr><td><?= h($l['landname']) ?></td><td class="r"><?= (int) $l['n'] ?></td><td class="r"><?= h($euro($l['umsatz'])) ?></td><td class="r"><?= h($anteil($l['umsatz'], $jetzt['umsatz'])) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="karte">
      <h2>Umstieg auf den neuen Shop</h2>
      <p><b><?= (int) $umstieg['aktiv'] ?></b> von <?= (int) $umstieg['alt'] ?> Altkunden haben ihr neues Passwort festgelegt
        <span class="balken"><span style="width:<?= $umstieg['alt'] ? round($umstieg['aktiv'] / $umstieg['alt'] * 100) : 0 ?>%"></span></span></p>
      <p><b><?= (int) $umstieg['neu'] ?></b> neue Kundenkonten · <b><?= (int) $bestNeu['n'] ?></b> Bestellungen im neuen Shop (<?= h($euro($bestNeu['umsatz'])) ?> netto)</p>
    </div>
  </div>
</div>
