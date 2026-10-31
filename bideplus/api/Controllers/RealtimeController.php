<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Http;
use Core\Ids;
use Core\Request;
use Core\Response;
use Core\TripKey;
use Models\LineModel;
use Models\ServiceJourney;
use Services\Calendar;
use Services\GtfsRealtimeClient;
use Core\Live;
use Engine\Bizkaibus\SiriVehicleMonitoringClient;
use Engine\Metro\TripUpdatesMatcher;

// Directo: los autobuses o trenes de una línea en el mapa (/api/lines/{id}/live), la ficha de un viaje
// (/api/vehicles/{tripKey}) y el diagnóstico de la fuente (/api/status). El cálculo de llegadas está en engine/.
class RealtimeController
{
    // Antes de su hora prevista se da la parada por pasada solo si han pasado más de 60 s (sin datos en directo).
    private const PASSED_MARGIN_SECONDS = 60;

    /*
     * Estado de un viaje en su ficha. Con datos en vivo es «live». Sin ellos solo se sabe lo que dice el horario: no se
     * afirma que el bus ya salió (la hora pasada no prueba que haya salido), solo que el viaje terminó si ya pasó su
     * última parada. Si no, se queda en «scheduled» y la app lo muestra como «Solo horario».
     */
    public static function tripStatus(bool $bLive, array $aStops, int $iNowSeconds): string
    {
        if ($bLive) {
            return 'live';
        }
        if (!empty($aStops) && $iNowSeconds > (int)$aStops[count($aStops) - 1]['arrival_seconds']) {
            return 'finished';
        }
        return 'scheduled';
    }

    /*
     * Diagnóstico de la fuente de los buses en directo: pide a Open Data Euskadi desde el servidor (como lo hace la app)
     * y cuenta qué trae. Sirve para ver si el feed responde desde el despliegue y si trae GPS, hora del dato y retraso.
     */
    public function status(Request $Req, array $aParams): void
    {
        $aConfig = Config::current();
        if (($aConfig['engine'] ?? null) !== 'bizkaibus' || !Config::hasLive('vehicle-monitoring')) {
            Response::error('This network has no SIRI feed', 404);
            return;
        }
        $sUrl = $aConfig['siri']['vehicle_monitoring_url'];
        $dStart = microtime(true);
        $aOut = ['network' => $aConfig['slug'], 'source' => parse_url($sUrl, PHP_URL_HOST), 'ok' => false];
        try {
            $sBody = Http::get($sUrl, $aConfig['siri']['http_timeout_seconds']);
            $aOut['bytes'] = \strlen($sBody);
            $aOut += self::feedCounts(SiriVehicleMonitoringClient::parse($sBody));
            $aOut['ok'] = $aOut['vehicles'] > 0;
        } catch (\Throwable $Ex) {
            $aOut['error'] = 'La fuente no respondió';
        }
        $aOut['ms'] = (int)round((microtime(true) - $dStart) * 1000);
        Response::json($aOut);
    }

    // Cuántos buses trae el feed y cuántos con GPS, con hora del dato y con retraso.
    private static function feedCounts(array $aMap): array
    {
        $aCounts = ['vehicles' => 0, 'withGps' => 0, 'withTime' => 0, 'withDelay' => 0];
        foreach ($aMap as $aEntries) {
            foreach ($aEntries as $aEntry) {
                $aCounts['vehicles']++;
                $aCounts['withGps'] += (int)($aEntry['lat'] !== null);
                $aCounts['withTime'] += (int)($aEntry['recordedSeconds'] !== null);
                $aCounts['withDelay'] += (int)($aEntry['delaySeconds'] !== 0);
            }
        }
        return $aCounts;
    }

    // Los autobuses de una línea en el mapa: su recorrido y, por cada bus, la parada por la que va y su retraso.
    public function lineLive(Request $Req, array $aParams): void
    {
        $sLineId = $aParams['id'];
        $Pdo = Database::connection();
        $LineModel = new LineModel($Pdo);
        $aLine = $LineModel->find($sLineId);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        Response::json([
            'line' => $aLine,
            'patterns' => $LineModel->patternsWithStops($sLineId),
            'vehicles' => self::vehiclesOnLine($Pdo, $sLineId),
        ]);
    }

    private static function vehiclesOnLine(\PDO $Pdo, string $sLineId): array
    {
        $aVmMap = Live::vehicles();
        $Matcher = Live::matcher(new ServiceJourney($Pdo), $aVmMap);
        $HeadsignStmt = $Pdo->prepare('
            SELECT jp.headsign
            FROM service_journeys sj
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ?
            LIMIT 1
        ');
        $StopStmt = $Pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id = ?');

        $aVehicles = [];
        foreach ($aVmMap as $sKey => $aEntries) {
            // Las claves del feed son «línea|número de viaje».
            [$sKeyLineId, $sTripNumber] = explode('|', $sKey);
            if ((string)$sKeyLineId !== (string)$sLineId) {
                continue;
            }
            foreach ($aEntries as $aEntry) {
                if ($aEntry['currentStopId'] === null) {
                    continue;
                }
                $StopStmt->execute([$aEntry['currentStopId']]);
                $aStop = $StopStmt->fetch();
                if (!\is_array($aStop)) {
                    continue;
                }
                $HeadsignStmt->execute([$sLineId, $sTripNumber]);
                $sHeadsign = $HeadsignStmt->fetchColumn();
                if ($sHeadsign === false) {
                    $sHeadsign = null;
                }
                $iDelaySeconds = $Matcher->displayDelaySeconds($Matcher->journeyIdFor($aEntry), $aEntry);
                $aVehicles[] = [
                    'vehicleRef' => $aEntry['vehicleRef'],
                    'delayMinutes' => $Matcher->displayDelayMinutes($iDelaySeconds),
                    'headsign' => $sHeadsign,
                    'currentStop' => [
                        'id' => Ids::forOutput($aStop['id']),
                        'name' => $aStop['name'],
                        'lat' => (float)$aStop['lat'],
                        'lon' => (float)$aStop['lon'],
                    ],
                ];
            }
        }
        return $aVehicles;
    }

    // Ficha de un viaje (/api/vehicles/{tripKey}?stopId=): sus paradas con la llegada prevista a cada una, la parada
    // por la que va, su estado y su retraso. En Metro, con las horas del feed; en los autobuses, con el algoritmo.
    public function vehicle(Request $Req, array $aParams): void
    {
        $aTripKey = TripKey::parse($aParams['tripKey']);
        if ($aTripKey === null) {
            Response::error('Invalid vehicle key', 422);
            return;
        }
        $JourneyModel = new ServiceJourney(Database::connection());
        $aVariants = $JourneyModel->variantsByLineAndTrip(...$aTripKey);
        if (empty($aVariants)) {
            Response::error('Trip not found', 404);
            return;
        }
        if (Config::hasLive('trip-updates')) {
            Response::json(self::trainSheet($JourneyModel, $aVariants[0], $Req->query('stopId')));
            return;
        }
        Response::json(self::busSheet($JourneyModel, $aTripKey, $aVariants, $Req->query('stopId')));
    }

    // Lo común a las dos fichas. $aExtra va tras el vehículo (en Metro, feedTimes: las horas son las del feed).
    private static function sheet(array $aJourney, string $sStatus, string|null $sVehicleRef, int $iDelayMinutes, array $aStops, array $aExtra = []): array
    {
        return [
            'lineCode' => $aJourney['line_code'],
            'lineName' => $aJourney['line_name'],
            'headsign' => $aJourney['headsign'],
            'status' => $sStatus,
            'vehicleRef' => $sVehicleRef,
        ] + $aExtra + [
            'delayMinutes' => $iDelayMinutes,
            'stops' => $aStops,
        ];
    }

    // Una parada de la ficha. $iEta es la llegada prevista en segundos desde medianoche (null si ya pasó).
    private static function stopEntry(array $aStop, int|null $iEta, bool $bPast, bool $bCurrent, int $iNow): array
    {
        $iEtaMinutes = null;
        $iEtaAt = null;
        if ($iEta !== null) {
            $iEtaMinutes = (int)round(($iEta - $iNow) / 60);
            $iEtaAt = time() + ($iEta - $iNow);
        }
        return [
            'stopId' => Ids::forOutput($aStop['stop_id']),
            'name' => $aStop['name'],
            'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
            'etaMinutes' => $iEtaMinutes,
            'etaAt' => $iEtaAt,
            'isPast' => $bPast,
            'isCurrent' => $bCurrent,
        ];
    }

    // ===== Autobuses: el algoritmo (engine/) sitúa el bus con su GPS o con la última parada que registró =====

    private static function busSheet(ServiceJourney $JourneyModel, array $aTripKey, array $aVariants, string|null $sFromStopId): array
    {
        $Matcher = Live::matcher($JourneyModel);
        [$aJourney, $aLive] = self::liveVariant($Matcher, $aTripKey, $aVariants);
        $bLive = $Matcher->isLive($aJourney['id'], $aLive);
        $aPosition = $Matcher->positionFor($aJourney['id'], $aLive);
        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();
        $iNextSeqOrder = self::nextSeqOrder($aStops, $aPosition, $aLive, $bLive);

        $aStopsOut = [];
        foreach ($aStops as $aStop) {
            $bPast = self::busPassed($aStop, $aPosition, $aLive, $bLive);
            $iEta = null;
            if (!$bPast) {
                $iEta = (int)$aStop['arrival_seconds'];
            }
            if (!$bPast && $bLive) {
                [$iEta] = $Matcher->etaForStop($aJourney['id'], (int)$aStop['arrival_seconds'], $aLive, (int)$aStop['seq_order']);
            }
            $bCurrent = $iNextSeqOrder !== null && (int)$aStop['seq_order'] === $iNextSeqOrder;
            $aStopsOut[] = self::stopEntry($aStop, $iEta, $bPast, $bCurrent, $iNow);
        }

        $iDelaySeconds = 0;
        $sVehicleRef = null;
        if ($bLive) {
            $iDelaySeconds = self::delaySeconds($Matcher, $aJourney['id'], $aLive, $aStops, $aStopsOut, $sFromStopId);
            $sVehicleRef = $aLive['vehicleRef'] ?? null;
        }
        $sStatus = self::tripStatus($bLive, $aStops, $iNow);
        $iDelayMinutes = $Matcher->displayDelayMinutes($iDelaySeconds);

        // Sin GPS (solo la última parada registrada) es una estimación, como en la tarjeta de la parada: ni «en
        // directo» ni adelanto o retraso.
        if ($sStatus === 'live' && $aPosition === null && \in_array(Config::current()['engine'] ?? null, ['bizkaibus', 'lurraldebus'], true)) {
            $sStatus = 'estimated';
            $iDelayMinutes = 0;
            $sVehicleRef = null;
        }
        return self::sheet($aJourney, $sStatus, $sVehicleRef, $iDelayMinutes, $aStopsOut);
    }

    // Entre las variantes del viaje (calendarios distintos), la que el directo sigue; si ninguna, la que circula hoy.
    private static function liveVariant(object $Matcher, array $aTripKey, array $aVariants): array
    {
        [$sLineId, $sTripNumber, $iFirstDepartureSeconds] = $aTripKey;
        foreach ($aVariants as $aVariant) {
            $aFound = $Matcher->lookup($sLineId, $sTripNumber, $iFirstDepartureSeconds, $aVariant['id']);
            if ($aFound !== null && $Matcher->isLive($aVariant['id'], $aFound)) {
                return [$aVariant, $aFound];
            }
        }
        return [$aVariants[0], null];
    }

    // Con GPS, las paradas de antes de su posición; sin GPS, las de antes de la parada que registró.
    private static function busPassed(array $aStop, array|null $aPosition, array|null $aLive, bool $bLive): bool
    {
        if ($aPosition !== null) {
            return (int)$aStop['arrival_seconds'] < $aPosition['scheduledSeconds'];
        }
        if ($bLive && isset($aLive['order'])) {
            return (int)$aStop['seq_order'] < (int)$aLive['order'];
        }
        return false;
    }

    // Orden de la siguiente parada del bus: por su posición GPS o, sin GPS, la última parada que registró.
    private static function nextSeqOrder(array $aStops, array|null $aPosition, array|null $aLive, bool $bLive): int|null
    {
        foreach ($aStops as $aStop) {
            if ($aPosition !== null && (int)$aStop['arrival_seconds'] >= $aPosition['scheduledSeconds']) {
                return (int)$aStop['seq_order'];
            }
            if ($aPosition === null && $bLive && $aStop['stop_id'] === $aLive['currentStopId']) {
                return (int)$aStop['seq_order'];
            }
        }
        return null;
    }

    // Adelanto o retraso en la siguiente parada del bus. Abierta desde una parada que el bus aún no ha pasado, el de esa
    // parada: el mismo que la tarjeta.
    private static function delaySeconds(object $Matcher, string $sJourneyId, array $aLive, array $aStops, array $aStopsOut, string|null $sFromStopId): int
    {
        foreach ($aStops as $iIndex => $aStop) {
            if ($sFromStopId !== null && (string)Ids::forOutput($aStop['stop_id']) === $sFromStopId && !$aStopsOut[$iIndex]['isPast']) {
                return $Matcher->etaForStop($sJourneyId, (int)$aStop['arrival_seconds'], $aLive, (int)$aStop['seq_order'])[1];
            }
        }
        return $Matcher->displayDelaySeconds($sJourneyId, $aLive);
    }

    // ===== Trenes (Metro Bilbao): el feed GTFS-Realtime da la hora prevista en las paradas que le quedan =====

    /*
     * Se empareja el viaje con el feed como en las salidas de una parada (mismo reparto) y se devuelve el recorrido con
     * esas horas. Las paradas que ya no aparecen en el feed, antes de la primera que sí, ya están pasadas; las que no
     * vienen después de ella llevan el retraso de la primera.
     */
    private static function trainSheet(ServiceJourney $JourneyModel, array $aJourney, string|null $sPreferredStopId): array
    {
        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();
        $aPredicted = self::predictedSeconds($JourneyModel, $aJourney, $aStops, $sPreferredStopId, $iNow);
        $iFirstPredictedOrder = null;
        foreach ($aStops as $aStop) {
            if (isset($aPredicted[(string)$aStop['stop_id']])) {
                $iFirstPredictedOrder = (int)$aStop['seq_order'];
                break;
            }
        }
        $bLive = $iFirstPredictedOrder !== null;

        $iDelaySeconds = 0;
        $bCurrentSet = false;
        $aStopsOut = [];
        foreach ($aStops as $aStop) {
            [$iEta, $bPast] = self::trainEta($aStop, $aPredicted, $bLive, $iFirstPredictedOrder, $iDelaySeconds, $iNow);
            // El retraso del tren es el de la primera parada que le queda según el feed.
            if (!$bPast && !$bCurrentSet && isset($aPredicted[(string)$aStop['stop_id']])) {
                $iDelaySeconds = $iEta - (int)$aStop['arrival_seconds'];
            }
            $bCurrent = !$bPast && !$bCurrentSet;
            $bCurrentSet = $bCurrentSet || $bCurrent;
            $aStopsOut[] = self::stopEntry($aStop, $iEta, $bPast, $bCurrent, $iNow);
        }

        $iDelayMinutes = 0;
        if (abs($iDelaySeconds) >= 60) {
            $iDelayMinutes = (int)round($iDelaySeconds / 60);
        }
        return self::sheet($aJourney, self::tripStatus($bLive, $aStops, $iNow), null, $iDelayMinutes, $aStopsOut, ['feedTimes' => true]);
    }

    // Hora prevista por el feed en cada parada que le queda al tren (segundos desde medianoche), o [] si no se encuentra.
    private static function predictedSeconds(ServiceJourney $JourneyModel, array $aJourney, array $aStops, string|null $sPreferredStopId, int $iNow): array
    {
        $aConfig = Config::current();
        $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        $sAnchorStopId = self::anchorStopId($aStops, $sPreferredStopId, $iNow);
        $TripMatcher = new TripUpdatesMatcher((new GtfsRealtimeClient($aConfig))->fetchTripUpdates(), $JourneyModel, $aConfig['gtfs_rt']['match_tolerance_seconds'], $iMidnight);
        if ($sAnchorStopId === null || !$TripMatcher->hasData()) {
            return [];
        }
        $aRows = $JourneyModel->upcomingAtStop($sAnchorStopId, 40, 3 * 3600);
        foreach ($aRows as $iRow => $aRow) {
            $aRows[$iRow]['status'] = 'scheduled';
        }
        $aFeedStops = $TripMatcher->liveTrip($sAnchorStopId, $aRows, $aJourney['id']);
        $aPredicted = [];
        foreach ($aFeedStops ?? [] as [$sFeedStopId, $iTime]) {
            $aPredicted[$sFeedStopId] = $iTime - $iMidnight;
        }
        return $aPredicted;
    }

    // La parada desde la que se empareja con el feed: la de la ficha si es del recorrido, o la próxima según el horario.
    private static function anchorStopId(array $aStops, string|null $sPreferredStopId, int $iNow): string|null
    {
        $aStopIds = array_map(fn($aStop) => (string)$aStop['stop_id'], $aStops);
        if ($sPreferredStopId !== null && in_array($sPreferredStopId, $aStopIds, true)) {
            return $sPreferredStopId;
        }
        foreach ($aStops as $aStop) {
            if ((int)$aStop['arrival_seconds'] >= $iNow - self::PASSED_MARGIN_SECONDS) {
                return (string)$aStop['stop_id'];
            }
        }
        return null;
    }

    // Llegada prevista a una parada del tren y si ya la ha pasado: [segundos desde medianoche o null, pasada].
    private static function trainEta(array $aStop, array $aPredicted, bool $bLive, int|null $iFirstPredictedOrder, int $iDelaySeconds, int $iNow): array
    {
        $sStopId = (string)$aStop['stop_id'];
        $iScheduled = (int)$aStop['arrival_seconds'];
        if (!$bLive) {
            if ($iScheduled < $iNow - self::PASSED_MARGIN_SECONDS) {
                return [null, true];
            }
            return [$iScheduled, false];
        }
        if (isset($aPredicted[$sStopId])) {
            // El feed mantiene una parada hasta ~110 s después de su hora prevista: el tren ya la ha dejado.
            if ($aPredicted[$sStopId] < $iNow - TripUpdatesMatcher::LIVE_DEPARTED_AFTER_SECONDS) {
                return [null, true];
            }
            return [$aPredicted[$sStopId], false];
        }
        if ((int)$aStop['seq_order'] < $iFirstPredictedOrder) {
            return [null, true];
        }
        return [$iScheduled + $iDelaySeconds, false];
    }
}
