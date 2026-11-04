<?php
// Batería de limpieza: las pautas de CONTRIBUTING.md y nada muerto. Uso: php tests/code-quality.php

declare(strict_types=1);

const ROOT = __DIR__ . '/../';
// Lo que no es nuestro o no es código: librerías de terceros, datos generados y capturas.
const SKIPPED_PATHS = '#^(lib|data|offline-data|map-data|screenshots|engine)/|^scripts/geocache\.json$|^sitemap\.xml$#';
const TEXT_EXTENSIONS = ['php', 'js', 'mjs', 'css', 'py', 'md', 'html', 'yml', 'yaml'];
const INDENTED_EXTENSIONS = ['php', 'js', 'mjs', 'css'];
require_once __DIR__ . '/../api/Core/Networks.php';
// Las redes salen del registro (api/Networks): una red nueva no toca esta batería.
define('NETWORK_SLUGS', \Core\Networks::slugs());
define('ENGINE_NAMES', array_values(array_unique(array_filter(array_column(\Core\Networks::all(), 'engine')))));
// Forma del PHP: niveles de sangría, líneas y columnas de un comentario, y desde cuántos tokens cuenta un cuerpo
// repetido.
const MAX_DEPTH = 6;
const MAX_COMMENT_LINES = 3;
const MAX_COMMENT_WIDTH = 120;
const MIN_BODY_TOKENS = 40;
// Variables de PHP que no son nuestras o son contadores de bucle.
const PHP_ALLOWED_NAMES = ['this', '_SERVER', '_GET', '_POST', '_ENV', '_COOKIE', '_FILES', '_REQUEST', 'GLOBALS', 'argv', 'argc', 'http_response_header', 'i', 'j', 'k'];
// El mapa de la red necesita internet (mosaicos y datos del mapa): sus piezas no van en el precache.
const PRECACHE_EXCEPTIONS = ['/js/network-map.js', '/css/style-map.css', '/js/estado.js', '/css/style-estado.css'];

$aProblems = [];
$Report = function (string $sRule, string $sWhere) use (&$aProblems): void {
    $aProblems[$sRule][] = $sWhere;
};

// ===== Archivos =====

$aListed = [];
exec('git -C ' . escapeshellarg(ROOT) . ' ls-files --cached --others --exclude-standard', $aListed);
$aFiles = [];
foreach ($aListed as $sFile) {
    if ($sFile !== '' && !preg_match(SKIPPED_PATHS, $sFile) && is_file(ROOT . $sFile)) {
        $aFiles[] = $sFile;
    }
}
$FilesWith = function (array $aExtensions) use ($aFiles): array {
    return array_values(array_filter($aFiles, fn($sFile) => in_array(pathinfo($sFile, PATHINFO_EXTENSION), $aExtensions, true)));
};
$aSources = [];
foreach ($aFiles as $sFile) {
    if (in_array(pathinfo($sFile, PATHINFO_EXTENSION), TEXT_EXTENSIONS, true)) {
        $aSources[$sFile] = file_get_contents(ROOT . $sFile);
    }
}
$aPhpFiles = $FilesWith(['php']);
$aJsFiles = $FilesWith(['js', 'mjs']);
$aCssFiles = array_values(array_filter($FilesWith(['css']), fn($sFile) => !str_starts_with($sFile, 'fonts/')));
$IsTest = fn(string $sFile): bool => str_starts_with($sFile, 'tests/');

// El algoritmo (repositorio privado en engine/) usa métodos de los modelos: si está, cuenta como uso.
$sEngine = '';
$aEngineFiles = [];
$bEngine = is_dir(ROOT . 'engine');
if ($bEngine) {
    $It = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . 'engine', FilesystemIterator::SKIP_DOTS));
    foreach ($It as $File) {
        if ($File->getExtension() === 'php') {
            $aEngineFiles[] = substr($File->getPathname(), strlen(ROOT . 'engine/'));
            $sEngine .= file_get_contents($File->getPathname()) . "\n";
        }
    }
    sort($aEngineFiles);
}
// Las mismas pautas valen para el algoritmo: su formato y su PHP también se miran (sin el laboratorio ni los datos).
$aEngineSources = [];
if ($bEngine) {
    $aEngineListed = [];
    exec('git -C ' . escapeshellarg(ROOT . 'engine') . ' ls-files --cached --others --exclude-standard', $aEngineListed);
    foreach ($aEngineListed as $sPath) {
        $bText = in_array(pathinfo($sPath, PATHINFO_EXTENSION), TEXT_EXTENSIONS, true);
        if ($bText && !preg_match('#^lab/|(^|/)data/#', $sPath) && is_file(ROOT . 'engine/' . $sPath)) {
            $aEngineSources['engine/' . $sPath] = file_get_contents(ROOT . 'engine/' . $sPath);
        }
    }
}
$aCheckedSources = $aSources + $aEngineSources;
$aCheckedPhp = array_merge($aPhpFiles, array_keys(array_filter($aEngineSources, fn($sCode, $sFile) => str_ends_with($sFile, '.php'), ARRAY_FILTER_USE_BOTH)));

// ===== JavaScript sin cadenas, comentarios ni expresiones regulares =====

// Devuelve el código sin comentarios y con las cadenas vacías (o tal cual, con $bKeepStrings), más las líneas que
// caen dentro de una plantilla o un comentario de varias líneas (su sangría no cuenta). Las expresiones de las
// plantillas (${…}) se conservan.
function scanJs(string $sCode, bool $bKeepStrings = false): array
{
    $sOut = '';
    $aInside = [];
    $iLine = 1;
    $iLength = strlen($sCode);
    $sLast = '';
    $aTemplates = [];
    $iBraces = 0;
    for ($i = 0; $i < $iLength; $i++) {
        $sChar = $sCode[$i];
        $sNext = $sCode[$i + 1] ?? '';
        if ($sChar === "\n") {
            $iLine++;
            $sOut .= "\n";
            continue;
        }
        if ($sChar === '/' && $sNext === '/') {
            while ($i + 1 < $iLength && $sCode[$i + 1] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($sChar === '/' && $sNext === '*') {
            $i += 2;
            while ($i < $iLength && !($sCode[$i] === '*' && ($sCode[$i + 1] ?? '') === '/')) {
                if ($sCode[$i] === "\n") {
                    $iLine++;
                    $aInside[$iLine] = true;
                    $sOut .= "\n";
                }
                $i++;
            }
            $i++;
            continue;
        }
        if ($sChar === '}' && !empty($aTemplates) && $iBraces === end($aTemplates)) {
            array_pop($aTemplates);
            $i = scanTemplate($sCode, $i + 1, $sOut, $aInside, $iLine, $aTemplates, $iBraces, $bKeepStrings);
            $sLast = '`';
            continue;
        }
        if ($sChar === '`') {
            $sOut .= '`';
            $i = scanTemplate($sCode, $i + 1, $sOut, $aInside, $iLine, $aTemplates, $iBraces, $bKeepStrings);
            $sLast = '`';
            continue;
        }
        if ($sChar === '"' || $sChar === "'") {
            $iStart = $i;
            $i++;
            while ($i < $iLength && $sCode[$i] !== $sChar && $sCode[$i] !== "\n") {
                if ($sCode[$i] === '\\') {
                    $i++;
                }
                $i++;
            }
            if ($bKeepStrings) {
                $sOut .= substr($sCode, $iStart, $i - $iStart + 1);
            } else {
                $sOut .= $sChar . $sChar;
            }
            $sLast = $sChar;
            continue;
        }
        if ($sChar === '/' && ($sLast === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $sLast) || preg_match('/(return|typeof|case)$/', rtrim($sOut)))) {
            $bClass = false;
            $i++;
            while ($i < $iLength && ($sCode[$i] !== '/' || $bClass) && $sCode[$i] !== "\n") {
                if ($sCode[$i] === '\\') {
                    $i++;
                } elseif ($sCode[$i] === '[') {
                    $bClass = true;
                } elseif ($sCode[$i] === ']') {
                    $bClass = false;
                }
                $i++;
            }
            while ($i + 1 < $iLength && ctype_alpha($sCode[$i + 1])) {
                $i++;
            }
            $sOut .= '/r/';
            $sLast = '/';
            continue;
        }
        if ($sChar === '{') {
            $iBraces++;
        } elseif ($sChar === '}') {
            $iBraces--;
        }
        $sOut .= $sChar;
        if (!ctype_space($sChar)) {
            $sLast = $sChar;
        }
    }
    return [$sOut, $aInside];
}

// Recorre una plantilla desde $i hasta su ` final o hasta un ${ (que deja abierto para seguir con su código).
function scanTemplate(string $sCode, int $i, string &$sOut, array &$aInside, int &$iLine, array &$aTemplates, int $iBraces, bool $bKeepStrings): int
{
    $iLength = strlen($sCode);
    while ($i < $iLength) {
        $sChar = $sCode[$i];
        if ($sChar === '\\') {
            $i += 2;
            continue;
        }
        if ($sChar === "\n") {
            $iLine++;
            $aInside[$iLine] = true;
            $sOut .= "\n";
        } elseif ($bKeepStrings && $sChar !== '`' && !($sChar === '$' && ($sCode[$i + 1] ?? '') === '{')) {
            $sOut .= $sChar;
        } elseif ($sChar === '`') {
            $sOut .= '`';
            return $i;
        } elseif ($sChar === '$' && ($sCode[$i + 1] ?? '') === '{') {
            $aTemplates[] = $iBraces;
            $sOut .= '${';
            return $i + 1;
        }
        $i++;
    }
    return $i;
}

$aJsCode = [];
foreach ($aJsFiles as $sFile) {
    $aJsCode[$sFile] = scanJs($aSources[$sFile]);
}

// ===== 1. Formato =====

foreach ($aCheckedSources as $sFile => $sText) {
    if (str_contains($sText, "\r")) {
        $Report('Formato: saltos de línea de Windows (CRLF)', $sFile);
    }
    if ($sText !== '' && !str_ends_with($sText, "\n")) {
        $Report('Formato: falta el salto de línea final', $sFile);
    }
    foreach (explode("\n", $sText) as $iIndex => $sLine) {
        if (str_contains($sLine, "\t")) {
            $Report('Formato: tabuladores (van 4 espacios)', $sFile . ':' . ($iIndex + 1));
        }
        if (preg_match('/[ \t]+$/', $sLine)) {
            $Report('Formato: espacios al final de la línea', $sFile . ':' . ($iIndex + 1));
        }
    }
}

// Sangría de 4 en 4. No cuentan el texto de las cadenas de varias líneas, el HTML de las plantillas PHP ni los
// comentarios de bloque.
foreach ($aCheckedSources as $sFile => $sText) {
    $sExtension = pathinfo($sFile, PATHINFO_EXTENSION);
    if (!in_array($sExtension, INDENTED_EXTENSIONS, true)) {
        continue;
    }
    $aFree = [];
    if ($sExtension === 'php') {
        foreach (token_get_all($sText) as $Token) {
            if (is_array($Token) && in_array($Token[0], [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC], true)) {
                for ($iLine = $Token[2] + 1; $iLine <= $Token[2] + substr_count($Token[1], "\n"); $iLine++) {
                    $aFree[$iLine] = true;
                }
            }
            if (is_array($Token) && $Token[0] === T_END_HEREDOC) {
                $aFree[$Token[2]] = true;
            }
        }
    } elseif ($sExtension !== 'css') {
        $aFree = ($aJsCode[$sFile] ?? scanJs($sText))[1];
    }
    $bComment = false;
    foreach (explode("\n", $sText) as $iIndex => $sLine) {
        $iLine = $iIndex + 1;
        if ($sExtension === 'css') {
            $bOpens = str_contains($sLine, '/*') && !str_contains($sLine, '*/');
            if ($bComment || $bOpens) {
                $bComment = !str_contains($sLine, '*/');
                continue;
            }
        }
        if (isset($aFree[$iLine]) || preg_match('/^\s*\*/', $sLine)) {
            continue;
        }
        if (preg_match('/^( *)\S/', $sLine, $aMatch) && strlen($aMatch[1]) % 4 !== 0) {
            $Report('Formato: sangría que no va de 4 en 4', $sFile . ':' . $iLine);
        }
    }
}

// CSS: medidas en px y una declaración por línea.
foreach ($aCssFiles as $sFile) {
    $sCss = preg_replace_callback('#/\*.*?\*/#s', fn($aMatch) => preg_replace('/[^\n]/', ' ', $aMatch[0]), $aSources[$sFile]);
    foreach (explode("\n", $sCss) as $iIndex => $sLine) {
        if (preg_match('/(?<![\w.#-])(\d+\.?\d*|\.\d+)rem\b/', $sLine)) {
            $Report('CSS: medida en rem (va en px)', $sFile . ':' . ($iIndex + 1));
        }
        if (preg_match('/\{\s*\S|;\s*\S/', $sLine)) {
            $Report('CSS: regla en una línea (una declaración por línea)', $sFile . ':' . ($iIndex + 1));
        }
    }
}

// ===== 2. PHP =====

foreach ($aCheckedPhp as $sFile) {
    $aTokens = [];
    foreach (token_get_all($aCheckedSources[$sFile]) as $Token) {
        if (!is_array($Token) || !in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $aTokens[] = $Token;
        }
    }
    $iLine = 1;
    foreach ($aTokens as $iIndex => $Token) {
        if (is_array($Token)) {
            $iLine = $Token[2];
        }
        // «?» de un tipo que admite null (?string) va detrás de «(», «,», «:» o de public, private…; si no, es un ternario.
        if ($Token === '?') {
            $Previous = $aTokens[$iIndex - 1] ?? null;
            $sPrevious = $Previous;
            if (is_array($Previous)) {
                $sPrevious = token_name($Previous[0]);
            }
            if (!in_array($sPrevious, ['(', ',', ':', '|', 'T_PUBLIC', 'T_PRIVATE', 'T_PROTECTED', 'T_READONLY', 'T_STATIC', 'T_VAR', 'T_CONST'], true)) {
                $Report('PHP: operador ternario (va con if/else)', $sFile . ':' . $iLine);
            }
        }
        if (is_array($Token) && $Token[0] === T_ELSE && is_array($aTokens[$iIndex + 1] ?? null) && $aTokens[$iIndex + 1][0] === T_IF) {
            $Report('PHP: «else if» (va junto: elseif)', $sFile . ':' . $iLine);
        }
        if (is_array($Token) && $Token[0] === T_VARIABLE) {
            $sName = substr($Token[1], 1);
            if (!in_array($sName, PHP_ALLOWED_NAMES, true) && !preg_match('/^([asidb][A-Z0-9]|[A-Z])/', $sName)) {
                $Report('PHP: variable sin prefijo de tipo ($aLista, $sTexto, $iNumero, $dDecimal, $bBandera; objetos y funciones en PascalCase)', $sFile . ':' . $iLine . ' $' . $sName);
            }
        }
        // Una función guardada en una variable va en PascalCase ($Sender = function…), no con prefijo de tipo.
        if (is_array($Token) && $Token[0] === T_VARIABLE && ($aTokens[$iIndex + 1] ?? null) === '=' && preg_match('/^\$[a-z]/', $Token[1])) {
            $Next = $aTokens[$iIndex + 2] ?? null;
            if (is_array($Next) && $Next[0] === T_STATIC) {
                $Next = $aTokens[$iIndex + 3] ?? null;
            }
            if (is_array($Next) && in_array($Next[0], [T_FUNCTION, T_FN], true)) {
                $Report('PHP: una función guardada en una variable va en PascalCase ($Sender = function…)', $sFile . ':' . $iLine . ' ' . $Token[1]);
            }
        }
        if (is_array($Token) && $Token[0] === T_STRING && in_array(strtolower($Token[1]), ['var_dump', 'print_r', 'var_export', 'dd'], true) && !preg_match('#^(tests|scripts)/|^engine/.*/tools/#', $sFile)) {
            $Report('PHP: restos de depuración', $sFile . ':' . $iLine . ' ' . $Token[1]);
        }
    }
    if (preg_match('/\?>\s*$/', $aCheckedSources[$sFile]) && !str_starts_with($sFile, 'api/Views/')) {
        $Report('PHP: «?>» al final de un archivo de solo PHP', $sFile);
    }
    // Cada clase de api/ en su archivo, con el espacio de nombres de su carpeta (lo que espera api/autoload.php).
    $sClassDir = dirname(substr($sFile, 4));
    if (str_starts_with($sFile, 'api/') && preg_match('#^(Controllers|Core|Models|Services)$#', $sClassDir)) {
        preg_match_all('/^(?:final |abstract )?(?:class|interface|trait|enum) (\w+)/m', $aSources[$sFile], $aMatches);
        if ($aMatches[1] !== [basename($sFile, '.php')] || !str_contains($aSources[$sFile], 'namespace ' . $sClassDir . ';')) {
            $Report('PHP: una clase por archivo, con su nombre y el espacio de nombres de su carpeta', $sFile);
        }
    }
}

// ===== 3. JavaScript =====

foreach ($aJsCode as $sFile => [$sCode]) {
    foreach (explode("\n", $sCode) as $iIndex => $sLine) {
        $sWhere = $sFile . ':' . ($iIndex + 1);
        // Ni ?. ni ?? (ni ??=): lo que queda con «?» es un ternario.
        if (str_contains(preg_replace('/\?\?=?|\?\.(?!\d)/', '', $sLine), '?')) {
            $Report('JavaScript: operador ternario (va con if/else)', $sWhere);
        }
        if (preg_match('/(^|[^=!<>])(==|!=)(?!=)/', $sLine)) {
            $Report('JavaScript: comparación floja (== o !=; van === y !==)', $sWhere);
        }
        if (preg_match('/(^|[^\w$.])var\s/', $sLine)) {
            $Report('JavaScript: «var» (van const y let)', $sWhere);
        }
        if (!$IsTest($sFile) && preg_match('/\bconsole\.(log|debug|info|trace)\b|\bdebugger\b/', $sLine)) {
            $Report('JavaScript: restos de depuración (console.log, debugger)', $sWhere);
        }
    }
}

// ===== 4. Textos en castellano, euskera e inglés =====

$sI18n = $aSources['js/i18n.js'];
preg_match_all('/^ {8}(es|eu|en): \{$/m', $sI18n, $aLangMatches, PREG_OFFSET_CAPTURE);
$aKeys = [];
$aLangStarts = $aLangMatches[1];
foreach ($aLangStarts as $iIndex => [$sLang, $iStart]) {
    $iEnd = strlen($sI18n);
    if (isset($aLangStarts[$iIndex + 1])) {
        $iEnd = $aLangStarts[$iIndex + 1][1];
    }
    preg_match_all("/^ {12}'([^']+)':/m", substr($sI18n, $iStart, $iEnd - $iStart), $aKeyMatches);
    foreach (array_count_values($aKeyMatches[1]) as $sKey => $iTimes) {
        if ($iTimes > 1) {
            $Report('Textos: clave repetida en un idioma (la segunda pisa a la primera)', $sLang . ' ' . $sKey);
        }
    }
    $aKeys[$sLang] = array_unique($aKeyMatches[1]);
}
if (array_keys($aKeys) !== ['es', 'eu', 'en']) {
    $Report('Textos: js/i18n.js tiene que tener los idiomas es, eu y en', 'js/i18n.js');
}
foreach (['eu', 'en'] as $sLang) {
    foreach (array_diff($aKeys['es'] ?? [], $aKeys[$sLang] ?? []) as $sKey) {
        $Report('Textos: falta la traducción', $sLang . ' ' . $sKey);
    }
    foreach (array_diff($aKeys[$sLang] ?? [], $aKeys['es'] ?? []) as $sKey) {
        $Report('Textos: clave que no está en castellano', $sLang . ' ' . $sKey);
    }
}
// Dónde se usan: todo el código salvo el propio diccionario. Una clave se da por usada si aparece entera o si el
// código monta claves con su principio («'app.status.' + estado», `bide.changelog.${n}.title`).
$sUsage = '';
foreach ($aSources as $sFile => $sText) {
    if ($sFile !== 'js/i18n.js' && in_array(pathinfo($sFile, PATHINFO_EXTENSION), ['php', 'js', 'html'], true) && !$IsTest($sFile)) {
        $sUsage .= $sText . "\n";
    }
}
$aDynamicPrefixes = [];
preg_match_all('/[\'"`]((?:[a-z]+\.)+[a-zA-Z0-9]*\.?)(?:[\'"]\s*[+.]|\$\{)/', $sUsage, $aPrefixMatches);
foreach ($aPrefixMatches[1] as $sPrefix) {
    $aDynamicPrefixes[$sPrefix] = true;
}
foreach ($aKeys['es'] ?? [] as $sKey) {
    if (preg_match('/[\'"`=:]' . preg_quote($sKey, '/') . '[\'"`,]/', $sUsage)) {
        continue;
    }
    $bBuilt = false;
    foreach (array_keys($aDynamicPrefixes) as $sPrefix) {
        if (str_starts_with($sKey, $sPrefix)) {
            $bBuilt = true;
        }
    }
    if (!$bBuilt) {
        $Report('Textos: clave que no se usa en ningún sitio', $sKey);
    }
}
$aNamespaces = [];
foreach ($aKeys['es'] ?? [] as $sKey) {
    $aNamespaces[explode('.', $sKey)[0]] = true;
}
preg_match_all('/(?:I18n\.t\(\s*|data-i18n="|data-i18n-attr="[a-z-]+:)\'?((?:' . implode('|', array_keys($aNamespaces)) . ')\.[a-zA-Z0-9_.-]*[a-zA-Z0-9])[\'"]/', $sUsage, $aUsedMatches);
foreach (array_unique($aUsedMatches[1]) as $sKey) {
    if (!in_array($sKey, $aKeys['es'] ?? [], true)) {
        $Report('Textos: se usa una clave que no existe', $sKey);
    }
}

// ===== 5. Código muerto =====

// Dónde puede usarse algo: todo nuestro código (pruebas incluidas) y, si está, el algoritmo, sin comentarios (una
// palabra en un comentario no es un uso) y sin esta batería. Las cadenas cuentan: el router nombra los métodos así.
function withoutPhpComments(string $sCode): string
{
    $sOut = '';
    foreach (token_get_all($sCode) as $Token) {
        if (!is_array($Token)) {
            $sOut .= $Token;
        } elseif (!in_array($Token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $sOut .= $Token[1];
        }
    }
    return $sOut;
}
$sEverything = withoutPhpComments($sEngine);
foreach ($aSources as $sFile => $sText) {
    $sExtension = pathinfo($sFile, PATHINFO_EXTENSION);
    if ($sFile === 'tests/code-quality.php') {
        continue;
    }
    if ($sExtension === 'php') {
        $sEverything .= withoutPhpComments($sText) . "\n";
    } elseif ($sExtension === 'js' || $sExtension === 'mjs') {
        $sEverything .= scanJs($sText, true)[0] . "\n";
    } elseif ($sExtension === 'py' || $sExtension === 'html') {
        $sEverything .= $sText . "\n";
    }
}
$CountWord = fn(string $sWord, string $sText): int => preg_match_all('/(?<![\w$])' . preg_quote($sWord, '/') . '(?![\w$])/', $sText);

foreach ($aJsFiles as $sFile) {
    if ($IsTest($sFile)) {
        continue;
    }
    preg_match_all('/^(export )?(?:async )?function\*? ?(\w+)|^export (?:const|let) (\w+)/m', $aSources[$sFile], $aMatches, PREG_SET_ORDER);
    foreach ($aMatches as $aMatch) {
        $sName = $aMatch[2];
        if ($sName === '') {
            $sName = $aMatch[3];
        }
        if ($CountWord($sName, $sEverything) <= 1) {
            $Report('Código muerto: función de JavaScript que no se usa', $sFile . ' ' . $sName);
        }
    }
}
foreach ($aPhpFiles as $sFile) {
    // Esta batería no entra en el recuento (sus textos hablan de «código muerto»): lo suyo se usa dentro de ella.
    if ($sFile === 'tests/code-quality.php') {
        continue;
    }
    preg_match_all('/^\s*((?:public|private|protected) )?(static )?function (\w+)\s*\(/m', $aSources[$sFile], $aMatches, PREG_SET_ORDER);
    foreach ($aMatches as $aMatch) {
        $sName = $aMatch[3];
        if (str_starts_with($sName, '__')) {
            continue;
        }
        // Sin el algoritmo, un método público puede usarse solo allí: se miran solo los privados.
        if (!$bEngine && trim($aMatch[1]) !== 'private') {
            continue;
        }
        if ($CountWord($sName, $sEverything) <= 1) {
            $Report('Código muerto: función o método de PHP que no se usa', $sFile . ' ' . $sName);
        }
    }
    preg_match_all('/^\s*((?:public|private|protected) )?const (\w+) =/m', $aSources[$sFile], $aMatches, PREG_SET_ORDER);
    foreach ($aMatches as $aMatch) {
        if (!$bEngine && trim($aMatch[1]) !== 'private') {
            continue;
        }
        if ($CountWord($aMatch[2], $sEverything) <= 1) {
            $Report('Código muerto: constante de PHP que no se usa', $sFile . ' ' . $aMatch[2]);
        }
    }
}

// Clases de CSS que nadie pone. Las de cada red se montan con su nombre («is-» + red, «tile--» + red…) y las de
// Leaflet las pone la librería.
$sMarkup = '';
foreach ($aSources as $sFile => $sText) {
    if (in_array(pathinfo($sFile, PATHINFO_EXTENSION), ['php', 'js', 'html'], true) && !$IsTest($sFile)) {
        $sMarkup .= $sText . "\n";
    }
}
foreach ($aCssFiles as $sFile) {
    $sCss = preg_replace('#/\*.*?\*/#s', '', $aSources[$sFile]);
    $sSelectors = preg_replace('/\{[^{}]*\}/', '{}', preg_replace('/url\([^)]*\)/', '', $sCss));
    preg_match_all('/\.(-?[a-zA-Z_][\w-]*)/', $sSelectors, $aMatches);
    foreach (array_unique($aMatches[1]) as $sClass) {
        if (str_starts_with($sClass, 'leaflet-') || $CountWord($sClass, $sMarkup) > 0) {
            continue;
        }
        $bNetwork = false;
        foreach (NETWORK_SLUGS as $sSlug) {
            if (str_ends_with($sClass, '-' . $sSlug) && str_contains($sMarkup, substr($sClass, 0, -strlen($sSlug)))) {
                $bNetwork = true;
            }
        }
        if (!$bNetwork) {
            $Report('Código muerto: clase de CSS que nadie usa', $sFile . ' .' . $sClass);
        }
    }
}

// Scripts y hojas de estilo que no carga nadie.
foreach (array_merge($aJsFiles, $aCssFiles) as $sFile) {
    if ($IsTest($sFile)) {
        continue;
    }
    $bUsed = false;
    foreach ($aSources as $sOther => $sText) {
        if ($sOther !== $sFile && str_contains($sText, basename($sFile))) {
            $bUsed = true;
            break;
        }
    }
    if (!$bUsed) {
        $Report('Código muerto: archivo que no carga nadie', $sFile);
    }
}

// Comentarios y textos del código que nombran un archivo del proyecto que ya no existe: suelen ser restos de cuando se
// movió o se borró. Lo de engine/ solo se mira si el algoritmo está.
foreach ($aSources as $sFile => $sText) {
    if (!in_array(pathinfo($sFile, PATHINFO_EXTENSION), ['php', 'js', 'mjs', 'css', 'py', 'yml'], true)) {
        continue;
    }
    preg_match_all('#(?<![\w./-])((?:api|js|scripts|tests|docs|engine|icons[\w-]*|offline-data|map-data|data)/[\w./-]*\.(?:php|js|mjs|css|py|md|json|sqlite|png|svg|html))(?!\w)#', $sText, $aMatches);
    foreach (array_unique($aMatches[1]) as $sPath) {
        if (str_starts_with($sPath, 'engine/') && !$bEngine) {
            continue;
        }
        if (!file_exists(ROOT . $sPath)) {
            $Report('Código muerto: nombra un archivo que ya no existe', $sFile . ' → ' . $sPath);
        }
    }
}

// ===== 6. Sin conexión: el precache del service worker lleva todo lo que cargan las páginas =====

preg_match('/SHELL_FILES = \[(.*?)\];/s', $aSources['sw.js'], $aShell);
preg_match_all("/^\s*'(\/[^']+)',?$/m", $aShell[1] ?? '', $aShellMatches);
$aPrecached = array_flip($aShellMatches[1]);
$aNeeded = [];
foreach ($aSources as $sFile => $sText) {
    if (str_starts_with($sFile, 'api/Views/') || preg_match('/^[\w-]+\.html$/', $sFile)) {
        preg_match_all('/(?:src|href)="\/?((?:js|lib|fonts)\/[^"?#]+\.(?:js|css)|style-[\w-]+\.css)/', $sText, $aMatches);
        foreach ($aMatches[1] as $sPath) {
            $aNeeded['/' . $sPath] = $sFile;
        }
    }
}
foreach ($aJsFiles as $sFile) {
    // Las herramientas (scripts/, tests/) se lanzan con node, no las carga ninguna página.
    if (str_starts_with($sFile, 'scripts/') || str_starts_with($sFile, 'tests/')) {
        continue;
    }
    preg_match_all('/^import\s[^\'"]*[\'"](\.{1,2}\/[^\'"]+)[\'"]/m', $aSources[$sFile], $aMatches);
    foreach ($aMatches[1] as $sImport) {
        $aParts = [];
        foreach (explode('/', dirname($sFile) . '/' . $sImport) as $sPart) {
            if ($sPart === '..') {
                array_pop($aParts);
            } elseif ($sPart !== '.') {
                $aParts[] = $sPart;
            }
        }
        $aNeeded['/' . implode('/', $aParts)] = $sFile;
    }
}
foreach ($aNeeded as $sPath => $sFrom) {
    if (!isset($aPrecached[$sPath]) && !in_array($sPath, PRECACHE_EXCEPTIONS, true)) {
        $Report('Sin conexión: falta en el precache del service worker (sw.js, SHELL_FILES)', $sPath . ' (lo carga ' . $sFrom . ')');
    }
}

// ===== 7. Forma del PHP: bloques con llaves, pirámide y comentarios cortos =====

// Los tokens que cuentan (sin espacios ni comentarios), cada uno con su línea: [tipo, texto, línea]. Las llaves de
// bloque llevan tipo null; las de las cadenas («{$a}») y las de ->{'x'} llevan T_CURLY_OPEN, para no confundirlas.
function phpTokens(string $sCode): array
{
    $aOut = [];
    $aBraces = [];
    $iLine = 1;
    foreach (token_get_all($sCode) as $Token) {
        if (is_array($Token)) {
            $iLine = $Token[2];
            if (in_array($Token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $aBraces[] = T_CURLY_OPEN;
            }
            if (!in_array($Token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $aOut[] = [$Token[0], $Token[1], $iLine];
            }
            $iLine += substr_count($Token[1], "\n");
            continue;
        }
        $iType = null;
        if ($Token === '{') {
            $iType = null;
            if (in_array(end($aOut)[0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $iType = T_CURLY_OPEN;
            }
            $aBraces[] = $iType;
        } elseif ($Token === '}') {
            $iType = array_pop($aBraces);
        }
        $aOut[] = [$iType, $Token, $iLine];
    }
    return $aOut;
}

// Índice del «)» que cierra el «(» que hay en $iOpen.
function closingParen(array $aTokens, int $iOpen): int
{
    $iDepth = 0;
    for ($i = $iOpen; $i < count($aTokens); $i++) {
        if ($aTokens[$i][1] === '(') {
            $iDepth++;
        } elseif ($aTokens[$i][1] === ')') {
            $iDepth--;
            if ($iDepth === 0) {
                return $i;
            }
        }
    }
    return count($aTokens) - 1;
}

// Las líneas de un archivo PHP que son texto, no código: cadenas de varias líneas, heredoc y HTML de las plantillas.
function phpFreeLines(string $sCode): array
{
    $aFree = [];
    foreach (token_get_all($sCode) as $Token) {
        if (!is_array($Token)) {
            continue;
        }
        if (in_array($Token[0], [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC], true)) {
            for ($iLine = $Token[2] + 1; $iLine <= $Token[2] + substr_count($Token[1], "\n"); $iLine++) {
                $aFree[$iLine] = true;
            }
        }
        if ($Token[0] === T_END_HEREDOC) {
            $aFree[$Token[2]] = true;
        }
    }
    return $aFree;
}

// Con engine/ presente, también sus clases y sus herramientas (no el laboratorio ni la tesis).
$aAllPhp = [];
foreach ($aPhpFiles as $sFile) {
    $aAllPhp[$sFile] = $aSources[$sFile];
}
if ($bEngine) {
    foreach ($aEngineFiles as $sPath) {
        if (!preg_match('#^(lab|docs)/#', $sPath)) {
            $aAllPhp['engine/' . $sPath] = file_get_contents(ROOT . 'engine/' . $sPath);
        }
    }
}
foreach ($aAllPhp as $sFile => $sCode) {
    $bView = str_starts_with($sFile, 'api/Views/');
    $aTokens = phpTokens($sCode);
    foreach ($aTokens as $iIndex => [$iType, $sText, $iLine]) {
        $aNext = $aTokens[$iIndex + 1] ?? null;
        $aPrevious = $aTokens[$iIndex - 1] ?? null;
        $sWhere = $sFile . ':' . $iLine;
        // «{» cierra su línea: lo de dentro va en las siguientes. Se admite el cuerpo vacío «{}».
        if ($sText === '{' && $iType === null && $aNext !== null && $aNext[2] === $iLine && $aNext[1] !== '}') {
            $Report('Forma: código detrás de «{» en la misma línea (el cuerpo va en las líneas siguientes)', $sWhere);
        }
        // «}» abre su línea: nada delante.
        if ($sText === '}' && $iType === null && $aPrevious !== null && $aPrevious[2] === $iLine && $aPrevious[1] !== '{') {
            $Report('Forma: código delante de «}» en la misma línea (la llave cierra sola)', $sWhere);
        }
        // if, elseif, for, foreach, while y else siempre con llaves. En las plantillas vale la sintaxis con «:».
        $bControl = in_array($iType, [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE], true) && ($aNext[1] ?? '') === '(';
        if ($iType === T_WHILE && ($aPrevious[1] ?? '') === '}') {
            $bControl = false;
        }
        if ($bControl) {
            $aAfter = $aTokens[closingParen($aTokens, $iIndex + 1) + 1] ?? null;
            if ($aAfter !== null && $aAfter[1] !== '{' && !($bView && $aAfter[1] === ':')) {
                $Report('Forma: bloque sin llaves (if, for, foreach, while y else van siempre con { })', $sWhere);
            }
        }
        if ($iType === T_ELSE && $aNext !== null && $aNext[1] !== '{' && $aNext[0] !== T_IF && !($bView && $aNext[1] === ':')) {
            $Report('Forma: bloque sin llaves (if, for, foreach, while y else van siempre con { })', $sWhere);
        }
    }

    // Pirámide: como mucho MAX_DEPTH niveles de sangría. Más hondo, se saca una función. Las plantillas llevan HTML.
    if (!$bView) {
        $aFree = phpFreeLines($sCode);
        foreach (explode("\n", $sCode) as $iIndex => $sLine) {
            if (isset($aFree[$iIndex + 1]) || preg_match('/^\s*(\*|\/\/)/', $sLine)) {
                continue;
            }
            if (preg_match('/^( *)\S/', $sLine, $aMatch) && strlen($aMatch[1]) > MAX_DEPTH * 4) {
                $Report('Forma: más de ' . MAX_DEPTH . ' niveles de sangría (saca una función)', $sFile . ':' . ($iIndex + 1));
            }
        }
    }

    // Comentarios cortos: hasta MAX_COMMENT_LINES líneas seguidas y de MAX_COMMENT_WIDTH columnas. La cabecera del
    // archivo (antes de la primera clase o función) no cuenta: explica qué es y cómo se usa.
    $bHeader = true;
    $iRun = 0;
    $iRunStart = 0;
    $iLine = 1;
    foreach (token_get_all($sCode) as $Token) {
        if (!is_array($Token)) {
            $iRun = 0;
            continue;
        }
        $iLine = $Token[2];
        if (in_array($Token[0], [T_CLASS, T_FUNCTION, T_FN, T_VARIABLE, T_CONST, T_IF, T_FOREACH, T_RETURN, T_ECHO, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            $bHeader = false;
        }
        if (!in_array($Token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            $iRun = 0;
            continue;
        }
        if ($Token[0] === T_WHITESPACE) {
            if (substr_count($Token[1], "\n") > 1) {
                $iRun = 0;
            }
            continue;
        }
        if ($iRun === 0) {
            $iRunStart = $iLine;
        }
        $iRun += substr_count(rtrim($Token[1]), "\n") + 1;
        foreach (explode("\n", $Token[1]) as $sCommentLine) {
            if (mb_strlen(rtrim($sCommentLine)) > MAX_COMMENT_WIDTH) {
                $Report('Forma: comentario de más de ' . MAX_COMMENT_WIDTH . ' columnas', $sFile . ':' . $iLine);
                break;
            }
        }
        if ($iRun === MAX_COMMENT_LINES + 1 && !$bHeader) {
            $Report('Forma: comentario de más de ' . MAX_COMMENT_LINES . ' líneas (corto, o al commit)', $sFile . ':' . $iRunStart);
        }
    }
}

// ===== 8. Funciones repetidas =====

// Dos funciones con el mismo cuerpo son una sola. Se comparan los cuerpos sin espacios ni comentarios, y solo los que
// tienen algo que repetir (MIN_BODY_TOKENS tokens o más). Con engine/ presente, también los del algoritmo.
$aPhpBodies = [];
foreach ($aAllPhp as $sFile => $sCode) {
    if ($sFile === 'tests/code-quality.php') {
        continue;
    }
    $aTokens = phpTokens($sCode);
    foreach ($aTokens as $iIndex => [$iType, $sText, $iLine]) {
        if ($iType !== T_FUNCTION || ($aTokens[$iIndex + 1][0] ?? null) !== T_STRING) {
            continue;
        }
        $sName = $aTokens[$iIndex + 1][1];
        $iOpen = $iIndex + 2;
        while ($iOpen < count($aTokens) && $aTokens[$iOpen][1] !== '{' && $aTokens[$iOpen][1] !== ';') {
            $iOpen++;
        }
        if (($aTokens[$iOpen][1] ?? '') !== '{') {
            continue;
        }
        $iDepth = 0;
        $aBody = [];
        for ($i = $iOpen; $i < count($aTokens); $i++) {
            if ($aTokens[$i][1] === '{' && $aTokens[$i][0] === null) {
                $iDepth++;
            } elseif ($aTokens[$i][1] === '}' && $aTokens[$i][0] === null) {
                $iDepth--;
            }
            $aBody[] = $aTokens[$i][1];
            if ($iDepth === 0) {
                break;
            }
        }
        if (count($aBody) < MIN_BODY_TOKENS) {
            continue;
        }
        $sKey = md5(implode(' ', $aBody));
        if (isset($aPhpBodies[$sKey])) {
            $Report('Funciones repetidas: el mismo cuerpo en dos sitios (deja una y llama a esa)', $sFile . ':' . $iLine . ' ' . $sName . ' = ' . $aPhpBodies[$sKey]);
        } else {
            $aPhpBodies[$sKey] = $sFile . ':' . $iLine . ' ' . $sName;
        }
    }
}

// ===== 9. Redes replicables: una red nueva es su ficha en api/Networks y sus iconos =====

$aNetworkFiles = $FilesWith(['php']);
$aOrders = [];
foreach ($aNetworkFiles as $sFile) {
    if (!str_starts_with($sFile, 'api/Networks/')) {
        continue;
    }
    $aNetwork = require ROOT . $sFile;
    $sSlug = basename($sFile, '.php');
    if (($aNetwork['slug'] ?? null) !== $sSlug) {
        $Report('Redes: el slug no coincide con el nombre del archivo', $sFile);
    }
    foreach (['order', 'db', 'kind', 'brand', 'texts', 'seo', 'attribution', 'features', 'gtfs'] as $sKey) {
        if (!isset($aNetwork[$sKey])) {
            $Report('Redes: falta una clave de la ficha', $sFile . ' ' . $sKey);
        }
    }
    $iOrder = $aNetwork['order'] ?? 0;
    if (isset($aOrders[$iOrder])) {
        $Report('Redes: dos redes con el mismo order', $sFile . ' y ' . $aOrders[$iOrder]);
    }
    $aOrders[$iOrder] = $sFile;
    // Los tres idiomas con las mismas claves: lo que falte sale en castellano sin avisar. La atribución en castellano
    // es la clave attribution de la ficha.
    $aLangKeys = [];
    foreach (['es', 'eu', 'en'] as $sLang) {
        $aLangKeys[$sLang] = array_keys($aNetwork['texts'][$sLang] ?? []);
    }
    $aLangKeys['es'][] = 'attribution';
    $aAll = array_unique(array_merge($aLangKeys['es'], $aLangKeys['eu'], $aLangKeys['en']));
    foreach (['es', 'eu', 'en'] as $sLang) {
        foreach (array_diff($aAll, $aLangKeys[$sLang]) as $sKey) {
            $Report('Redes: texto que falta en un idioma', $sFile . ' ' . $sLang . '.' . $sKey);
        }
    }
    foreach (['icon-192.png', 'icon-512.png', 'apple-touch-icon.png'] as $sIcon) {
        if (!is_file(ROOT . ($aNetwork['brand']['icons'] ?? '-') . '/' . $sIcon)) {
            $Report('Redes: falta un icono', $sFile . ' ' . ($aNetwork['brand']['icons'] ?? '?') . '/' . $sIcon);
        }
    }
    if (in_array('vehicle-monitoring', $aNetwork['live'] ?? [], true) && empty($aNetwork['engine'])) {
        $Report('Redes: vehicle-monitoring sin motor (clave engine)', $sFile);
    }
}

// Ninguna red ni motor se nombra en el código: lo que distingue a una red va en su ficha (features, live, engine).
foreach (array_merge($aPhpFiles, $aJsFiles) as $sFile) {
    if ($IsTest($sFile) || str_starts_with($sFile, 'api/Networks/') || in_array($sFile, ['js/networks.js', 'js/i18n.js', 'api/Core/Live.php', 'scripts/new-network.php'], true)) {
        continue;
    }
    $sCode = $aSources[$sFile];
    if (pathinfo($sFile, PATHINFO_EXTENSION) !== 'php') {
        $sCode = scanJs($sCode, true)[0];
    }
    foreach (explode("\n", $sCode) as $iIndex => $sLine) {
        if (preg_match('/(network|slug|red|engine)[\'\]]*\s*[!=]==?\s*[\'"](' . implode('|', NETWORK_SLUGS) . '|' . implode('|', ENGINE_NAMES) . ')[\'"]/i', $sLine)) {
            $Report('Redes: una red o un motor nombrado en el código (va a su ficha en api/Networks)', $sFile . ':' . ($iIndex + 1));
        }
        if (preg_match('/\[\s*[\'"](' . implode('|', ENGINE_NAMES) . ')[\'"]\s*,|^\s*[\'"](' . implode('|', NETWORK_SLUGS) . ')[\'"]\s*=>/', $sLine)) {
            $Report('Redes: una red o un motor nombrado en el código (va a su ficha en api/Networks)', $sFile . ':' . ($iIndex + 1));
        }
    }
}

// ===== Resultado =====

if (empty($aProblems)) {
    echo "OK  formato, PHP, JavaScript, textos, código muerto, precache, forma, funciones repetidas y redes\n";
}
foreach ($aProblems as $sRule => $aWhere) {
    echo 'MAL ' . $sRule . ' (' . count($aWhere) . ")\n";
    foreach (array_slice($aWhere, 0, 25) as $sWhere) {
        echo '      ' . $sWhere . "\n";
    }
    if (count($aWhere) > 25) {
        echo '      … y ' . (count($aWhere) - 25) . " más\n";
    }
}
if (!$bEngine) {
    echo "(sin engine/: los métodos públicos de PHP no se miran, puede usarlos el algoritmo)\n";
}
if (!empty($aProblems)) {
    echo "\nRESULTADO calidad del código: FALLA\n";
    exit(1);
}
echo "\nRESULTADO calidad del código: OK\n";
