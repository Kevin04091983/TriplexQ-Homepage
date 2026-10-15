<?php
/**
 * Übernahme aus dem alten Shop direkt auf dem Server (ohne Browser). Wird mit den CSV-Dateien nur
 * vorübergehend nach /tmp kopiert und danach gelöscht – liegt nie im Web-Ordner.
 *   TQ_WEB=~/triplex-q TQ_ART=kunden|gruppenpreise|bestellungen_xml TQ_CSV=/tmp/x.csv [TQ_ECHT=1] php -q import_server.php
 * Ohne TQ_ECHT nur Probelauf (speichert nichts). Gibt nur Zahlen aus, E-Mail-Adressen werden maskiert.
 */
declare(strict_types=1);
$web = getenv('TQ_WEB');
$art = getenv('TQ_ART');
$csv = getenv('TQ_CSV');
$echt = getenv('TQ_ECHT') === '1';
require $web . '/api/db.php';
require $web . '/admin/uebernahme_funktionen.php';
@set_time_limit(600);
if ($art === 'bestellungen_xml') {
    $b = bestellungen_xml_uebernehmen($csv, !$echt);
    echo ($echt ? 'ÜBERNAHME' : 'PROBELAUF'), " Bestellungen (XML): ", json_encode(array_diff_key($b, ['hinweise' => 1]), JSON_UNESCAPED_UNICODE), "
";
    foreach ($b['hinweise'] as $h) { echo '  - ', $h, "
"; }
    exit;
}
[$kopf, $zeilen] = csv_lesen($csv);
$GLOBALS['import_pfad'] = $csv;
$map = zuordnen($art, $kopf);
echo ($echt ? 'ÜBERNAHME' : 'PROBELAUF'), " $art: ", count($zeilen), " Zeilen\n";
foreach ($map as $feld => $i) {
    echo "  $feld <- ", $i === null ? '–' : $kopf[$i], "\n";
}
$b = uebernehmen($art, $zeilen, $map, !$echt);
$maske = static fn(string $t) => preg_replace('/[\w.+-]+@([\w-]+\.)+\w+/u', '…@…', $t);
foreach ($b as $k => $v) {
    if ($k !== 'hinweise') {
        echo "$k: ", is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), "\n";
    }
}
echo 'Hinweise (', count($b['hinweise']), "):\n";
foreach ($b['hinweise'] as $h) {
    echo '  - ', $maske($h), "\n";
}
