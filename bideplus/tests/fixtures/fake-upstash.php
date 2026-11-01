<?php
// Imita la API HTTPS de Upstash Redis (solo /pipeline) para tests/store-test.php y tests/push-test.php:
// hashes (HSET, HMGET, HSCAN), textos (SET con NX y EX, GET, MGET, DEL), conjuntos (SADD, SREM, SMEMBERS, SCARD,
// SISMEMBER) y EXPIRE.
$sFile = getenv('FAKE_UPSTASH_FILE');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer token-de-prueba') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    return;
}
if (($_SERVER['REQUEST_URI'] ?? '') !== '/pipeline' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    return;
}
$aData = [];
if (is_file($sFile)) {
    $aData = json_decode(file_get_contents($sFile), true);
}
$aOut = [];
foreach (json_decode(file_get_contents('php://input'), true) as $aCmd) {
    $sName = strtoupper(array_shift($aCmd));
    $sKey = array_shift($aCmd);
    if ($sName === 'HSET') {
        $iNew = 0;
        for ($i = 0; $i + 1 < count($aCmd); $i += 2) {
            if (!isset($aData[$sKey][$aCmd[$i]])) {
                $iNew++;
            }
            $aData[$sKey][$aCmd[$i]] = (string)$aCmd[$i + 1];
        }
        $aOut[] = ['result' => $iNew];
    } elseif ($sName === 'HMGET') {
        $aOut[] = ['result' => array_map(fn($sWanted) => $aData[$sKey][$sWanted] ?? null, $aCmd)];
    } elseif ($sName === 'HSCAN') {
        // Sin paginar de verdad: devuelve todo lo que encaja con MATCH en una sola página (cursor 0).
        $sPattern = '*';
        for ($i = 1; $i + 1 < count($aCmd); $i += 2) {
            if (strtoupper($aCmd[$i]) === 'MATCH') {
                $sPattern = $aCmd[$i + 1];
            }
        }
        $aPairs = [];
        foreach ($aData[$sKey] ?? [] as $sField => $sValue) {
            if (fnmatch($sPattern, (string)$sField)) {
                $aPairs[] = (string)$sField;
                $aPairs[] = $sValue;
            }
        }
        $aOut[] = ['result' => ['0', $aPairs]];
    } elseif ($sName === 'EXPIRE') {
        $aOut[] = ['result' => (int)isset($aData[$sKey])];
    } elseif ($sName === 'SET') {
        $aFlags = array_map('strtoupper', array_map('strval', array_slice($aCmd, 1)));
        if (in_array('NX', $aFlags, true) && isset($aData[$sKey])) {
            $aOut[] = ['result' => null];
        } else {
            $aData[$sKey] = ['__str' => (string)$aCmd[0]];
            $aOut[] = ['result' => 'OK'];
        }
    } elseif ($sName === 'GET') {
        $aOut[] = ['result' => $aData[$sKey]['__str'] ?? null];
    } elseif ($sName === 'MGET') {
        $aOut[] = ['result' => array_map(fn($k) => $aData[$k]['__str'] ?? null, array_merge([$sKey], $aCmd))];
    } elseif ($sName === 'DEL') {
        $iDeleted = 0;
        foreach (array_merge([$sKey], $aCmd) as $k) {
            if (isset($aData[$k])) {
                unset($aData[$k]);
                $iDeleted++;
            }
        }
        $aOut[] = ['result' => $iDeleted];
    } elseif ($sName === 'SADD') {
        $aSet = $aData[$sKey]['__set'] ?? [];
        $iAdded = count(array_diff(array_map('strval', $aCmd), $aSet));
        $aData[$sKey] = ['__set' => array_values(array_unique(array_merge($aSet, array_map('strval', $aCmd))))];
        $aOut[] = ['result' => $iAdded];
    } elseif ($sName === 'SREM') {
        $aSet = $aData[$sKey]['__set'] ?? [];
        $iRemoved = count(array_intersect($aSet, array_map('strval', $aCmd)));
        $aData[$sKey] = ['__set' => array_values(array_diff($aSet, array_map('strval', $aCmd)))];
        $aOut[] = ['result' => $iRemoved];
    } elseif ($sName === 'SMEMBERS') {
        $aOut[] = ['result' => $aData[$sKey]['__set'] ?? []];
    } elseif ($sName === 'SISMEMBER') {
        $aOut[] = ['result' => (int)in_array((string)$aCmd[0], $aData[$sKey]['__set'] ?? [], true)];
    } elseif ($sName === 'SCARD') {
        $aOut[] = ['result' => count($aData[$sKey]['__set'] ?? [])];
    } else {
        $aOut[] = ['error' => 'ERR unknown command'];
    }
}
file_put_contents($sFile, json_encode($aData));
header('Content-Type: application/json');
echo json_encode($aOut);
