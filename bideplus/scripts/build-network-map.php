<?php

// Datos del mapa de la red (map/): un JSON por red con sus líneas (color, recorridos y paradas en orden) y un índice.
// Sale de las bases ya construidas, así que se rehace con ellas en cada reconstrucción diaria y no hay que tocarlo a mano.
//
// Uso: php scripts/build-network-map.php [--out=map-data]

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const SIMPLIFY_METERS = 2.0;
const MAX_PATTERNS_PER_LINE = 6;

// Redes que publican varias líneas como una sola (Metro Bilbao: «MB» para L1 y L2). Cada línea del mapa sale del recorrido
// que va de un extremo al otro (en cualquier sentido), con su trazado.
const LINE_SPLITS = [
    'metro' => [
        ['code' => 'L1', 'name' => 'Etxebarri - Plentzia', 'ends' => ['Etxebarri', 'Plentzia']],
        ['code' => 'L2', 'name' => 'Basauri - Kabiezes', 'ends' => ['Basauri', 'Kabiezes']],
    ],
];

// Colores de línea de los planos de cada operador. Mandan sobre los del GTFS porque algunos operadores publican el mismo
// color para todas sus líneas (Euskotren); las líneas que no están aquí usan el del GTFS y, si no hay, el de la red.
const LINE_COLORS = [
    'metro' => ['L1' => '#F26722', 'L2' => '#1D1D1B'],
    'euskotren' => ['E1' => '#0057A6', 'E2' => '#B5BD00', 'E3' => '#4FB3E8', 'E3a' => '#4FB3E8', 'E4' => '#1B3F8B', 'L3' => '#E5007E'],
    'tranvia-bilbao' => ['A' => '#78BE20', 'TR' => '#78BE20'],
];

$sOut = __DIR__ . '/../map-data';
foreach ($argv as $sArg) {
    if (str_starts_with($sArg, '--out=')) {
        $sOut = substr($sArg, 6);
    }
}
if (!is_dir($sOut)) {
    mkdir($sOut, 0775, true);
}

$aIndex = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    if (!is_file($aNetwork['db_path'])) {
        fwrite(STDERR, "  $sSlug: sin base de datos, se omite\n");
        continue;
    }
    $aData = buildNetwork($sSlug, $aNetwork);
    $sJson = json_encode($aData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents("$sOut/$sSlug.json", $sJson);
    $aIndex[] = [
        'slug' => $sSlug,
        'name' => networkLabel($aNetwork, 'es'),
        'nameEu' => networkLabel($aNetwork, 'eu'),
        'kind' => $aNetwork['kind'] ?? null,
        'color' => $aNetwork['brand']['theme_color'] ?? '#555555',
        'lines' => \count($aData['lines']),
        'bytes' => \strlen($sJson),
    ];
    printf("  %s: %d líneas, %d paradas, %.0f KB\n", $sSlug, \count($aData['lines']), \count($aData['stops']), \strlen($sJson) / 1024);
}
file_put_contents("$sOut/index.json", json_encode(['generated' => date('c'), 'networks' => $aIndex], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Nombre de la red en el mapa: el del operador, sin el «+» de la app («Metro+» → «Metro»).
function networkLabel(array $aNetwork, string $sLang): string
{
    if ($aNetwork['slug'] === 'metro') {
        return 'Metro Bilbao';
    }
    $sName = $aNetwork['texts'][$sLang]['name'] ?? $aNetwork['texts']['es']['name'] ?? $aNetwork['slug'];
    return rtrim($sName, '+ ');
}

function buildNetwork(string $sSlug, array $aNetwork): array
{
    $Pdo = new PDO('sqlite:' . $aNetwork['db_path']);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $Pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $aLineColumns = array_column($Pdo->query('PRAGMA table_info(lines)')->fetchAll(), 'name');
    $sColorSelect = 'NULL AS color';
    if (\in_array('color', $aLineColumns, true)) {
        $sColorSelect = 'color';
    }
    $sTextColorSelect = 'NULL AS text_color';
    if (\in_array('text_color', $aLineColumns, true)) {
        $sTextColorSelect = 'text_color';
    }
    $bHasShapes = (bool)$Pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'shapes'")->fetchColumn();
    $aJourneyColumns = array_column($Pdo->query('PRAGMA table_info(service_journeys)')->fetchAll(), 'name');
    $bHasShapeIds = $bHasShapes && \in_array('shape_id', $aJourneyColumns, true);

    $aLines = $Pdo->query("SELECT id, code, name, $sColorSelect, $sTextColorSelect FROM lines ORDER BY code")->fetchAll();

    // Viajes por recorrido: el más usado de cada línea va primero y es el que da el esquema.
    $aPatternTrips = [];
    foreach ($Pdo->query('SELECT journey_pattern_id, COUNT(*) AS n FROM service_journeys GROUP BY journey_pattern_id') as $aRow) {
        $aPatternTrips[$aRow['journey_pattern_id']] = (int)$aRow['n'];
    }
    $aPatternShape = [];
    if ($bHasShapeIds) {
        $sSql = 'SELECT journey_pattern_id, shape_id, COUNT(*) AS n FROM service_journeys WHERE shape_id IS NOT NULL GROUP BY journey_pattern_id, shape_id ORDER BY n DESC';
        foreach ($Pdo->query($sSql) as $aRow) {
            $aPatternShape[$aRow['journey_pattern_id']] ??= $aRow['shape_id'];
        }
    }

    $aPatternsByLine = [];
    foreach ($Pdo->query('SELECT id, line_id, headsign FROM journey_patterns') as $aRow) {
        $aPatternsByLine[$aRow['line_id']][] = $aRow;
    }
    $aPatternStops = [];
    foreach ($Pdo->query('SELECT journey_pattern_id, stop_id FROM journey_pattern_stops ORDER BY journey_pattern_id, seq_order') as $aRow) {
        $aPatternStops[$aRow['journey_pattern_id']][] = (string)$aRow['stop_id'];
    }
    $aStopsAll = [];
    foreach ($Pdo->query('SELECT id, name, lat, lon FROM stops') as $aRow) {
        $aStopsAll[(string)$aRow['id']] = $aRow;
    }
    $ShapeStmt = null;
    if ($bHasShapes) {
        $ShapeStmt = $Pdo->prepare('SELECT points FROM shapes WHERE id = ?');
    }

    $aStopsUsed = [];
    $aOutLines = [];
    if (isset(LINE_SPLITS[$sSlug])) {
        foreach (LINE_SPLITS[$sSlug] as $aSplit) {
            $aBest = null;
            foreach ($aPatternsByLine as $aPatterns) {
                foreach ($aPatterns as $aPattern) {
                    $aStops = $aPatternStops[$aPattern['id']] ?? [];
                    if (\count($aStops) < 2 || !isset($aStopsAll[$aStops[0]], $aStopsAll[end($aStops)])) {
                        continue;
                    }
                    $aEnds = [$aStopsAll[$aStops[0]]['name'], $aStopsAll[end($aStops)]['name']];
                    sort($aEnds);
                    $aWanted = $aSplit['ends'];
                    sort($aWanted);
                    if ($aEnds === $aWanted && ($aBest === null || ($aPatternTrips[$aPattern['id']] ?? 0) > ($aPatternTrips[$aBest['id']] ?? 0))) {
                        $aBest = $aPattern;
                    }
                }
            }
            if ($aBest === null) {
                fwrite(STDERR, "  $sSlug: no hay recorrido {$aSplit['ends'][0]} - {$aSplit['ends'][1]} para {$aSplit['code']}\n");
                continue;
            }
            $aStops = $aPatternStops[$aBest['id']];
            foreach ($aStops as $sId) {
                $aStopsUsed[$sId] = true;
            }
            $aOutLines[] = [
                'id' => $aSplit['code'],
                'code' => $aSplit['code'],
                'name' => $aSplit['name'],
                'color' => LINE_COLORS[$sSlug][$aSplit['code']] ?? ($aNetwork['brand']['theme_color'] ?? '#555555'),
                'textColor' => null,
                'patterns' => [[
                    'headsign' => $aBest['headsign'],
                    'stops' => $aStops,
                    'path' => patternPath($aBest['id'], $aStops, $aStopsAll, $aPatternShape, $ShapeStmt),
                ]],
            ];
        }
        $aLines = [];
    }
    foreach ($aLines as $aLine) {
        $aPatterns = $aPatternsByLine[$aLine['id']] ?? [];
        usort($aPatterns, fn($aA, $aB) => ($aPatternTrips[$aB['id']] ?? 0) <=> ($aPatternTrips[$aA['id']] ?? 0));

        // Se quedan los recorridos que añaden paradas nuevas (ramales, sentidos con paradas propias), no las repeticiones.
        $aCovered = [];
        $aChosen = [];
        foreach ($aPatterns as $aPattern) {
            $aStops = array_values(array_filter($aPatternStops[$aPattern['id']] ?? [], fn($sId) => isset($aStopsAll[$sId])));
            if (\count($aStops) < 2) {
                continue;
            }
            $aNew = array_diff($aStops, array_keys($aCovered));
            if (!empty($aChosen) && \count($aNew) === 0) {
                continue;
            }
            foreach ($aStops as $sId) {
                $aCovered[$sId] = true;
                $aStopsUsed[$sId] = true;
            }
            $aChosen[] = [
                'headsign' => $aPattern['headsign'],
                'stops' => $aStops,
                'path' => patternPath($aPattern['id'], $aStops, $aStopsAll, $aPatternShape, $ShapeStmt),
            ];
            if (\count($aChosen) >= MAX_PATTERNS_PER_LINE) {
                break;
            }
        }
        if (empty($aChosen)) {
            continue;
        }
        $sColor = LINE_COLORS[$sSlug][$aLine['code']] ?? $aLine['color'] ?? ($aNetwork['brand']['theme_color'] ?? '#555555');
        $aOutLines[] = [
            'id' => (string)$aLine['id'],
            'code' => $aLine['code'],
            'name' => $aLine['name'],
            'color' => $sColor,
            'textColor' => $aLine['text_color'] ?? null,
            'patterns' => $aChosen,
        ];
    }

    $aOutLines = mergeDirections($aOutLines);
    foreach ($aOutLines as &$aOutLine) {
        if (isset($aOutLine['ends'])) {
            $aOutLine['name'] = lineNameForEnds($aOutLine['names'], $aStopsAll[$aOutLine['ends'][0]]['name'], $aStopsAll[$aOutLine['ends'][1]]['name']);
            unset($aOutLine['ends']);
        }
        unset($aOutLine['names']);
    }
    unset($aOutLine);

    $aOutStops = [];
    foreach (array_keys($aStopsUsed) as $sId) {
        $aStop = $aStopsAll[$sId];
        $aOutStops[$sId] = [$aStop['name'], round((float)$aStop['lat'], 5), round((float)$aStop['lon'], 5)];
    }

    return ['network' => $sSlug, 'lines' => $aOutLines, 'stops' => $aOutStops];
}

// Algunos operadores publican cada sentido como una línea aparte (Renfe: «Abando -Santurtzi» y «Santurtzi -Abando»). En el
// mapa van juntas: mismo código, mismo color y mismo núcleo (las tres primeras letras del id: 60T Bilbao, 61T Donostia).
function mergeDirections(array $aLines): array
{
    $aGroups = [];
    foreach ($aLines as $aLine) {
        $sKey = $aLine['code'] . '|' . $aLine['color'] . '|' . substr($aLine['id'], 0, 3);
        if (!isset($aGroups[$sKey])) {
            $aGroups[$sKey] = $aLine;
            $aGroups[$sKey]['names'] = [$aLine['name']];
            continue;
        }
        $aGroups[$sKey]['names'][] = $aLine['name'];
        $aCovered = [];
        foreach ($aGroups[$sKey]['patterns'] as $aPattern) {
            foreach ($aPattern['stops'] as $sId) {
                $aCovered[$sId] = true;
            }
        }
        foreach ($aLine['patterns'] as $aPattern) {
            if (\count(array_diff($aPattern['stops'], array_keys($aCovered))) > 0 && \count($aGroups[$sKey]['patterns']) < MAX_PATTERNS_PER_LINE) {
                $aGroups[$sKey]['patterns'][] = $aPattern;
                foreach ($aPattern['stops'] as $sId) {
                    $aCovered[$sId] = true;
                }
            }
        }
        $aGroups[$sKey]['merged'] = true;
    }
    // Una línea unida se llama por los extremos de su recorrido más largo («Irun - Brinkola»).
    foreach ($aGroups as &$aGroup) {
        if (!empty($aGroup['merged'])) {
            $aLongest = $aGroup['patterns'][0];
            foreach ($aGroup['patterns'] as $aPattern) {
                if (\count($aPattern['stops']) > \count($aLongest['stops'])) {
                    $aLongest = $aPattern;
                }
            }
            $aGroup['ends'] = [$aLongest['stops'][0], end($aLongest['stops'])];
        }
        unset($aGroup['merged']);
    }
    unset($aGroup);
    return array_values($aGroups);
}

// Entre los nombres que publica el operador para cada sentido, el que nombra los dos extremos del recorrido más largo
// («Irun -Brincola» para Irun - Brinkola); si ninguno encaja, los nombres de las dos paradas.
function lineNameForEnds(array $aNames, string $sFirst, string $sLast): string
{
    $sBest = null;
    $dBestScore = 0.0;
    foreach ($aNames as $sName) {
        $aSides = array_map('trim', explode('-', $sName, 2));
        if (\count($aSides) < 2 || $aSides[0] === '' || $aSides[1] === '') {
            continue;
        }
        $dScore = max(sideScore($aSides[0], $sFirst) + sideScore($aSides[1], $sLast), sideScore($aSides[0], $sLast) + sideScore($aSides[1], $sFirst));
        if ($dScore > $dBestScore) {
            $dBestScore = $dScore;
            $sBest = $aSides[0] . ' - ' . $aSides[1];
        }
    }
    if ($sBest !== null && $dBestScore >= 1.2) {
        return $sBest;
    }
    return $sFirst . ' - ' . $sLast;
}

function sideScore(string $sSide, string $sStop): float
{
    similar_text(mb_strtolower($sSide), mb_strtolower($sStop), $dPercent);
    $dScore = $dPercent / 100;
    if (str_contains(mb_strtolower($sStop), mb_strtolower($sSide))) {
        $dScore = 1.0;
    }
    return $dScore;
}

// El trazado real si la red lo publica (simplificado); si no, la línea entre paradas.
function patternPath(string $sPatternId, array $aStops, array $aStopsAll, array $aPatternShape, PDOStatement|null $ShapeStmt): array
{
    $aPoints = [];
    if ($ShapeStmt !== null && isset($aPatternShape[$sPatternId])) {
        $ShapeStmt->execute([$aPatternShape[$sPatternId]]);
        $sPoints = $ShapeStmt->fetchColumn();
        if (\is_string($sPoints)) {
            foreach (json_decode($sPoints, true) ?: [] as $aPoint) {
                $aPoints[] = [(float)$aPoint[0], (float)$aPoint[1]];
            }
        }
    }
    if (\count($aPoints) < 2) {
        $aPoints = [];
        foreach ($aStops as $sId) {
            $aPoints[] = [(float)$aStopsAll[$sId]['lat'], (float)$aStopsAll[$sId]['lon']];
        }
    }
    $aSimple = simplify($aPoints, SIMPLIFY_METERS);
    return array_map(fn($aP) => [round($aP[0], 5), round($aP[1], 5)], $aSimple);
}

// Douglas-Peucker en metros (aproximación plana, suficiente a escala de una provincia).
function simplify(array $aPoints, float $dTolerance): array
{
    $iCount = \count($aPoints);
    if ($iCount < 3) {
        return $aPoints;
    }
    $dLat0 = deg2rad($aPoints[0][0]);
    $aXY = array_map(fn($aP) => [$aP[1] * 111320 * cos($dLat0), $aP[0] * 110540], $aPoints);
    $aKeep = array_fill(0, $iCount, false);
    $aKeep[0] = true;
    $aKeep[$iCount - 1] = true;
    $aStack = [[0, $iCount - 1]];
    while ($aStack) {
        [$iA, $iB] = array_pop($aStack);
        $dMax = 0.0;
        $iMax = -1;
        for ($i = $iA + 1; $i < $iB; $i++) {
            $dDist = pointSegmentDistance($aXY[$i], $aXY[$iA], $aXY[$iB]);
            if ($dDist > $dMax) {
                $dMax = $dDist;
                $iMax = $i;
            }
        }
        if ($iMax !== -1 && $dMax > $dTolerance) {
            $aKeep[$iMax] = true;
            $aStack[] = [$iA, $iMax];
            $aStack[] = [$iMax, $iB];
        }
    }
    $aOut = [];
    foreach ($aPoints as $i => $aPoint) {
        if ($aKeep[$i]) {
            $aOut[] = $aPoint;
        }
    }
    return $aOut;
}

function pointSegmentDistance(array $aP, array $aA, array $aB): float
{
    $dDx = $aB[0] - $aA[0];
    $dDy = $aB[1] - $aA[1];
    $dLen = $dDx * $dDx + $dDy * $dDy;
    if ($dLen == 0.0) {
        return hypot($aP[0] - $aA[0], $aP[1] - $aA[1]);
    }
    $dT = max(0.0, min(1.0, (($aP[0] - $aA[0]) * $dDx + ($aP[1] - $aA[1]) * $dDy) / $dLen));
    return hypot($aP[0] - ($aA[0] + $dT * $dDx), $aP[1] - ($aA[1] + $dT * $dDy));
}
