<?php
// Descarga del GTFS de cada red y unión de los GTFS de varios operadores en uno. Lo usa build-database.php.

declare(strict_types=1);

function resolveZipPath(string $sSource, ?string $sFallbackSource = null): string
{
    $AttemptDownload = function(string $sUrl) {
        $sTmp = tempnam(sys_get_temp_dir(), 'bbgtfs') . '.zip';
        $Ch = curl_init($sUrl);
        $Fp = fopen($sTmp, 'wb');

        $aCurlOptions = [
            CURLOPT_FILE => $Fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'BizkaibusMetroApp/1.0',
        ];

        $sNapKey = getenv('NAP_API_KEY');
        if ($sNapKey && str_contains(strtolower($sUrl), 'nap.transportes.gob.es')) {
            $aCurlOptions[CURLOPT_HTTPHEADER] = ['ApiKey: ' . $sNapKey];
        }

        curl_setopt_array($Ch, $aCurlOptions);
        $bOk = curl_exec($Ch);

        $sError = null;
        if ($bOk === false) {
            $sError = curl_error($Ch);
        }

        curl_close($Ch);
        fclose($Fp);

        if ($bOk) {
            return $sTmp;
        } else {
            unlink($sTmp);
            return $sError;
        }
    };

    if (preg_match('#^(https?|ftp)://#i', $sSource)) {
        echo "  -> Downloading from primary source: $sSource\n";
        $sResult = $AttemptDownload($sSource);
        if (is_string($sResult) && file_exists($sResult)) {
            return $sResult;
        }

        fwrite(STDERR, "  -> Primary download failed: $sResult\n");

        if ($sFallbackSource && preg_match('#^(https?|ftp)://#i', $sFallbackSource)) {
            echo "  -> Trying backdoor fallback source: $sFallbackSource\n";
            $sFallbackResult = $AttemptDownload($sFallbackSource);
            if (is_string($sFallbackResult) && file_exists($sFallbackResult)) {
                return $sFallbackResult;
            }
            fwrite(STDERR, "  -> Fallback download failed: $sFallbackResult\n");
        }

        exit(1);
    }

    if (!file_exists($sSource)) {
        fwrite(STDERR, "Source file not found: $sSource\n");
        exit(1);
    }
    return $sSource;
}

/*
 * Junta varios GTFS (un operador cada uno) en un único zip temporal. Líneas, viajes, calendarios, trazados y
 * operadores llevan delante «<clave>:» porque sus ids se repiten entre operadores; las paradas no, porque
 * comparten numeración (la misma parada física tiene el mismo id en todos) y así una parada muestra todas sus líneas.
 * Si un viaje no trae trip_headsign se usa trip_short_name. feed_info queda con la fecha de inicio más temprana
 * y la de fin más tardía.
 */
function mergeGtfsZips(array $aPathsByKey): string
{
    $aPrefixed = [
        'agency.txt' => ['agency_id'],
        'routes.txt' => ['route_id', 'agency_id'],
        'trips.txt' => ['route_id', 'service_id', 'trip_id', 'shape_id', 'block_id'],
        'stop_times.txt' => ['trip_id'],
        'calendar.txt' => ['service_id'],
        'calendar_dates.txt' => ['service_id'],
        'shapes.txt' => ['shape_id'],
        'stops.txt' => [],
    ];
    $aHeaders = [];
    $aRows = [];
    $aSeenStops = [];
    $aFeedStart = [];
    $aFeedEnd = [];
    foreach ($aPathsByKey as $sKey => $sPath) {
        $Zip = new ZipArchive();
        if ($Zip->open($sPath) !== true) {
            fwrite(STDERR, "Could not open zip for $sKey: $sPath\n");
            exit(1);
        }
        foreach ($aPrefixed as $sFile => $aColumns) {
            foreach (readCsv($Zip, $sFile, $sFile !== 'calendar_dates.txt' && $sFile !== 'shapes.txt' && $sFile !== 'calendar.txt') as $aRow) {
                foreach ($aColumns as $sColumn) {
                    if (isset($aRow[$sColumn]) && $aRow[$sColumn] !== '') {
                        $aRow[$sColumn] = $sKey . ':' . $aRow[$sColumn];
                    }
                }
                if ($sFile === 'stops.txt') {
                    if (isset($aSeenStops[$aRow['stop_id']])) {
                        continue;
                    }
                    $aSeenStops[$aRow['stop_id']] = true;
                }
                if ($sFile === 'trips.txt' && ($aRow['trip_headsign'] ?? '') === '' && ($aRow['trip_short_name'] ?? '') !== '') {
                    $aRow['trip_headsign'] = $aRow['trip_short_name'];
                }
                if ($sFile === 'trips.txt' && ($aRow['trip_headsign'] ?? '') !== '') {
                    $aRow['trip_headsign'] = tidyHeadsign($aRow['trip_headsign']);
                }
                if ($sFile === 'routes.txt') {
                    $aRow['route_long_name'] = tidyRouteName($aRow['route_long_name'] ?? '', $aRow['route_short_name'] ?? '');
                }
                foreach (array_keys($aRow) as $sColumn) {
                    $aHeaders[$sFile][$sColumn] = true;
                }
                $aRows[$sFile][] = $aRow;
            }
        }
        foreach (readCsv($Zip, 'feed_info.txt', false) as $aRow) {
            if (!empty($aRow['feed_start_date'])) {
                $aFeedStart[] = $aRow['feed_start_date'];
            }
            if (!empty($aRow['feed_end_date'])) {
                $aFeedEnd[] = $aRow['feed_end_date'];
            }
        }
        $Zip->close();
    }

    $sOut = tempnam(sys_get_temp_dir(), 'bbmerged') . '.zip';
    $Out = new ZipArchive();
    $Out->open($sOut, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($aRows as $sFile => $aFileRows) {
        $aColumns = array_keys($aHeaders[$sFile]);
        $Fp = fopen('php://temp', 'w+');
        fputcsv($Fp, $aColumns, ',', '"', '\\');
        foreach ($aFileRows as $aRow) {
            fputcsv($Fp, array_map(fn($sColumn) => $aRow[$sColumn] ?? '', $aColumns), ',', '"', '\\');
        }
        rewind($Fp);
        $Out->addFromString($sFile, stream_get_contents($Fp));
        fclose($Fp);
    }
    if ($aFeedStart && $aFeedEnd) {
        $Out->addFromString('feed_info.txt', "feed_publisher_name,feed_publisher_url,feed_lang,feed_start_date,feed_end_date,feed_version\n"
            . 'Bide+,https://bideplus.vercel.app,es,' . min($aFeedStart) . ',' . max($aFeedEnd) . ',' . date('YmdHis') . "\n");
    }
    $Out->close();
    foreach ($aRows as $sFile => $aFileRows) {
        echo "  merged $sFile: " . count($aFileRows) . " rows\n";
    }
    return $sOut;
}
