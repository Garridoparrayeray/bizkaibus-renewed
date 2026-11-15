<?php

// Genera los sitemaps (estáticos) de Bide+: sitemap-index.xml y, en sitemaps/, uno con las páginas generales (menú,
// mapa, estado y cada red) y uno por red con sus líneas y paradas, cada página en sus cuatro idiomas (hreflang).
// El lastmod de cada página es el día en que cambió de verdad (nombre, líneas, paradas u horario), no el de la
// compilación: su huella y ese día se guardan en scripts/sitemap-lastmod.json, que la reconstrucción diaria
// (rebuild-schedule.yml) sube al repositorio junto a geocache.json. El día solo cambia cuando cambia la huella.
//
// Uso: php scripts/build-sitemap.php [--output-dir=raíz] [--state=json] [--keep-state]
//   --keep-state  no guarda las huellas nuevas (para comparar con lo del repositorio sin tocarlo)

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

require_once __DIR__ . '/../api/autoload.php';

use Core\Lang;
use Core\Networks;

// Lo que se guarda de cada huella (md5): con 12 caracteres basta para ver si ha cambiado.
const HASH_LENGTH = 12;
// Las páginas generales van en su propio sitemap, con este nombre.
const GENERAL_SITEMAP = 'bide';

$aOptions = getopt('', ['output-dir:', 'state:', 'keep-state']);
$sRoot = dirname(__DIR__);
$sOutputDir = rtrim($aOptions['output-dir'] ?? $sRoot, '/');
$sStatePath = $aOptions['state'] ?? __DIR__ . '/sitemap-lastmod.json';
$aSite = require $sRoot . '/api/Config/site.php';
$sToday = date('Y-m-d');

$aState = [];
if (is_file($sStatePath)) {
    $aState = json_decode((string)file_get_contents($sStatePath), true) ?? [];
}
$aNewState = [];
// El lastmod de una página: el que tenía si su huella no ha cambiado; si no (o si es nueva), hoy.
$Lastmod = function (string $sPath, string $sHash) use ($aState, &$aNewState, $sToday): string {
    $sDate = $sToday;
    if (isset($aState[$sPath]) && $aState[$sPath][0] === $sHash) {
        $sDate = $aState[$sPath][1];
    }
    $aNewState[$sPath] = [$sHash, $sDate];
    return $sDate;
};

function openReadonly(string $sDbPath): PDO
{
    // PHP 8.5 deprecó las constantes PDO::SQLITE_*; se usan las nuevas si existen.
    if (defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS')) {
        $aOptions = [constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS') => constant('Pdo\\Sqlite::OPEN_READONLY')];
    } else {
        $aOptions = [constant('PDO::SQLITE_ATTR_OPEN_FLAGS') => constant('PDO::SQLITE_OPEN_READONLY')];
    }
    return new PDO('sqlite:' . $sDbPath, null, null, $aOptions);
}

// Va sumando a la huella de cada clave las filas que le tocan; al final, cada huella en HASH_LENGTH caracteres.
final class Fingerprints
{
    private array $aContexts = [];

    public function add(string $sKey, string $sData): void
    {
        $this->aContexts[$sKey] ??= hash_init('md5');
        hash_update($this->aContexts[$sKey], $sData . "\n");
    }

    public function digest(string $sKey): string
    {
        return substr(hash_final(hash_copy($this->aContexts[$sKey] ?? hash_init('md5'))), 0, HASH_LENGTH);
    }
}

// Las huellas de las líneas (código, nombre, recorridos y horas de salida) y de las paradas (nombre, zona, líneas y
// horas de paso con sus días). Sin ids de viajes, recorridos ni calendarios: cambian de un GTFS a otro.
function networkFingerprints(PDO $Pdo): array
{
    $Lines = new Fingerprints();
    $Stops = new Fingerprints();
    foreach ($Pdo->query('SELECT id, code, name FROM lines') as $aRow) {
        $Lines->add((string)$aRow['id'], $aRow['code'] . '|' . $aRow['name']);
    }
    $aPatterns = [];
    foreach ($Pdo->query('SELECT jp.line_id, jp.id, jps.stop_id FROM journey_pattern_stops jps JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id ORDER BY jp.line_id, jp.id, jps.seq_order') as $aRow) {
        $aPatterns[(string)$aRow['line_id']][(string)$aRow['id']][] = (string)$aRow['stop_id'];
    }
    foreach ($aPatterns as $sLineId => $aLinePatterns) {
        $aSequences = array_unique(array_map(fn($aStops) => implode(',', $aStops), $aLinePatterns));
        sort($aSequences);
        $Lines->add((string)$sLineId, implode(';', $aSequences));
    }
    $sTrips = "SELECT sj.line_id, jp.headsign, sj.first_departure_seconds, sc.weekday_mask FROM service_journeys sj
        JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id JOIN service_calendars sc ON sc.id = sj.calendar_id
        WHERE sc.id != 'PRUEBA' ORDER BY sj.line_id, jp.headsign, sj.first_departure_seconds, sc.weekday_mask";
    foreach ($Pdo->query($sTrips) as $aRow) {
        $Lines->add((string)$aRow['line_id'], $aRow['headsign'] . '|' . $aRow['first_departure_seconds'] . '|' . $aRow['weekday_mask']);
    }

    // Una estación con andenes suma lo de sus andenes (su página enseña todos).
    $aStations = [];
    foreach ($Pdo->query('SELECT id, name, area, station_id FROM stops ORDER BY id') as $aRow) {
        $Stops->add((string)$aRow['id'], $aRow['name'] . '|' . $aRow['area']);
        if ($aRow['station_id'] !== null && $aRow['station_id'] !== '') {
            $aStations[(string)$aRow['id']] = (string)$aRow['station_id'];
        }
    }
    $AddToStop = function (string $sStopId, string $sData) use ($Stops, $aStations): void {
        $Stops->add($sStopId, $sData);
        if (isset($aStations[$sStopId])) {
            $Stops->add($aStations[$sStopId], $sData);
        }
    };
    $aServed = [];
    foreach ($Pdo->query('SELECT DISTINCT jps.stop_id, l.code, l.name FROM journey_pattern_stops jps JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id JOIN lines l ON l.id = jp.line_id ORDER BY jps.stop_id, l.code, l.name') as $aRow) {
        $AddToStop((string)$aRow['stop_id'], 'L|' . $aRow['code'] . '|' . $aRow['name']);
        $aServed[(string)$aRow['stop_id']] = true;
        $aServed[$aStations[(string)$aRow['stop_id']] ?? (string)$aRow['stop_id']] = true;
    }
    $sTimes = "SELECT pt.stop_id, sj.line_id, pt.departure_seconds, sc.weekday_mask FROM passing_times pt
        JOIN service_journeys sj ON sj.id = pt.service_journey_id JOIN service_calendars sc ON sc.id = sj.calendar_id
        WHERE sc.id != 'PRUEBA' ORDER BY pt.stop_id, sj.line_id, pt.departure_seconds, sc.weekday_mask";
    foreach ($Pdo->query($sTimes) as $aRow) {
        $AddToStop((string)$aRow['stop_id'], $aRow['line_id'] . '|' . $aRow['departure_seconds'] . '|' . $aRow['weekday_mask']);
    }
    return [$Lines, $Stops, $aServed];
}

// Cada página en castellano, euskera, inglés y francés (?lang=), y cada versión con los enlaces a las cuatro
// (hreflang), como pide Google para las páginas en varios idiomas.
// El x-default es la versión en el idioma de la red (seo.default_lang de su ficha; si no, el castellano).
function urlEntry(string $sLoc, string $sLastModified, string $sDefaultLang = 'es'): string
{
    $Escape = fn(string $sUrl) => htmlspecialchars($sUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sAlternates = '';
    foreach (Lang::LANGS as $sLang) {
        $sAlternates .= '<xhtml:link rel="alternate" hreflang="' . $sLang . '" href="' . $Escape(Lang::url($sLoc, $sLang)) . '"/>';
    }
    $sAlternates .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . $Escape(Lang::url($sLoc, $sDefaultLang)) . '"/>';
    $sEntries = '';
    foreach (Lang::LANGS as $sLang) {
        $sEntries .= '  <url><loc>' . $Escape(Lang::url($sLoc, $sLang)) . '</loc><lastmod>' . $sLastModified . '</lastmod>' . $sAlternates . '</url>' . "\n";
    }
    return $sEntries;
}

function writeUrlset(string $sPath, string $sEntries): void
{
    $sXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $sXml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    file_put_contents($sPath, $sXml . $sEntries . '</urlset>' . "\n");
}

if (!is_dir($sOutputDir . '/sitemaps')) {
    mkdir($sOutputDir . '/sitemaps', 0775, true);
}
$aSitemaps = [];
$aNetworkDates = [];
$iPages = 0;
foreach (Networks::all() as $sSlug => $aNetwork) {
    if (!is_file($aNetwork['db_path'])) {
        fwrite(STDERR, "Falta {$aNetwork['db_path']}; la red $sSlug queda fuera del sitemap\n");
        continue;
    }
    $Pdo = openReadonly($aNetwork['db_path']);
    [$Lines, $Stops, $aServed] = networkFingerprints($Pdo);
    $sSuffix = Networks::urlSuffix($sSlug);
    $sDefaultLang = $aNetwork['seo']['default_lang'] ?? 'es';
    $sEntries = '';
    $aDates = [];
    foreach ($Pdo->query('SELECT id FROM lines ORDER BY id') as $aRow) {
        $sPath = '/lines/' . rawurlencode((string)$aRow['id']) . $sSuffix;
        $aDates[] = $Lastmod($sPath, $Lines->digest((string)$aRow['id']));
        $sEntries .= urlEntry($aSite['url'] . $sPath, end($aDates), $sDefaultLang);
    }
    // Los recorridos que el operador publica como una sola línea (map.splits) tienen su página.
    foreach ($aNetwork['map']['splits'] ?? [] as $aSplit) {
        $sPath = '/lines/' . rawurlencode((string)$aSplit['line']) . '/' . rawurlencode($aSplit['code']) . $sSuffix;
        $aDates[] = $Lastmod($sPath, $Lines->digest((string)$aSplit['line']));
        $sEntries .= urlEntry($aSite['url'] . $sPath, end($aDates), $sDefaultLang);
    }
    // Las paradas sin ninguna línea llevan noindex: fuera del sitemap.
    $aListed = Networks::listedStops($aNetwork);
    foreach ($Pdo->query('SELECT id FROM stops ORDER BY id') as $aRow) {
        $sId = (string)$aRow['id'];
        if (($aListed !== null && !isset($aListed[$sId])) || !isset($aServed[$sId])) {
            continue;
        }
        $sPath = '/stops/' . rawurlencode($sId) . $sSuffix;
        $aDates[] = $Lastmod($sPath, $Stops->digest($sId));
        $sEntries .= urlEntry($aSite['url'] . $sPath, end($aDates), $sDefaultLang);
    }
    $iPages += substr_count($sEntries, '<url>');
    $aNetworkDates[$sSlug] = $sToday;
    if (!empty($aDates)) {
        $aNetworkDates[$sSlug] = max($aDates);
    }
    writeUrlset($sOutputDir . '/sitemaps/' . $sSlug . '.xml', $sEntries);
    $aSitemaps[$sSlug] = $aNetworkDates[$sSlug];
}

if (!$aNetworkDates) {
    fwrite(STDERR, "No hay ninguna base de datos: no se genera el sitemap\n");
    exit(1);
}

// Las páginas generales cambian cuando cambia algo de lo que enseñan: la red, con sus líneas y paradas; el resto, con
// cualquiera.
$sNewest = max($aNetworkDates);
$sEntries = urlEntry($aSite['url'] . '/', $sNewest) . urlEntry($aSite['url'] . '/mapa', $sNewest) . urlEntry($aSite['url'] . '/estado', $sNewest);
foreach ($aNetworkDates as $sSlug => $sDate) {
    $sEntries .= urlEntry($aSite['url'] . '/?red=' . $sSlug, $sDate, Networks::get($sSlug)['seo']['default_lang'] ?? 'es');
}
$iPages += substr_count($sEntries, '<url>');
writeUrlset($sOutputDir . '/sitemaps/' . GENERAL_SITEMAP . '.xml', $sEntries);
$aSitemaps = [GENERAL_SITEMAP => $sNewest] + $aSitemaps;

$sIndex = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($aSitemaps as $sName => $sDate) {
    $sIndex .= '  <sitemap><loc>' . $aSite['url'] . '/sitemaps/' . $sName . '.xml</loc><lastmod>' . $sDate . '</lastmod></sitemap>' . "\n";
}
file_put_contents($sOutputDir . '/sitemap-index.xml', $sIndex . '</sitemapindex>' . "\n");

if (!isset($aOptions['keep-state'])) {
    ksort($aNewState);
    file_put_contents($sStatePath, json_encode($aNewState, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}
echo 'sitemap-index.xml: ' . count($aSitemaps) . ' sitemaps, ' . $iPages . " URLs\n";
