<?php
// Lectura de los archivos del GTFS: líneas, paradas, viajes, horas de paso e información del feed. Lo usa
// build-database.php.

declare(strict_types=1);

namespace Build;

use Generator;
use ZipArchive;
use RuntimeException;

class Gtfs
{
    // Accesibilidad en silla de ruedas, como en GTFS (wheelchair_boarding y wheelchair_accessible).
    public const WHEELCHAIR_UNKNOWN = 0;
    public const WHEELCHAIR_YES = 1;
    public const WHEELCHAIR_NO = 2;

    // «Azkoitia(altzibar» -> «Azkoitia (Altzibar)»: espacio antes del paréntesis, paréntesis cerrado y mayúscula
    // inicial en cada palabra que empieza en minúscula (también tras «-» y «(»).
    public static function tidyHeadsign(string $sHeadsign): string
    {
        $sHeadsign = preg_replace('/\s*\(\s*/u', ' (', trim($sHeadsign));
        if (substr_count($sHeadsign, '(') > substr_count($sHeadsign, ')')) {
            $sHeadsign = rtrim($sHeadsign) . ')';
        }
        return preg_replace_callback('/(^|[\s(\-\/])(\p{Ll})/u', fn($aM) => $aM[1] . mb_strtoupper($aM[2]), $sHeadsign);
    }

    // «Uk01-azkoitia>azpeitia>zestoa» -> «Azkoitia - Azpeitia - Zestoa»: sin el código delante y con mayúscula inicial.
    public static function tidyRouteName(string $sName, string $sCode): string
    {
        if ($sCode !== '' && stripos($sName, $sCode . '-') === 0) {
            $sName = substr($sName, strlen($sCode) + 1);
        }
        $aParts = array_map('trim', explode('>', $sName));
        $aParts = array_map(function ($sPart) {
            if ($sPart === mb_strtolower($sPart)) {
                return mb_convert_case($sPart, MB_CASE_TITLE);
            }
            return mb_strtoupper(mb_substr($sPart, 0, 1)) . mb_substr($sPart, 1);
        }, $aParts);
        return implode(' - ', array_filter($aParts, fn($sPart) => $sPart !== ''));
    }

    // La cabecera de un CSV, sin espacios ni la marca BOM que algunos ficheros llevan al principio.
    public static function readCsvHeader($Stream): array
    {
        $aHeader = array_map('trim', fgetcsv($Stream, 0, ',', '"', ''));
        $aHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $aHeader[0]);
        return $aHeader;
    }

    public static function readCsv(ZipArchive $Zip, string $sName, bool $bRequired = true): Generator
    {
        $Stream = $Zip->getStream($sName);
        if ($Stream === false) {
            if ($bRequired) {
                throw new RuntimeException("Could not open $sName from zip");
            }
            return;
        }
        $aHeader = self::readCsvHeader($Stream);
        $iDropped = 0;
        $iRows = 0;
        while (($aRow = fgetcsv($Stream, 0, ',', '"', '')) !== false) {
            if ($aRow === null || $aRow === [null]) {
                continue;
            }
            $iRows++;
            $aRow = self::fitRow($aRow, count($aHeader));
            if ($aRow === null) {
                $iDropped++;
                continue;
            }
            yield array_combine($aHeader, array_map('trim', $aRow));
        }
        fclose($Stream);
        self::reportDropped($sName, $iDropped, $iRows);
    }

    // Una fila con columnas vacías de más (una coma al final, típica de las exportaciones a mano) vale recortada; con
    // cualquier otro número de columnas no se sabe qué valor va en cada una: null.
    public static function fitRow(array $aRow, int $iColumns): array|null
    {
        if (count($aRow) > $iColumns && trim(implode('', array_slice($aRow, $iColumns))) === '') {
            $aRow = array_slice($aRow, 0, $iColumns);
        }
        if (count($aRow) !== $iColumns) {
            return null;
        }
        return $aRow;
    }

    // Las filas descuadradas no se pierden en silencio: siempre un aviso en el registro, y en los ficheros de los que
    // salen los viajes, sus paradas y sus días la construcción se para si son más del 0,1 % (un fichero roto). Una
    // suelta no para el despliegue de todas las redes.
    public const STRICT_FILES = ['stop_times.txt', 'trips.txt', 'calendar.txt', 'calendar_dates.txt'];
    public const MAX_DROPPED_SHARE = 0.001;

    // Un paso de stop_times.txt a una parada que stops.txt no define (casi siempre, la fila de la parada venía descuadrada y
    // se descartó) no se puede mostrar: se quita del viaje, con aviso, y con la misma regla de arriba, si son más del 0,1 %
    // la construcción se para.
    public static function reportUnknownStops(array $aUnknown, int $iRows): void
    {
        $iUnknown = array_sum($aUnknown);
        if ($iUnknown === 0) {
            return;
        }
        arsort($aUnknown);
        $sMessage = "stop_times.txt: $iUnknown de $iRows pasos a paradas que stops.txt no define (" . implode(', ', array_map(fn($sId, $iCount) => "$sId: $iCount", array_keys(array_slice($aUnknown, 0, 5, true)), array_slice($aUnknown, 0, 5, true))) . ')';
        if ($iUnknown > self::MAX_DROPPED_SHARE * $iRows) {
            throw new RuntimeException($sMessage . '. Build abortado.');
        }
        fwrite(STDERR, "AVISO: $sMessage (quitados de sus viajes)\n");
    }

    public static function reportDropped(string $sName, int $iDropped, int $iRows): void
    {
        if ($iDropped === 0) {
            return;
        }
        $sMessage = "$sName: $iDropped de $iRows filas con un número de columnas distinto del de la cabecera";
        if (in_array($sName, self::STRICT_FILES, true) && $iDropped > self::MAX_DROPPED_SHARE * $iRows) {
            throw new RuntimeException($sMessage . '. Build abortado.');
        }
        fwrite(STDERR, "AVISO: $sMessage (descartadas)\n");
    }

    public static function gtfsColor(string|null $sValue): string|null
    {
        $sValue = strtoupper(ltrim(trim((string)$sValue), '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $sValue)) {
            return null;
        }
        return '#' . $sValue;
    }

    // El día del fichero más nuevo del zip (cuando lo exportó el operador; null si no lo dice): no es el día de la
    // compilación, así que dice lo vieja que es una copia de respaldo.
    public static function zipDate(ZipArchive $Zip): string|null
    {
        $iNewest = 0;
        for ($iIndex = 0; $iIndex < $Zip->numFiles; $iIndex++) {
            $iNewest = max($iNewest, (int)($Zip->statIndex($iIndex)['mtime'] ?? 0));
        }
        if ($iNewest === 0) {
            return null;
        }
        return date('Y-m-d', $iNewest);
    }

    public static function gtfsDateToIso(string $sYmd): string
    {
        return substr($sYmd, 0, 4) . '-' . substr($sYmd, 4, 2) . '-' . substr($sYmd, 6, 2);
    }

    public static function loadRoutes(ZipArchive $Zip, string|null $sExpectedAgencyId, array|null $aRouteIdPrefixes = null): array
    {
        $aRoutes = [];
        foreach (self::readCsv($Zip, 'routes.txt') as $aRow) {
            $sAgencyId = '';
            if (isset($aRow['agency_id'])) {
                $sAgencyId = $aRow['agency_id'];
            }
            if ($sExpectedAgencyId !== null && $sAgencyId !== $sExpectedAgencyId) {
                fwrite(STDERR, "  WARNING: skipping route {$aRow['route_id']} with unexpected agency_id \"{$aRow['agency_id']}\"\n");
                continue;
            }
            if ($aRouteIdPrefixes !== null) {
                $bMatchesPrefix = false;
                foreach ($aRouteIdPrefixes as $sPrefix) {
                    if (str_starts_with($aRow['route_id'], $sPrefix)) {
                        $bMatchesPrefix = true;
                        break;
                    }
                }
                if (!$bMatchesPrefix) {
                    continue;
                }
            }
            $sRouteId = $aRow['route_id'];
            $sCode = $aRow['route_short_name'];
            if ($sCode === '') {
                $sCode = $aRow['route_id'];
            }
            $aRoutes[$sRouteId] = [
                'code' => $sCode,
                'name' => trim(preg_replace('/ {2,}/', ' ', $aRow['route_long_name'])),
                // Colores del operador (route_color / route_text_color) para el mapa de la red; null si no los publica.
                'color' => self::gtfsColor($aRow['route_color'] ?? null),
                'textColor' => self::gtfsColor($aRow['route_text_color'] ?? null),
            ];
        }
        return $aRoutes;
    }

    public static function realStopRows(ZipArchive $Zip): Generator
    {
        foreach (self::readCsv($Zip, 'stops.txt') as $aRow) {
            $sLocationType = '';
            if (isset($aRow['location_type'])) {
                $sLocationType = $aRow['location_type'];
            }
            if ($sLocationType !== '' && $sLocationType !== '0') {
                continue;
            }
            yield $aRow;
        }
    }

    public static function loadStopsRenfe(ZipArchive $Zip): array
    {
        $aStops = [];
        foreach (self::realStopRows($Zip) as $aRow) {
            $aStops[$aRow['stop_id']] = [
                'name' => $aRow['stop_name'],
                'lat' => (float)$aRow['stop_lat'],
                'lon' => (float)$aRow['stop_lon'],
            ];
        }
        return $aStops;
    }

    public static function loadStopsBus(ZipArchive $Zip): array
    {
        $aStops = [];
        foreach (self::realStopRows($Zip) as $aRow) {
            $sId = $aRow['stop_id'];
            $sName = $aRow['stop_name'];
            $sStripped = preg_replace('/\s*\(' . preg_quote((string)$sId, '/') . '\)$/', '', $sName);
            if ($sStripped === $sName && is_numeric($sId)) {
                fwrite(STDERR, "  WARNING: stop $sId name \"$sName\" lacked the expected trailing \"($sId)\" suffix\n");
            } else {
                $sName = $sStripped;
            }

            $aStops[$sId] = [
                'name' => $sName,
                'lat' => (float)$aRow['stop_lat'],
                'lon' => (float)$aRow['stop_lon'],
            ];
        }
        return $aStops;
    }

    public static function loadStopsMetro(ZipArchive $Zip): array
    {
        $aStops = [];
        foreach (self::realStopRows($Zip) as $aRow) {
            $sId = self::stripFloatIdSuffix($aRow['stop_id']);
            $aStops[$sId] = [
                'name' => $aRow['stop_name'],
                'lat' => (float)$aRow['stop_lat'],
                'lon' => (float)$aRow['stop_lon'],
            ];
        }
        return $aStops;
    }

    public static function loadStopsEuskotren(ZipArchive $Zip): array
    {
        $aStations = [];
        $aQuaysByParent = [];

        foreach (self::readCsv($Zip, 'stops.txt') as $aRow) {
            $sLocationType = '';
            if (isset($aRow['location_type'])) {
                $sLocationType = $aRow['location_type'];
            }

            if ($sLocationType === '1') {
                $aStations[$aRow['stop_id']] = [
                    'name' => $aRow['stop_name'],
                    'lat' => (float)$aRow['stop_lat'],
                    'lon' => (float)$aRow['stop_lon'],
                ];
                continue;
            }

            if ($sLocationType === '0') {
                $sParentId = '';
                if (isset($aRow['parent_station'])) {
                    $sParentId = $aRow['parent_station'];
                }
                if ($sParentId !== '') {
                    $aQuaysByParent[$sParentId][] = $aRow;
                }
            }
        }

        $aStops = $aStations;
        foreach ($aQuaysByParent as $sParentId => $aQuays) {
            if (!isset($aStations[$sParentId])) {
                continue;
            }

            usort($aQuays, fn($aA, $aB) => $aA['stop_id'] <=> $aB['stop_id']);

            $iIndex = 1;
            foreach ($aQuays as $aQuay) {
                $sLabel = 'Andén ' . $iIndex;
                if (preg_match('/_Q(\d+):$/', $aQuay['stop_id'], $aM)) {
                    $sLabel = 'Andén ' . $aM[1];
                }
                $aStops[$aQuay['stop_id']] = [
                    'name' => $aStations[$sParentId]['name'],
                    'lat' => (float)$aQuay['stop_lat'],
                    'lon' => (float)$aQuay['stop_lon'],
                    'stationId' => $sParentId,
                    'platformLabel' => $sLabel,
                ];
                $iIndex++;
            }
        }

        return $aStops;
    }

    // 1 y 2 de GTFS tal cual; vacío, 0 o cualquier otra cosa es «sin dato».
    public static function wheelchairValue(string|null $sValue): int
    {
        $iValue = (int)$sValue;
        if ($iValue === self::WHEELCHAIR_YES || $iValue === self::WHEELCHAIR_NO) {
            return $iValue;
        }
        return self::WHEELCHAIR_UNKNOWN;
    }

    // Un viaje hecho de varios del GTFS: accesible si lo son todos, no accesible si uno no lo es, y si no, sin dato.
    public static function mergedWheelchair(array $aValues): int
    {
        if (in_array(self::WHEELCHAIR_NO, $aValues, true)) {
            return self::WHEELCHAIR_NO;
        }
        if (array_unique($aValues) === [self::WHEELCHAIR_YES]) {
            return self::WHEELCHAIR_YES;
        }
        return self::WHEELCHAIR_UNKNOWN;
    }

    // wheelchair_boarding de cada parada de stops.txt; como manda GTFS, un andén sin dato toma el de su estación.
    public static function stopWheelchair(ZipArchive $Zip): array
    {
        $aOwn = [];
        $aParent = [];
        foreach (self::readCsv($Zip, 'stops.txt') as $aRow) {
            $sId = self::stripFloatIdSuffix($aRow['stop_id']);
            $aOwn[$sId] = self::wheelchairValue($aRow['wheelchair_boarding'] ?? null);
            $aParent[$sId] = self::stripFloatIdSuffix($aRow['parent_station'] ?? '');
        }
        $aValues = $aOwn;
        foreach ($aOwn as $sId => $iValue) {
            if ($iValue === self::WHEELCHAIR_UNKNOWN && isset($aOwn[$aParent[$sId]])) {
                $aValues[$sId] = $aOwn[$aParent[$sId]];
            }
        }
        return $aValues;
    }

    // Las paradas de un cargador con su wheelchair_boarding (stopWheelchair).
    public static function withWheelchair(ZipArchive $Zip, array $aStops): array
    {
        $aValues = self::stopWheelchair($Zip);
        foreach ($aStops as $Id => $aStop) {
            $aStops[$Id]['wheelchair'] = $aValues[self::stripFloatIdSuffix((string)$Id)] ?? self::WHEELCHAIR_UNKNOWN;
        }
        return $aStops;
    }

    public static function headsignByPattern(string $sPattern, string $sHeadsign): string
    {
        if (preg_match($sPattern, $sHeadsign, $aMatch)) {
            return trim($aMatch[1]);
        }
        return '';
    }

    // Con $sHeadsignPattern, el destino es su primer grupo (Bilbobus pone «PT-Semana / (PLAZA BIRIBILA)»); el rótulo que no
    // encaja se deja vacío y el viaje va hacia su última parada.
    public static function loadTrips(ZipArchive $Zip, string|null $sHeadsignPattern = null): array
    {
        $aTrips = [];
        foreach (self::readCsv($Zip, 'trips.txt') as $aRow) {
            $sTripId = $aRow['trip_id'];
            $sTripNumber = null;
            if (preg_match('/^trp_[A-Za-z]*\d+_(\d+)_/', $sTripId, $aM)) {
                $sTripNumber = $aM[1];
            }
            $sHeadsign = '';
            if (isset($aRow['trip_headsign'])) {
                $sHeadsign = $aRow['trip_headsign'];
            }
            if ($sHeadsignPattern !== null) {
                $sHeadsign = self::headsignByPattern($sHeadsignPattern, $sHeadsign);
            }
            $sShapeId = null;
            if (isset($aRow['shape_id']) && $aRow['shape_id'] !== '') {
                $sShapeId = $aRow['shape_id'];
            }
            $aTrips[$sTripId] = [
                'routeId' => (string)$aRow['route_id'],
                'serviceId' => $aRow['service_id'],
                'headsign' => $sHeadsign,
                'tripNumber' => $sTripNumber,
                'shapeId' => $sShapeId,
                'wheelchair' => self::wheelchairValue($aRow['wheelchair_accessible'] ?? null),
            ];
        }
        return $aTrips;
    }

    public static function stripFloatIdSuffix(string $sId): string
    {
        return preg_replace('/\.0$/', '', $sId);
    }

    public static function streamStopTimesByTrip(ZipArchive $Zip, array|null $aKnownStops = null): Generator
    {
        $Stream = $Zip->getStream('stop_times.txt');
        if ($Stream === false) {
            fwrite(STDERR, "Could not open stop_times.txt stream\n");
            exit(1);
        }
        $aHeader = self::readCsvHeader($Stream);

        $aByTrip = [];

        $iDropped = 0;
        $iRows = 0;
        $aUnknownStops = [];
        while (($aRow = fgetcsv($Stream, 0, ',', '"', '')) !== false) {
            if ($aRow === null || $aRow === [null]) {
                continue;
            }
            $iRows++;
            $aRow = self::fitRow($aRow, count($aHeader));
            if ($aRow === null) {
                $iDropped++;
                continue;
            }
            $aAssoc = array_combine($aHeader, array_map('trim', $aRow));
            $sTripId = $aAssoc['trip_id'];
            $sStopId = self::stripFloatIdSuffix($aAssoc['stop_id']);
            if ($aKnownStops !== null && !isset($aKnownStops[$sStopId])) {
                $aUnknownStops[$sStopId] = ($aUnknownStops[$sStopId] ?? 0) + 1;
                continue;
            }

            $iArrival = null;
            if ($aAssoc['arrival_time'] !== '') {
                $iArrival = self::timeToSeconds($aAssoc['arrival_time']);
            }
            $iDeparture = $iArrival;
            if ($aAssoc['departure_time'] !== '') {
                $iDeparture = self::timeToSeconds($aAssoc['departure_time']);
            }
            if ($iArrival === null) {
                $iArrival = $iDeparture;
            }
            $dDistance = null;
            if (isset($aAssoc['shape_dist_traveled']) && $aAssoc['shape_dist_traveled'] !== '') {
                $dDistance = (float)$aAssoc['shape_dist_traveled'];
            }

            $aByTrip[$sTripId][] = [
                'seqOrder' => (int)$aAssoc['stop_sequence'],
                'stopId' => $sStopId,
                'arrival' => $iArrival,
                'departure' => $iDeparture,
                'distance' => $dDistance,
            ];
        }
        fclose($Stream);
        self::reportDropped('stop_times.txt', $iDropped, $iRows);
        self::reportUnknownStops($aUnknownStops, $iRows);

        foreach ($aByTrip as $sTripId => $aBuffer) {
            yield $sTripId => self::withoutDayJumps($aBuffer);
            unset($aByTrip[$sTripId]);
        }
    }

    // Una hora que salta más de 12 horas sobre la parada anterior es la del día siguiente mal escrita: Bilbobus pone
    // «24:23» en la última parada de un búho que sale a las 00:00. Se le quitan las 24 horas.
    public static function withoutDayJumps(array $aBuffer): array
    {
        usort($aBuffer, fn($aA, $aB) => $aA['seqOrder'] <=> $aB['seqOrder']);
        $iPrevious = null;
        foreach ($aBuffer as &$aRow) {
            foreach (['arrival', 'departure'] as $sKey) {
                if ($aRow[$sKey] !== null && $iPrevious !== null && $aRow[$sKey] - $iPrevious > 12 * 3600) {
                    $aRow[$sKey] -= 86400;
                }
            }
            if ($aRow['departure'] !== null) {
                $iPrevious = $aRow['departure'];
            }
        }
        unset($aRow);
        return $aBuffer;
    }

    public static function timeToSeconds(string $sHms): int
    {
        [$iH, $iM, $iS] = array_map('intval', explode(':', $sHms));
        return $iH * 3600 + $iM * 60 + $iS;
    }

    public static function loadFeedInfo(ZipArchive $Zip): array
    {
        if ($Zip->locateName('feed_info.txt') === false) {
            return [];
        }
        foreach (self::readCsv($Zip, 'feed_info.txt') as $aRow) {
            $sFeedVersion = '';
            if (isset($aRow['feed_version'])) {
                $sFeedVersion = $aRow['feed_version'];
            }
            $sFeedStartDate = '';
            if (isset($aRow['feed_start_date'])) {
                $sFeedStartDate = $aRow['feed_start_date'];
            }
            $sFeedEndDate = '';
            if (isset($aRow['feed_end_date'])) {
                $sFeedEndDate = $aRow['feed_end_date'];
            }
            return [
                'feed_version' => $sFeedVersion,
                'feed_start_date' => $sFeedStartDate,
                'feed_end_date' => $sFeedEndDate,
            ];
        }
        return [];
    }
}
