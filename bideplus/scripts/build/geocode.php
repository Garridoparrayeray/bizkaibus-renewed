<?php
// Direcciones de las paradas con Nominatim (OpenStreetMap), con caché en geocache.json. Lo usa build-database.php.

declare(strict_types=1);

function geocodeStops(array $aStops, bool $bSkip, ?callable $Lookup = null): array
{
    $Lookup = $Lookup ?? 'reverseGeocode';
    if ($bSkip) {
        foreach ($aStops as &$aStop) {
            $aStop['area'] = '';
        }
        return $aStops;
    }
    $aCache = [];
    if (is_file(GEOCACHE_PATH)) {
        $aCache = json_decode((string)file_get_contents(GEOCACHE_PATH), true);
    }
    if (!is_array($aCache)) {
        $aCache = [];
    }
    $aClusterKeys = [];
    foreach ($aStops as $iId => $aStop) {
        $aClusterKeys[$iId] = round($aStop['lat'], 3) . ',' . round($aStop['lon'], 3);
    }
    $aUniqueKeys = array_unique(array_values($aClusterKeys));
    $aMissing = array_values(array_diff($aUniqueKeys, array_keys($aCache)));
    $aEmpty = array_values(array_filter($aUniqueKeys, fn($sKey) => isset($aCache[$sKey]) && $aCache[$sKey] === ''));
    $aPending = array_merge(
        array_slice($aMissing, 0, GEOCODE_MAX_NEW_LOOKUPS_PER_RUN),
        array_slice($aEmpty, 0, GEOCODE_MAX_EMPTY_RETRIES_PER_RUN)
    );
    echo '  ' . count($aUniqueKeys) . ' unique ~1km clusters, ' . count($aMissing) . ' not yet cached, '
        . count($aEmpty) . ' cached empty; looking up ' . count($aPending) . "\n";

    $iDone = 0;
    $iFailed = 0;
    foreach ($aPending as $iIndex => $sKey) {
        [$sLat, $sLon] = explode(',', $sKey);
        $sArea = $Lookup((float)$sLat, (float)$sLon);
        if ($sArea === null) {
            $iFailed++;
        } else {
            $aCache[$sKey] = $sArea;
        }
        $iDone++;
        if ($iDone % 25 === 0 || $iDone === count($aPending)) {
            echo '    geocoded ' . $iDone . '/' . count($aPending) . "\r";
            file_put_contents(GEOCACHE_PATH, json_encode($aCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if ($iDone < count($aPending) && GEOCODE_PAUSE_MICROSECONDS > 0) {
            usleep(GEOCODE_PAUSE_MICROSECONDS);
        }
    }
    if (!empty($aPending)) {
        echo "\n";
    }
    if ($iFailed > 0) {
        echo "  $iFailed lookups failed and were not cached (they will be retried next run)\n";
    }
    file_put_contents(GEOCACHE_PATH, json_encode($aCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    foreach ($aStops as $iId => &$aStop) {
        $aStop['area'] = $aCache[$aClusterKeys[$iId]] ?? '';
    }
    return $aStops;
}

function reverseGeocode(float $dLat, float $dLon): ?string
{
    $aAddress = nominatimAddress($dLat, $dLon, 16);
    if ($aAddress === null) {
        return null;
    }
    $sArea = formatAddress($aAddress);
    if ($sArea !== '') {
        return $sArea;
    }

    if (GEOCODE_PAUSE_MICROSECONDS > 0) {
        usleep(GEOCODE_PAUSE_MICROSECONDS);
    }
    $aWiderAddress = nominatimAddress($dLat, $dLon, 10);
    if ($aWiderAddress === null) {
        return null;
    }
    return formatAddress($aWiderAddress);
}

function nominatimAddress(float $dLat, float $dLon, int $iZoom): ?array
{
    $sUrl = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $dLat,
        'lon' => $dLon,
        'format' => 'jsonv2',
        'zoom' => $iZoom,
        'addressdetails' => 1,
    ]);
    $Ch = curl_init($sUrl);
    curl_setopt_array($Ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['User-Agent: BizkaiBusPlus-etl/1.0 (' . NOMINATIM_CONTACT . ')'],
    ]);
    $sBody = curl_exec($Ch);
    $iStatus = (int)curl_getinfo($Ch, CURLINFO_HTTP_CODE);
    curl_close($Ch);
    if ($sBody === false || $iStatus !== 200) {
        return null;
    }
    $aData = json_decode($sBody, true);
    if (!is_array($aData)) {
        return null;
    }
    return $aData['address'] ?? [];
}

function formatAddress(array $aAddress): string
{
    $sTownLevel = $aAddress['town'] ?? $aAddress['city'] ?? $aAddress['village'] ?? $aAddress['hamlet'] ?? $aAddress['municipality'] ?? null;
    $aParts = array_filter([$aAddress['neighbourhood'] ?? null, $aAddress['suburb'] ?? null, $sTownLevel]);
    return implode(', ', array_unique($aParts));
}
