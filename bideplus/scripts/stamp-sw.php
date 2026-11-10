<?php

// Pone en sw.js la huella de los ficheros que guarda el móvil (SHELL_HASH). La llama el despliegue a producción justo
// antes de subir; en el repositorio sw.js la lleva vacía. Si cambia cualquiera de esos ficheros, cambia la huella y el
// móvil baja la versión nueva entera; si no cambia nada, sw.js queda igual y nadie vuelve a bajar nada.
// Uso: php scripts/stamp-sw.php [--root=carpeta] [--list]

declare(strict_types=1);

const SHELL_DIRS = ['css', 'js', 'lib', 'fonts'];
const SHELL_ROOT_PATTERNS = ['*.css', '*.html', 'manifest*.json'];

// Rutas relativas, ordenadas, de todo lo que el service worker puede servir sin volver a pedir.
function shellFiles(string $sRoot): array
{
    $aDirs = SHELL_DIRS;
    foreach (glob($sRoot . '/icons*', GLOB_ONLYDIR) as $sDir) {
        $aDirs[] = basename($sDir);
    }
    $aFiles = [];
    foreach ($aDirs as $sDir) {
        if (!is_dir($sRoot . '/' . $sDir)) {
            continue;
        }
        $Items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sRoot . '/' . $sDir, FilesystemIterator::SKIP_DOTS));
        foreach ($Items as $File) {
            if ($File->isFile()) {
                $aFiles[] = substr($File->getPathname(), \strlen($sRoot) + 1);
            }
        }
    }
    foreach (SHELL_ROOT_PATTERNS as $sPattern) {
        foreach (glob($sRoot . '/' . $sPattern) as $sFile) {
            $aFiles[] = basename($sFile);
        }
    }
    $aFiles = array_values(array_unique(str_replace('\\', '/', $aFiles)));
    sort($aFiles, SORT_STRING);
    return $aFiles;
}

function shellHash(string $sRoot, array $aFiles): string
{
    $sDigest = '';
    foreach ($aFiles as $sFile) {
        $sDigest .= $sFile . "\0" . sha1_file($sRoot . '/' . $sFile) . "\n";
    }
    return substr(sha1($sDigest), 0, 12);
}

$aOptions = getopt('', ['root:', 'list']);
$sRoot = rtrim($aOptions['root'] ?? __DIR__ . '/..', '/');
$aFiles = shellFiles($sRoot);

if (isset($aOptions['list'])) {
    echo implode("\n", $aFiles) . "\n";
    exit(0);
}

$sWorkerPath = $sRoot . '/sw.js';
$sWorker = (string)file_get_contents($sWorkerPath);
$sHash = shellHash($sRoot, $aFiles);
$sStamped = preg_replace("/^const SHELL_HASH = '[0-9a-f]*';$/m", "const SHELL_HASH = '" . $sHash . "';", $sWorker, -1, $iCount);
if ($iCount !== 1) {
    fwrite(STDERR, "sw.js: no encuentro una sola línea «const SHELL_HASH = '…';» ($iCount)\n");
    exit(1);
}
file_put_contents($sWorkerPath, $sStamped);
// La misma huella para las páginas (cabecera X-Bide-Shell): sw.js sabe con ella si una página es de otra versión.
$sConfigPath = $sRoot . '/api/Config/shell-hash.php';
if (is_file($sConfigPath)) {
    file_put_contents($sConfigPath, "<?php\n\nreturn '" . $sHash . "';\n");
}
echo 'sw.js: huella ' . $sHash . ' (' . \count($aFiles) . " ficheros)\n";
