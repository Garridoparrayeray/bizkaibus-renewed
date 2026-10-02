<?php

// /sitemap.xml: índice con un sitemap por red. /sitemap-<red>.xml: cada parada y línea de esa red.
// Se genera desde las bases de datos, así que se actualiza solo con la reconstrucción diaria.

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

$site = require __DIR__ . '/Config/site.php';

$aNetworks = [
    'bus' => 'bizkaibus',
    'metro' => 'metrobilbao',
    'euskotren' => 'euskotren',
    'tranvia-bilbao' => 'tranviabilbao',
    'tranvia-vitoria' => 'tranviavitoria',
    'renfe' => 'renfe',
];

function lastModified(string $sDbPath): string
{
    try {
        $Pdo = new PDO('sqlite:' . $sDbPath, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $sValue = $Pdo->query("SELECT value FROM meta WHERE key = 'schedule_source_published'")->fetchColumn();
        if (is_string($sValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sValue)) {
            return $sValue;
        }
    } catch (Throwable $Ex) {
    }
    return date('Y-m-d', (int)filemtime($sDbPath));
}

function xmlEscape(string $sValue): string
{
    return htmlspecialchars($sValue, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$sNetwork = $_GET['red'] ?? '';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=0, s-maxage=86400');

if ($sNetwork === '') {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '  <sitemap><loc>' . xmlEscape($site['url'] . '/sitemap-apps.xml') . '</loc></sitemap>' . "\n";
    foreach ($aNetworks as $sSlug => $sDb) {
        $sPath = __DIR__ . '/../data/' . $sDb . '.sqlite';
        if (!is_file($sPath)) {
            continue;
        }
        echo '  <sitemap><loc>' . xmlEscape($site['url'] . '/sitemap-' . $sSlug . '.xml') . '</loc><lastmod>' . lastModified($sPath) . '</lastmod></sitemap>' . "\n";
    }
    echo '</sitemapindex>' . "\n";
    return;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if ($sNetwork === 'apps') {
    echo '  <url><loc>' . xmlEscape($site['url'] . '/') . '</loc><changefreq>monthly</changefreq><priority>1.0</priority></url>' . "\n";
    foreach ($aNetworks as $sSlug => $sDb) {
        echo '  <url><loc>' . xmlEscape($site['url'] . '/?red=' . $sSlug) . '</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>' . "\n";
    }
    echo '</urlset>' . "\n";
    return;
}

if (!isset($aNetworks[$sNetwork])) {
    http_response_code(404);
    echo '</urlset>' . "\n";
    return;
}

$sPath = __DIR__ . '/../data/' . $aNetworks[$sNetwork] . '.sqlite';
$sLastModified = lastModified($sPath);
$sSuffix = '';
if ($sNetwork !== 'bus') {
    $sSuffix = '?red=' . $sNetwork;
}

$Pdo = new PDO('sqlite:' . $sPath, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
foreach ($Pdo->query('SELECT id FROM lines ORDER BY id') as $aRow) {
    $sLoc = $site['url'] . '/lines/' . rawurlencode((string)$aRow['id']) . $sSuffix;
    echo '  <url><loc>' . xmlEscape($sLoc) . '</loc><lastmod>' . $sLastModified . '</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>' . "\n";
}
foreach ($Pdo->query('SELECT id FROM stops ORDER BY id') as $aRow) {
    $sLoc = $site['url'] . '/stops/' . rawurlencode((string)$aRow['id']) . $sSuffix;
    echo '  <url><loc>' . xmlEscape($sLoc) . '</loc><lastmod>' . $sLastModified . '</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
