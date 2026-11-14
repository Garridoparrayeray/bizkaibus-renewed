<?php

// Prepara la release «bases»: cada base comprimida con su huella en el nombre (<db>-<sha8>.sqlite.gz) y bases.json.
// Uso: php scripts/prepare-databases.php --out=dir [--data=dir] [--except=slug] [--rebuilt=epoch] [--commit=sha]
// --rebuilt es la hora de la última reconstrucción completa y --commit, lo desplegado; --except no se publica.

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const MANIFEST_FILE = 'bases.json';
const PACKED_SUFFIX = '.sqlite.gz';
const NAME_HASH_LENGTH = 8;
const GZIP_MODE = 'wb6';
const CHUNK_BYTES = 1048576;

function gzipTo(string $sSource, string $sTarget): void
{
    $Input = fopen($sSource, 'rb');
    $Output = gzopen($sTarget, GZIP_MODE);
    while (!feof($Input)) {
        gzwrite($Output, (string)fread($Input, CHUNK_BYTES));
    }
    fclose($Input);
    gzclose($Output);
}

$aOptions = getopt('', ['out:', 'data::', 'except::', 'rebuilt::', 'commit::']);
if (!isset($aOptions['out'])) {
    fwrite(STDERR, "Falta --out=carpeta\n");
    exit(1);
}
$sOut = rtrim($aOptions['out'], '/');
$sData = rtrim($aOptions['data'] ?? __DIR__ . '/../data', '/');
$aExcept = array_filter(explode(',', $aOptions['except'] ?? ''));
@mkdir($sOut, 0775, true);
$aNetworks = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    $sSource = $sData . '/' . $aNetwork['db'] . '.sqlite';
    if (in_array($sSlug, $aExcept, true)) {
        continue;
    }
    if (!is_file($sSource) || filesize($sSource) === 0) {
        fwrite(STDERR, $sSlug . ': falta ' . $sSource . "\n");
        exit(1);
    }
    // El nombre cambia con el contenido: quien lee el bases.json de ayer sigue encontrando sus ficheros mientras se sube.
    $sHash = hash_file('sha256', $sSource);
    $sFile = $aNetwork['db'] . '-' . substr($sHash, 0, NAME_HASH_LENGTH) . PACKED_SUFFIX;
    gzipTo($sSource, $sOut . '/' . $sFile);
    $aNetworks[$sSlug] = ['db' => $aNetwork['db'], 'file' => $sFile, 'bytes' => filesize($sSource), 'sha256' => $sHash];
    echo sprintf("%-16s %6.1f MB -> %6.1f MB\n", $sSlug, filesize($sSource) / 1e6, filesize($sOut . '/' . $sFile) / 1e6);
}
$aManifest = [
    'rebuilt' => (int)($aOptions['rebuilt'] ?? time()),
    'generated' => time(),
    'commit' => $aOptions['commit'] ?? '',
    'networks' => $aNetworks,
];
file_put_contents($sOut . '/' . MANIFEST_FILE, json_encode($aManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
