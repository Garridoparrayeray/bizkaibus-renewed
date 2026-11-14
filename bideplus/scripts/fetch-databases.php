<?php

// Baja las bases de las redes de la release «bases», que «Rebuild schedule data» deja cada noche igual que producción.
// Uso: php scripts/fetch-databases.php [--red=slug,slug] [--out=carpeta] [--release=bases] [--fichero=nombre]
// Con --fichero baja solo ese fichero de la release; todo se comprueba con el tamaño y la huella SHA-256.

declare(strict_types=1);

const REPOSITORY = 'Garridoparrayeray/bizkaibus-renewed';
const DEFAULT_RELEASE = 'bases';
const MANIFEST_FILE = 'bases.json';
// pace-data.sh sube primero <nombre>.nuevo: si la subida se cortó, esa es la copia completa.
const PENDING_SUFFIX = '.nuevo';
const API_URL = 'https://api.github.com/repos/%s/releases/tags/%s';
const DIGEST_PREFIX = 'sha256:';
const TRIES = 4;
const RETRY_SECONDS = 5;
const TIMEOUT_SECONDS = 600;
const CHUNK_BYTES = 1048576;
const HTTP_OK = 200;
const HTTP_NOT_FOUND = 404;
const EXIT_FAILED = 1;
const EXIT_MISSING = 2;

final class ReleaseMissing extends RuntimeException
{
}

// Pide una URL con reintentos; con $sFile guarda el cuerpo en ese fichero y no lo devuelve.
function request(string $sUrl, string|null $sFile = null): string
{
    $aHeaders = ['User-Agent: bideplus-fetch-databases'];
    $sToken = (string)getenv('GH_TOKEN');
    if ($sToken !== '' && str_starts_with($sUrl, 'https://api.github.com/')) {
        $aHeaders[] = 'Authorization: Bearer ' . $sToken;
    }
    $sError = '';
    for ($iTry = 1; $iTry <= TRIES; $iTry++) {
        $Curl = curl_init($sUrl);
        $Handle = null;
        curl_setopt_array($Curl, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $aHeaders, CURLOPT_TIMEOUT => TIMEOUT_SECONDS]);
        if ($sFile === null) {
            curl_setopt($Curl, CURLOPT_RETURNTRANSFER, true);
        } else {
            $Handle = fopen($sFile, 'wb');
            curl_setopt($Curl, CURLOPT_FILE, $Handle);
        }
        $sBody = curl_exec($Curl);
        $iStatus = (int)curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
        $sError = curl_error($Curl) . ' (HTTP ' . $iStatus . ')';
        if ($Handle !== null) {
            fclose($Handle);
        }
        if ($iStatus === HTTP_NOT_FOUND) {
            throw new ReleaseMissing($sUrl . ' no existe');
        }
        if ($sBody !== false && $iStatus === HTTP_OK) {
            return (string)$sBody;
        }
        sleep($iTry * RETRY_SECONDS);
    }
    throw new RuntimeException('No se puede bajar ' . $sUrl . ': ' . $sError);
}

// Los ficheros de la release: nombre => tamaño, huella y dirección de descarga.
function releaseAssets(string $sRelease): array
{
    $sRepository = (string)getenv('GITHUB_REPOSITORY');
    if ($sRepository === '') {
        $sRepository = REPOSITORY;
    }
    $aRelease = json_decode(request(sprintf(API_URL, $sRepository, rawurlencode($sRelease))), true);
    $aAssets = [];
    foreach ($aRelease['assets'] ?? [] as $aAsset) {
        $aAssets[$aAsset['name']] = [
            'size' => (int)$aAsset['size'],
            'digest' => (string)($aAsset['digest'] ?? ''),
            'url' => $aAsset['browser_download_url'],
        ];
    }
    return $aAssets;
}

// Baja un fichero de la release a $sTarget y comprueba que llega entero y con la huella que da GitHub.
function fetchAsset(array $aAssets, string $sName, string $sTarget): void
{
    $aAsset = $aAssets[$sName] ?? $aAssets[$sName . PENDING_SUFFIX] ?? null;
    if ($aAsset === null) {
        throw new ReleaseMissing('La release no tiene ' . $sName);
    }
    request($aAsset['url'], $sTarget);
    if (filesize($sTarget) !== $aAsset['size']) {
        throw new RuntimeException($sName . ': llegan ' . filesize($sTarget) . ' bytes y son ' . $aAsset['size']);
    }
    $bSigned = str_starts_with($aAsset['digest'], DIGEST_PREFIX);
    if ($bSigned && DIGEST_PREFIX . hash_file('sha256', $sTarget) !== $aAsset['digest']) {
        throw new RuntimeException($sName . ': la huella SHA-256 no coincide con la de GitHub');
    }
}

function gunzipTo(string $sPacked, string $sTarget): void
{
    $Input = gzopen($sPacked, 'rb');
    $Output = fopen($sTarget, 'wb');
    while (!gzeof($Input)) {
        fwrite($Output, (string)gzread($Input, CHUNK_BYTES));
    }
    gzclose($Input);
    fclose($Output);
}

function sameFile(string $sPath, array $aExpected): bool
{
    return is_file($sPath) && filesize($sPath) === $aExpected['bytes'] && hash_file('sha256', $sPath) === $aExpected['sha256'];
}

// Baja, descomprime y comprueba una base; si no llega bien, deja la que hubiera en $sTarget.
function fetchDatabase(array $aAssets, array $aExpected, string $sPacked, string $sTarget): void
{
    fetchAsset($aAssets, $aExpected['file'], $sPacked);
    gunzipTo($sPacked, $sTarget . '.bajando');
    unlink($sPacked);
    if (!sameFile($sTarget . '.bajando', $aExpected)) {
        unlink($sTarget . '.bajando');
        throw new RuntimeException('la base descomprimida no es la de bases.json');
    }
    rename($sTarget . '.bajando', $sTarget);
}

// Cada base de bases.json que no esté ya igual en $sOut. Si falla una, sigue con las demás y avisa al final.
function fetchDatabases(array $aAssets, string $sOut, array $aWanted): void
{
    $sTemporary = sys_get_temp_dir() . '/bases-' . getmypid();
    @mkdir($sTemporary, 0775, true);
    fetchAsset($aAssets, MANIFEST_FILE, $sTemporary . '/' . MANIFEST_FILE);
    $aManifest = json_decode((string)file_get_contents($sTemporary . '/' . MANIFEST_FILE), true);
    $aNetworks = $aManifest['networks'] ?? [];
    $aMissing = array_diff($aWanted, array_keys($aNetworks));
    if ($aMissing !== []) {
        throw new ReleaseMissing('La release no tiene ' . implode(', ', $aMissing));
    }
    $aFailed = [];
    foreach ($aNetworks as $sSlug => $aExpected) {
        if ($aWanted !== [] && !in_array($sSlug, $aWanted, true)) {
            continue;
        }
        $sTarget = $sOut . '/' . $aExpected['db'] . '.sqlite';
        if (sameFile($sTarget, $aExpected)) {
            echo $sSlug . ": ya está al día\n";
            continue;
        }
        try {
            fetchDatabase($aAssets, $aExpected, $sTemporary . '/' . $aExpected['file'], $sTarget);
            echo sprintf("%s: %s (%.1f MB)\n", $sSlug, $sTarget, $aExpected['bytes'] / 1e6);
        } catch (RuntimeException $Error) {
            fwrite(STDERR, $sSlug . ': ' . $Error->getMessage() . "\n");
            $aFailed[] = $sSlug;
        }
    }
    rename($sTemporary . '/' . MANIFEST_FILE, $sOut . '/' . MANIFEST_FILE);
    rmdir($sTemporary);
    echo 'Bases de la reconstrucción completa del ' . gmdate('Y-m-d H:i', (int)($aManifest['rebuilt'] ?? 0)) . " UTC\n";
    if ($aFailed !== []) {
        throw new RuntimeException('No se han podido bajar: ' . implode(', ', $aFailed));
    }
}

$aOptions = getopt('', ['red::', 'out::', 'release::', 'fichero::']);
$sOut = rtrim($aOptions['out'] ?? __DIR__ . '/../data', '/');
$aWanted = array_values(array_filter(explode(',', $aOptions['red'] ?? '')));
try {
    @mkdir($sOut, 0775, true);
    $aAssets = releaseAssets($aOptions['release'] ?? DEFAULT_RELEASE);
    if (isset($aOptions['fichero'])) {
        fetchAsset($aAssets, $aOptions['fichero'], $sOut . '/' . $aOptions['fichero']);
        echo $sOut . '/' . $aOptions['fichero'] . ': ' . filesize($sOut . '/' . $aOptions['fichero']) . " bytes, huella correcta\n";
        exit(0);
    }
    fetchDatabases($aAssets, $sOut, $aWanted);
} catch (ReleaseMissing $Missing) {
    fwrite(STDERR, $Missing->getMessage() . "\n");
    exit(EXIT_MISSING);
} catch (RuntimeException $Error) {
    fwrite(STDERR, $Error->getMessage() . "\n");
    exit(EXIT_FAILED);
}
