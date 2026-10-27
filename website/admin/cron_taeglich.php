<?php
/**
 * TRIPLEX-Q – täglicher automatischer Lauf (Strato Cron-Job, z. B. jede Nacht 3 Uhr):
 *   1. Datensicherung der Datenbank (admin/sicherungen/, 30 Tage, die 7 neuesten bleiben immer)
 *   2. Wächter-Prüfung; Ergebnis in waechter_laeufe
 *   3. Alarm-Mail nur wenn etwas kritisch ist oder ein NEUES Problem dazugekommen ist
 * Läuft nur über den Zeitplan bzw. die Kommandozeile – im Browser aufgerufen gibt es 404.
 */
declare(strict_types=1);

if (isset($_SERVER['REQUEST_METHOD'])) {          // Aufruf über das Web: verweigern
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/sicherheit_funktionen.php';
@set_time_limit(600);

$bericht = [];
try {
    $name = sicherung_erstellen();
    $bericht[] = 'Sicherung: ' . $name . ' (' . number_format(filesize(SICHERUNG_ORDNER . '/' . $name) / 1024, 0, ',', '.') . ' KB)';
    $weg = sicherungen_aufraeumen(30);
    $bericht[] = 'Alte Sicherungen gelöscht: ' . $weg;
} catch (Throwable $e) {
    $bericht[] = 'SICHERUNG FEHLGESCHLAGEN: ' . $e->getMessage();
    alarm_senden('sicherung', 'Datensicherung fehlgeschlagen', "Die nächtliche Datensicherung hat nicht geklappt:\n" . $e->getMessage(), 600);
}

$e = waechter_pruefen(true);
$probleme = [];
foreach ($e['pruefungen'] as $p) {
    if (in_array($p['stand'], ['kritisch', 'achtung'], true)) {
        $probleme[] = ($p['stand'] === 'kritisch' ? 'KRITISCH' : 'Hinweis') . ': ' . $p['gruppe'] . ' – ' . strip_tags(html_entity_decode($p['titel']))
            . ' – ' . strip_tags(html_entity_decode($p['text']));
    }
}

waechter_lauf_tabelle();
$vorher = letzter_lauf();
$alteProbleme = $vorher ? (json_decode((string) $vorher['probleme'], true) ?: []) : [];
db()->prepare('INSERT INTO waechter_laeufe (punkte, kritisch, achtung, probleme) VALUES (?, ?, ?, ?)')
    ->execute([$e['punkte'], $e['kritisch'], $e['achtung'], json_encode($probleme, JSON_UNESCAPED_UNICODE)]);
db()->exec('DELETE FROM waechter_laeufe WHERE zeit < (NOW() - INTERVAL 400 DAY)');

$neueProbleme = array_values(array_diff($probleme, $alteProbleme));
if ($e['kritisch'] > 0 || $neueProbleme) {
    alarm_senden('waechter:' . date('Y-m-d'), $e['kritisch'] > 0 ? "Wächter: {$e['kritisch']} kritische(r) Punkt(e)" : 'Wächter: neuer Hinweis',
        "Der nächtliche Wächter-Lauf hat " . ($e['kritisch'] > 0 ? 'KRITISCHE Punkte' : 'neue Hinweise') . " gefunden ({$e['punkte']} von 100 Punkten).\n\n"
        . ($neueProbleme ? "Neu seit dem letzten Lauf:\n- " . implode("\n- ", $neueProbleme) . "\n\n" : '')
        . "Alle offenen Punkte:\n- " . implode("\n- ", $probleme) . "\n\n" . implode("\n", $bericht), 600);
}

echo date('d.m.Y H:i'), " – Wächter {$e['punkte']}/100, kritisch {$e['kritisch']}, Hinweise {$e['achtung']}\n", implode("\n", $bericht), "\n";
