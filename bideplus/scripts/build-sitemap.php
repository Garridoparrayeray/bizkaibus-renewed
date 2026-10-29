<?php

// Genera sitemap.xml (estático) con todo Bide+: el menú, las seis apps y cada línea y
// parada de cada red. Vercel lo sirve como fichero, sin PHP de por medio, así que
// ningún aviso del intérprete puede colarse en el XML.
//
// Uso: php scripts/build-sitemap.php [--output=ruta]   (por defecto, sitemap.xml en la raíz)
// Se ejecuta en la reconstrucción diaria, después de regenerar las bases de datos.

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

$aOptions = getopt('', ['output:']);
$sRoot = dirname(__DIR__);
$sOutput = $aOptions['output'] ?? $sRoot . '/sitemap.xml';
$site = require $sRoot . '/api/Config/site.php';

require_once $sRoot . '/api/Core/Networks.php';
$aNetworks = array_map(fn($aNetwork) => $aNetwork['db'], \Core\Networks::all());

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

function lastModified(PDO $Pdo, string $sDbPath): string
{
    try {
        $sValue = $Pdo->query("SELECT value FROM meta WHERE key = 'schedule_source_published'")->fetchColumn();
        if (is_string($sValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sValue)) {
            return $sValue;
        }
    } catch (Throwable $Ex) {
    }
    return date('Y-m-d', (int)filemtime($sDbPath));
}

function urlEntry(string $sLoc, string $sLastModified, string $sChangeFreq, string $sPriority): string
{
    return '  <url><loc>' . htmlspecialchars($sLoc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
        . '<lastmod>' . $sLastModified . '</lastmod>'
        . '<changefreq>' . $sChangeFreq . '</changefreq>'
        . '<priority>' . $sPriority . '</priority></url>' . "\n";
}

$aPages = [];
$aLastModified = [];
foreach ($aNetworks as $sSlug => $sDb) {
    $sPath = $sRoot . '/data/' . $sDb . '.sqlite';
    if (!is_file($sPath)) {
        fwrite(STDERR, "Falta $sPath; la red $sSlug queda fuera del sitemap\n");
        continue;
    }
    $Pdo = openReadonly($sPath);
    $sLastModified = lastModified($Pdo, $sPath);
    $aLastModified[$sSlug] = $sLastModified;
    $sSuffix = \Core\Networks::urlSuffix($sSlug);
    foreach ($Pdo->query('SELECT id FROM lines ORDER BY id') as $aRow) {
        $aPages[] = urlEntry($site['url'] . '/lines/' . rawurlencode((string)$aRow['id']) . $sSuffix, $sLastModified, 'weekly', '0.7');
    }
    foreach ($Pdo->query('SELECT id FROM stops ORDER BY id') as $aRow) {
        $aPages[] = urlEntry($site['url'] . '/stops/' . rawurlencode((string)$aRow['id']) . $sSuffix, $sLastModified, 'weekly', '0.6');
    }
}

if (!$aLastModified) {
    fwrite(STDERR, "No hay ninguna base de datos: no se genera el sitemap\n");
    exit(1);
}

$sXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$sXml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$sXml .= urlEntry($site['url'] . '/', max($aLastModified), 'weekly', '1.0');
$sXml .= urlEntry($site['url'] . '/mapa', max($aLastModified), 'weekly', '0.8');
foreach ($aLastModified as $sSlug => $sLastModified) {
    $sXml .= urlEntry($site['url'] . '/?red=' . $sSlug, $sLastModified, 'daily', '0.9');
}
$sXml .= implode('', $aPages);
$sXml .= '</urlset>' . "\n";

file_put_contents($sOutput, $sXml);
echo 'sitemap.xml: ' . (count($aPages) + 2 + count($aLastModified)) . " URLs\n";
