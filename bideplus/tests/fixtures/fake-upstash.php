<?php
// Imita la API HTTPS de Upstash Redis (solo /pipeline con HSET, HMGET y EXPIRE) para tests/store-test.php.
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
$aData = is_file($sFile) ? json_decode(file_get_contents($sFile), true) : [];
$aOut = [];
foreach (json_decode(file_get_contents('php://input'), true) as $aCmd) {
    $sName = strtoupper(array_shift($aCmd));
    $sKey = array_shift($aCmd);
    if ($sName === 'HSET') {
        $iNew = 0;
        for ($i = 0; $i + 1 < count($aCmd); $i += 2) {
            $iNew += isset($aData[$sKey][$aCmd[$i]]) ? 0 : 1;
            $aData[$sKey][$aCmd[$i]] = (string)$aCmd[$i + 1];
        }
        $aOut[] = ['result' => $iNew];
    } elseif ($sName === 'HMGET') {
        $aOut[] = ['result' => array_map(fn($f) => $aData[$sKey][$f] ?? null, $aCmd)];
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
        $aOut[] = ['result' => isset($aData[$sKey]) ? 1 : 0];
    } else {
        $aOut[] = ['error' => 'ERR unknown command'];
    }
}
file_put_contents($sFile, json_encode($aData));
header('Content-Type: application/json');
echo json_encode($aOut);
