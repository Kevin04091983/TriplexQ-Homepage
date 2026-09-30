<?php
/**
 * TRIPLEX-Q – aktuelle Preise lesen (für alle) und ändern (nur Admin).
 *
 *   GET  preise.php                      → {"ok":true,"preise":{sku: preis|null, ...}}  nur geänderte Preise
 *   POST preise.php?aktion=speichern     → {"csrf":…, "preise":{sku: preis|null}}        Admin
 *   GET  preise.php?aktion=protokoll&sku=… → letzte Änderungen                          Admin
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
sitzung_start();

$aktion = $_GET['aktion'] ?? 'liste';

if ($aktion === 'liste') {
    header('Cache-Control: no-cache');
    $geaendert = preise_geaendert();
    $produkte = [];
    if ($geaendert) {
        $betroffen = [];
        $basis = preise_basis();
        foreach (array_keys($geaendert) as $sku) {
            if (isset($basis[$sku][3])) { $betroffen[$basis[$sku][3]] = true; }
        }
        foreach (preise_aktuell() as $z) {
            $id = $z[3] ?? null;
            if ($id === null || !isset($betroffen[$id]) || $z[0] === null) { continue; }
            $produkte[$id] = isset($produkte[$id])
                ? [min($produkte[$id][0], $z[0]), max($produkte[$id][1], $z[0])]
                : [$z[0], $z[0]];
        }
    }
    json_aus(['ok' => true, 'preise' => (object) $geaendert, 'produkte' => (object) $produkte]);
}

// ---------------- ab hier nur für Admins
if (empty($_SESSION['admin'])) {
    json_aus(['ok' => false, 'meldung' => 'Nur für Administratoren.'], 403);
}

if ($aktion === 'protokoll') {
    $sku = text($_GET['sku'] ?? '', 60);
    $s = db()->prepare('SELECT sku, alt, neu, wer, zeit FROM preis_protokoll WHERE (? = "" OR sku = ?) ORDER BY id DESC LIMIT 100');
    $s->execute([$sku, $sku]);
    json_aus(['ok' => true, 'protokoll' => $s->fetchAll()]);
}

if ($aktion === 'speichern') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_aus(['ok' => false, 'meldung' => 'Nur POST'], 405);
    }
    $d = eingabe();
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', (string) ($d['csrf'] ?? ''))) {
        json_aus(['ok' => false, 'meldung' => 'Sitzung abgelaufen, bitte Seite neu laden.'], 403);
    }
    $neu = is_array($d['preise'] ?? null) ? $d['preise'] : [];
    if (!$neu || count($neu) > 2000) {
        json_aus(['ok' => false, 'meldung' => 'Keine Preise übergeben.'], 422);
    }
    try {
        $geaendert = preise_speichern($neu, (string) ($_SESSION['admin_name'] ?? ADMIN_BENUTZER));
    } catch (InvalidArgumentException $e) {
        json_aus(['ok' => false, 'meldung' => $e->getMessage()], 422);
    }
    json_aus(['ok' => true, 'geaendert' => (object) $geaendert, 'anzahl' => count($geaendert)]);
}

json_aus(['ok' => false, 'meldung' => 'Unbekannte Aktion'], 400);
