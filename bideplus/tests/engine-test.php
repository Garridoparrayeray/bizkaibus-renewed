<?php
// Pruebas unitarias del algoritmo (engine/, repositorio aparte): emparejado, capas, ritmo, tramos, SIRI y registro de
// pasos. Sin engine/ a mano no se ejecutan. Uso: php tests/engine-test.php

if (!is_dir(__DIR__ . '/../engine')) {
    echo "SKIP sin engine/: las pruebas del algoritmo no se ejecutan\n";
    exit(0);
}
require __DIR__ . '/unit-harness.php';

use Core\Config;
use Engine\Bizkaibus\FeedMemory;
use Engine\Bizkaibus\PaceFactors;
use Engine\Bizkaibus\PassageLog;
use Engine\Bizkaibus\RealtimeMatcher;
use Engine\Bizkaibus\SegmentTimes;
use Engine\Bizkaibus\SiriVehicleMonitoringClient;
use Engine\Bizkaibus\TripOffsets;
use Models\ServiceJourney;
/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: posición sobre el trazado');

$iGps = T0 + 240;
$aLive = live(1500, 0, 3, $iGps);
$M = matcher($aLive, $iGps + 30);
$aPos = $M->positionFor('J_LINE', $aLive);
$Expect('el GPS a mitad del tramo P2-P3 se sitúa con el trazado', $aPos !== null && $aPos['method'] === 'shape');
$Expect('T_pos se interpola por metros (08:04:30)', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 270, positionText($aPos));
$Expect('el retraso es T_gps - T_pos (-30 s, adelantado)', $M->currentDelaySeconds('J_LINE', $aLive) === -30);

[$iEta, $iDelay, $bPositioned] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('ETA = T_gps + (T_destino - T_pos) con k = 1 (08:11:30)', $iEta === T0 + 690 && $bPositioned, hms($iEta));
$Expect('etaForStop devuelve el mismo retraso', $iDelay === -30);
[$iEta] = $M->etaForStop('J_LINE', T0 + 60, $aLive);
$Expect('una parada ya pasada por el bus cae en el pasado (no sale como llegando ahora)', $iEta < $iGps - 120, hms($iEta));

$Pace = new PaceFactors(['global' => ['laborable.punta_manana' => 0.9]]);
$M = matcher($aLive, $iGps + 30, $Pace);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('k de la franja escala solo el horario restante (08:10:45)', $iEta === T0 + 645, hms($iEta));
$Expect('k no cambia el retraso mostrado', $M->currentDelaySeconds('J_LINE', $aLive) === -30);

$M = matcher($aLive, $iGps + 30, $Pace, 40.0, DATE_WEEKEND);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('un factor de laborable no se aplica en fin de semana', $iEta === T0 + 690, hms($iEta));

/* Tramos aprendidos de las capturas (S1>S2, S2>S3); S3>S4 no tiene datos y usa el horario con k. */
$Segments = new SegmentTimes(['segments' => [
    'S1>S2' => ['n' => 5, 'all' => 60],
    'S2>S3' => ['n' => 5, 'all' => 100],
]]);
$M = matcher($aLive, $iGps + 30, null, 40.0, DATE_WEEKDAY, $Segments);
[$iEta, $iDelay] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('con tiempos por tramo: medio tramo de 60 s + 100 s + 180 s del horario (08:09:10)', $iEta === T0 + 550, hms($iEta));
$Expect('el retraso mostrado es la llegada estimada menos la hora publicada de esa parada (08:09:10 contra 08:12:00)', $iDelay === -170, (string)$iDelay);
// Cabecera y lista dicen lo mismo: el retraso que se enseña es el de la próxima parada, no el de la posición.
$Expect('sin tiempos aprendidos el retraso enseñado coincide con el de la posición (−30 s)', matcher($aLive, $iGps + 30)->displayDelaySeconds('J_LINE', $aLive) === -30);
[, $iDelayNext] = $M->etaForStop('J_LINE', T0 + 360, $aLive, 3);
$Expect('con tiempos aprendidos el retraso de la posición (−30 s) y el de la próxima parada (−90 s) son distintos', $M->currentDelaySeconds('J_LINE', $aLive) === -30 && $iDelayNext === -90, (string)$iDelayNext);
$Expect('el retraso enseñado es el de la próxima parada (−90 s), el mismo de la lista, no el de la posición', $M->displayDelaySeconds('J_LINE', $aLive) === -90, (string)$M->displayDelaySeconds('J_LINE', $aLive));
[$iEta] = $M->etaForStop('J_LINE', T0 + 360, $aLive);
$Expect('con tiempos por tramo solo se suman los tramos hasta la parada pedida (08:04:30)', $iEta === T0 + 270, hms($iEta));

$M = matcher($aLive, $iGps + 30, $Pace, 40.0, DATE_WEEKDAY, $Segments);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('el factor k solo se aplica a los tramos sin datos (08:08:52)', $iEta === T0 + 532, hms($iEta));

$SegmentsBand = new SegmentTimes(['segments' => ['S1>S2' => ['n' => 9, 'all' => 60, 'laborable.punta_manana' => 120]]]);
$Expect('el tiempo de la franja manda sobre el general', $SegmentsBand->seconds('S1', 'S2', 'laborable.punta_manana') === 120.0 && $SegmentsBand->seconds('S1', 'S2', 'laborable.noche') === 60.0);
$Expect('un tramo sin datos no tiene tiempo aprendido', $SegmentsBand->seconds('S2', 'S1', 'laborable.noche') === null);

$aNear = live(1500, 30, 3, $iGps);
$Expect('a 30 m del trazado sigue en ruta', matcher($aNear, $iGps)->positionFor('J_LINE', $aNear) !== null);
$aFar = live(1500, 80, 3, $iGps);
$MFar = matcher($aFar, $iGps);
$Expect('a 80 m del trazado (> 50 m) queda fuera de ruta', $MFar->positionFor('J_LINE', $aFar) === null);
[$iEta, , $bPositioned] = $MFar->etaForStop('J_LINE', T0 + 720, $aFar);
$Expect('fuera de ruta la ETA es el horario + retraso del feed', $iEta === T0 + 720 && !$bPositioned, hms($iEta));
[$iEtaNext] = $MFar->etaForStop('J_LINE', T0 + 360, $aFar, 3);
$Expect('fuera de ruta el retraso mostrado sale de la parada registrada, igual que la ETA de la lista', $MFar->currentDelaySeconds('J_LINE', $aFar) === $iEtaNext - (T0 + 360), (string)$MFar->currentDelaySeconds('J_LINE', $aFar));

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: cuándo no se fía del GPS');

$aStale = live(1500, 0, 3, $iGps);
$Expect('un dato GPS de hace 9 min todavía vale', matcher($aStale, $iGps + 9 * 60)->positionFor('J_LINE', $aStale) !== null);
$Expect('un dato GPS de hace 11 min (> 10) se descarta', matcher($aStale, $iGps + 11 * 60)->positionFor('J_LINE', $aStale) === null);

// Esperando en la primera parada el feed ya apunta a la segunda (orden 2).
$aWaiting = live(20, 0, 2, T0 - 600);
$MWaiting = matcher($aWaiting, T0 - 590);
$aWaitingPos = $MWaiting->positionFor('J_LINE', $aWaiting);
$Expect('un bus que espera en la primera parada antes de su hora ya se sitúa: sale a su hora', $aWaitingPos !== null && $aWaitingPos['method'] === 'origin' && $aWaitingPos['locationSeconds'] === T0, positionText($aWaitingPos));
[$iEta, $iDelay, $bPositioned] = $MWaiting->etaForStop('J_LINE', T0 + 720, $aWaiting);
$Expect('esperando en la primera parada, la llegada sale de su hora de salida y sin retraso (08:12:00)', $iEta === T0 + 720 && $iDelay === 0 && $bPositioned, hms($iEta));
$aFirstRow = ['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0, 'stop_seq' => 1];
$aFirstOut = $MWaiting->enrich([$aFirstRow])[0];
$Expect('en la primera parada sale «en directo» a su hora, no como ya pasado', $aFirstOut['status'] === 'live' && $aFirstOut['etaSeconds'] === T0, $aFirstOut['status'] . ' ' . hms((int)$aFirstOut['etaSeconds']));
$Expect('esperando en la primera parada, la fila lo dice (atOrigin) para enseñarlo «en la parada»', $aFirstOut['atOrigin'] === true);
$aSecondOut = $MWaiting->enrich([['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0 + 180, 'stop_seq' => 2]])[0];
$Expect('en la segunda parada no: el bus no está en ella', $aSecondOut['status'] === 'live' && $aSecondOut['atOrigin'] === false);
$aSameTimeOut = $MWaiting->enrich([['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0, 'stop_seq' => 2]])[0];
$Expect('ni aunque la segunda parada tenga la misma hora que la primera: cuenta el orden, no la hora', $aSameTimeOut['atOrigin'] === false);
$aOnRoute = live(1500, 0, 3, T0 + 240);
$aOnRouteOut = matcher($aOnRoute, T0 + 270)->enrich([['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0 + 720, 'stop_seq' => 5]])[0];
$Expect('un autobús en ruta no está en la cabecera', $aOnRouteOut['status'] === 'live' && $aOnRouteOut['atOrigin'] === false);
$SegmentsLearned = new SegmentTimes(['segments' => ['S0>S1' => ['n' => 9, 'all' => 200], 'S1>S2' => ['n' => 9, 'all' => 200]]]);
$MWaitingLearned = matcher($aWaiting, T0 - 590, null, 40.0, DATE_WEEKDAY, $SegmentsLearned);
[$iEta, , $bPositioned] = $MWaitingLearned->etaForStop('J_LINE', T0 + 360, $aWaiting, 3);
$Expect('con tramos aprendidos, esperando en la primera parada la llegada es la de la capa 3: salida + 1 min + tramos (08:07:40)', $iEta === T0 + 460 && $bPositioned, hms($iEta));
$aAway = live(600, 0, 2, T0 - 600);
$Expect('antes de su hora y lejos de la primera parada (600 m) no se sitúa: puede estar acabando otro viaje', matcher($aAway, T0 - 590)->positionFor('J_LINE', $aAway) === null);
$aTooEarly = live(0, 0, 2, T0 - 61 * 60);
$Expect('más de una hora antes de su salida no se sitúa', matcher($aTooEarly, T0 - 61 * 60)->positionFor('J_LINE', $aTooEarly) === null);
$aJustBefore = live(0, 0, 1, T0 - 30);
$Expect('30 s antes de la salida sí se sitúa (margen de 60 s)', matcher($aJustBefore, T0)->positionFor('J_LINE', $aJustBefore) !== null);

$aBadOrder = live(1500, 0, 42, $iGps);
$Expect('una próxima parada que no existe en el viaje descarta el GPS', matcher($aBadOrder, $iGps)->positionFor('J_LINE', $aBadOrder) === null);

$aWindow = live(500, 0, 5, $iGps);
$Expect('fuera de la ventana (3 tramos atrás) no se proyecta aunque esté sobre la ruta', matcher($aWindow, $iGps)->positionFor('J_LINE', $aWindow) === null);

// Bizkaibus: sin GPS utilizable (no lo manda o está fuera de la ruta) se muestra el horario oficial, sin estimar nada.
$aNoGps = live(1500, 0, 3, $iGps);
$aNoGps['lat'] = null;
$aNoGps['lon'] = null;
$aNoGps['locationSeconds'] = null;
$aNoGps['delaySeconds'] = 60;
$MNoGps = matcher($aNoGps, T0 + 300);
$Expect('Bizkaibus sin GPS: no cuenta como en vivo', !$MNoGps->isLive('J_LINE', $aNoGps));
[$iEta, $iDelay, $bPositioned] = $MNoGps->etaForStop('J_LINE', T0 + 720, $aNoGps);
$Expect('Bizkaibus sin GPS: la ETA es la hora oficial y no hay retraso', $iEta === T0 + 720 && $iDelay === 0 && !$bPositioned, hms($iEta));
$Expect('Bizkaibus sin GPS: el retraso mostrado es 0 aunque el feed diga otra cosa', $MNoGps->currentDelaySeconds('J_LINE', $aNoGps) === 0);
$aRowOfficial = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720];
$aOfficial = $MNoGps->enrich([$aRowOfficial])[0];
$Expect('enrich: sin GPS la salida sale programada con su hora oficial', $aOfficial['status'] === 'scheduled' && $aOfficial['etaSeconds'] === T0 + 720 && $aOfficial['delaySeconds'] === 0, json_encode([$aOfficial['status'], $aOfficial['etaSeconds']]));
$aOffRouteBus = live(1500, 200, 3, $iGps);
$aOffRouteNoClock = $aOffRouteBus;
$aOffRouteNoClock['locationSeconds'] = null;
$Expect('Bizkaibus con GPS fuera de la ruta y sin hora del dato: horario oficial', !matcher($aOffRouteNoClock, $iGps + 30)->isLive('J_LINE', $aOffRouteNoClock) && matcher($aOffRouteNoClock, $iGps + 30)->enrich([$aRowOfficial])[0]['status'] === 'scheduled');
$Expect('Bizkaibus con GPS en ruta: en directo', matcher($aLive, $iGps + 30)->enrich([$aRowOfficial])[0]['status'] === 'live');

// Si el cálculo con GPS falla por lo que sea (base de datos rota, dato imposible), la salida cae al horario oficial en
// vez de romper la parada.
$PdoBroken = new PDO('sqlite::memory:');
$PdoBroken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoBroken->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$MBroken = new RealtimeMatcher(['3414|808' => [$aLive]], new ServiceJourney($PdoBroken), $iGps + 30, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aBrokenOut = $MBroken->enrich([$aRowOfficial])[0];
$Expect('si el cálculo con GPS falla, la salida sale con el horario oficial', $aBrokenOut['status'] === 'scheduled' && $aBrokenOut['etaSeconds'] === T0 + 720 && $aBrokenOut['delaySeconds'] === 0, json_encode([$aBrokenOut['status'], $aBrokenOut['etaSeconds']]));
$Expect('si el cálculo con GPS falla, ni la posición ni el retraso rompen nada', $MBroken->positionFor('J_LINE', $aLive) === null && $MBroken->currentDelaySeconds('J_LINE', $aLive) === 0 && $MBroken->etaForStop('J_LINE', T0 + 720, $aLive) === [T0 + 720, 0, false]);

/* Otras redes con SIRI (Euskotren, tranvías) no cambian: sin GPS siguen en directo con el retraso del feed. */
Config::set('euskotren');
$MOtherNetwork = matcher($aNoGps, T0 + 300);
$Expect('en otra red, sin GPS, sigue en directo', $MOtherNetwork->isLive('J_LINE', $aNoGps));
[$iEta] = $MOtherNetwork->etaForStop('J_LINE', T0 + 720, $aNoGps);
$Expect('en otra red, sin GPS, la ETA es la de siempre (ahora + horario desde la próxima parada)', $iEta === T0 + 300 + 360, hms($iEta));
Config::set('bus');

$Expect('sin datos en vivo la ETA es el horario', matcher($aLive, $iGps)->etaForStop('J_LINE', T0 + 720, null) === [T0 + 720, 0, false]);

/* ------------------------------------------------------------------ */
$Section('Bizkaibus: fuente de los buses en directo');
$aBusConfig = \Core\Networks::get('bus');
$Expect('los buses en directo salen solo del feed GPS SIRI de Open Data Bizkaia (posicion y hora del dato), sin el feed de trip-updates', str_starts_with($aBusConfig['siri']['vehicle_monitoring_url'], 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions') && !isset($aBusConfig['siri']['vehicle_monitoring_fallback_url']));

$Section('Lurraldebus: lectura del feed en directo');
$sLurSiri = '<?xml version="1.0"?><Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery>'
    . '<VehicleActivity><RecordedAtTime>' . DATE_WEEKDAY . 'T08:30:10+02:00</RecordedAtTime><MonitoredVehicleJourney><LineRef>7</LineRef>'
    . '<FramedVehicleJourneyRef><DataFrameRef>' . DATE_WEEKDAY . '</DataFrameRef><DatedVehicleJourneyRef>7_702_LJ_29700</DatedVehicleJourneyRef></FramedVehicleJourneyRef>'
    . '<OriginAimedDepartureTime>' . DATE_WEEKDAY . 'T08:15:00+02:00</OriginAimedDepartureTime>'
    . '<VehicleLocation><Longitude>-1.98</Longitude><Latitude>43.31</Latitude></VehicleLocation><VehicleRef>412</VehicleRef>'
    . '<MonitoredCall><StopPointRef>130</StopPointRef><Order>5</Order></MonitoredCall></MonitoredVehicleJourney></VehicleActivity>'
    . '</VehicleMonitoringDelivery></ServiceDelivery></Siri>';
$aLur = \Engine\Lurraldebus\Live::parseSnapshot($sLurSiri, 'pesa', DATE_WEEKDAY);
$aLurEntry = $aLur['pesa:7|pesa:7_702_LJ_29700'][0] ?? null;
$Expect('el viaje lleva el prefijo del operador, como en la base de datos', $aLurEntry !== null && $aLurEntry['tripRef'] === 'pesa:7_702_LJ_29700' && $aLurEntry['lineId'] === 'pesa:7');
$Expect('posición, parada, orden y hora del dato', $aLurEntry !== null && $aLurEntry['lat'] === 43.31 && $aLurEntry['currentStopId'] === '130' && $aLurEntry['order'] === 5 && $aLurEntry['locationSeconds'] === 8 * 3600 + 30 * 60 + 10);
$Expect('salida de cabecera desde OriginAimedDepartureTime', $aLurEntry !== null && $aLurEntry['departureSeconds'] === 8 * 3600 + 15 * 60);
$Expect('un feed vacío no trae buses', \Engine\Lurraldebus\Live::parseSnapshot('<Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery/></ServiceDelivery></Siri>', 'pesa', DATE_WEEKDAY) === []);
$Expect('motores separados: Lurraldebus no lee los datos aprendidos de Bizkaibus', (new \ReflectionClassConstant(\Engine\Lurraldebus\SegmentTimes::class, 'FILE_PATH'))->getValue() !== (new \ReflectionClassConstant(\Engine\Bizkaibus\SegmentTimes::class, 'FILE_PATH'))->getValue());

$Section('RealtimeMatcher: capa 2, parada registrada sin GPS utilizable');

/* J_LINE: paradas seq 1..5 con llegadas T0, +180, +360, +540, +720. El feed da la parada 3 como próxima. */
$aRegistered = live(1500, 0, 3, $iGps);
$aRegistered['lat'] = null;
$aRegistered['lon'] = null;
$aRegistered['locationSeconds'] = null;
$aRegistered['recordedSeconds'] = T0 + 250;
$MReg = matcher($aRegistered, T0 + 300);
$Expect('sin GPS pero con parada y hora del dato, el bus cuenta como en vivo', $MReg->isLive('J_LINE', $aRegistered));
[$iEta, $iDelay, $bPositioned] = $MReg->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$Expect('la ETA parte de la hora del dato, a mitad del tramo hasta la próxima, más lo que queda (08:11:40)', $iEta === T0 + 700 && !$bPositioned, hms($iEta));
$Expect('el retraso mostrado es la ETA menos el horario de la parada', $iDelay === -20);
$Expect('el retraso del recorrido sale de la parada registrada y no del campo Delay del feed', $MReg->currentDelaySeconds('J_LINE', array_merge($aRegistered, ['delaySeconds' => 720])) === -20);
[$iEta] = $MReg->etaForStop('J_LINE', T0 + 360, $aRegistered, 3);
$Expect('para la propia próxima parada solo cuenta la mitad del tramo (08:05:40)', $iEta === T0 + 340, hms($iEta));
$MRegFast = matcher($aRegistered, T0 + 300, new PaceFactors(['global' => ['laborable.punta_manana' => 0.5]]));
[$iEtaFar, $iDelayFar] = $MRegFast->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$Expect('con parada registrada, el retraso mostrado de una parada es su llegada estimada menos su hora publicada', $iDelayFar === $iEtaFar - (T0 + 720), json_encode([$iEtaFar - (T0 + 720), $iDelayFar]));
$aRowFarEarly = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720 + 3 * 3600, 'stop_seq' => 5];
$aFarOut = $MRegFast->enrich([$aRowFarEarly])[0];
$Expect('si la llegada estimada difiere más de 45 min de la hora publicada, sale estimada con su llegada estimada y sin afirmar retraso', $aFarOut['status'] === 'estimated' && $aFarOut['delaySeconds'] === 0 && $aFarOut['etaSeconds'] < T0 + 720 + 3 * 3600, json_encode([$aFarOut['status'], $aFarOut['delaySeconds']]));
[$iEta, , ] = $MReg->etaForStop('J_LINE', T0 + 720, $aRegistered);
$Expect('sin número de parada no se estima: horario oficial', $iEta === T0 + 720, hms($iEta));

$aRowPassed = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 180, 'stop_seq' => 2];
$aRowTarget = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5];
$aEnriched = $MReg->enrich([$aRowPassed, $aRowTarget]);
$Expect('enrich: la fila de una parada ya pasada sale como salida, con la hora estimada en que el bus la pasó (la de la parada registrada)', $aEnriched[0]['status'] === 'departed' && $aEnriched[0]['etaSeconds'] === T0 + 250, json_encode($aEnriched[0]['etaSeconds']));
$Expect('enrich: sin GPS, la fila de una parada posterior sale estimada (no en directo) con la ETA de la parada registrada y sin adelanto ni retraso', $aEnriched[1]['status'] === 'estimated' && $aEnriched[1]['etaSeconds'] === T0 + 700 && $aEnriched[1]['delaySeconds'] === 0, json_encode([$aEnriched[1]['status'], $aEnriched[1]['etaSeconds']]));

$aWaiting = $aRegistered;
$aWaiting['recordedSeconds'] = T0 - 120;
$Expect('un bus que espera en cabecera (viaje sin empezar) no cuenta como en vivo', !matcher($aWaiting, T0 - 100)->isLive('J_LINE', $aWaiting));
$Expect('un dato de hace más de 10 min no sirve: horario oficial', !matcher($aRegistered, T0 + 250 + 11 * 60)->isLive('J_LINE', $aRegistered));
$aNoClock = $aRegistered;
$aNoClock['recordedSeconds'] = null;
$Expect('sin hora del dato no se estima nada: horario oficial', !matcher($aNoClock, T0 + 300)->isLive('J_LINE', $aNoClock));

$aOffRoute = live(1500, 200, 3, $iGps);
$aOffRoute['recordedSeconds'] = $iGps;
$MOff = matcher($aOffRoute, $iGps + 30);
$Expect('con GPS fuera de la ruta se usa la parada registrada', $MOff->isLive('J_LINE', $aOffRoute) && $MOff->enrich([$aRowPassed])[0]['status'] === 'departed');
[$iEta] = $MOff->etaForStop('J_LINE', T0 + 720, $aOffRoute, 5);
$Expect('con GPS fuera de la ruta la ETA sale de la parada registrada (08:12:00)', $iEta === $iGps + 90 + 360, hms($iEta));
$aOnRoute = live(1500, 0, 3, $iGps);
$Expect('con GPS en ruta manda el GPS y no se descarta nada por el orden del feed', matcher($aOnRoute, $iGps + 30)->enrich([$aRowTarget])[0]['status'] === 'live');

/* Con GPS: en cuanto el bus pasa una parada (el feed ya marca la siguiente), esa salida se retira al momento. */
$aGpsPassed = live(1500, 0, 3, $iGps);
$MGpsPassed = matcher($aGpsPassed, $iGps + 30);
$aRowStop2 = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 180, 'stop_seq' => 2];
$aRowStop3 = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 360, 'stop_seq' => 3];
$aOutPassed = $MGpsPassed->enrich([$aRowStop2, $aRowStop3]);
$Expect('con GPS, la parada que el bus ya pasó sale como salida, con la hora en que el bus estuvo en ella (08:02:30)', $aOutPassed[0]['status'] === 'departed' && $aOutPassed[0]['etaSeconds'] === T0 + 150, json_encode([$aOutPassed[0]['status'], $aOutPassed[0]['etaSeconds']]));
$Expect('con GPS, la próxima parada sigue en directo', $aOutPassed[1]['status'] === 'live', $aOutPassed[1]['status']);

/* Tramo largo (cabecera, espera): la parada registrada no dice dónde va el bus, así que no se estima. */
$aLongSegment = $aRegistered;
$aLongSegment['order'] = 3;
$MLong = matcher($aLongSegment, T0 + 300);
$Expect('tramos de 3 min sí se estiman', $MLong->isLive('J_LINE', $aLongSegment));
$aBigGap = array_merge($aRegistered, ['order' => 5, 'recordedSeconds' => T0 + 600]);
$Expect('con tramo hasta la próxima parada de 3 min (orden 5) se estima', matcher($aBigGap, T0 + 620)->isLive('J_LINE', $aBigGap));

// Capa 3: sin ningún dato del bus (ni GPS ni registro de pasos), la hora se estima con los tiempos aprendidos (salida
// de cabecera + lo que tardan los buses) y sale como estimada («estimated»): la app enseña al lado la hora oficial.
$SegLearned = new SegmentTimes(['segments' => [
    'S0>S1' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S1>S2' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S2>S3' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S3>S4' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
]]);
$aRowNoBus = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5];
$MNoBus = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$aNoBus = $MNoBus->enrich([$aRowNoBus])[0];
$Expect('sin datos del bus, la llegada es la salida de cabecera + tiempos aprendidos + 1 min (08:07:40), marcada como estimada', $aNoBus['status'] === 'estimated' && $aNoBus['etaSeconds'] === T0 + 400 + 60 && $aNoBus['delaySeconds'] === 0, json_encode([$aNoBus['status'], hms($aNoBus['etaSeconds'])]));
$MNoBusLate = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 500, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$aNoBusLate = $MNoBusLate->enrich([$aRowNoBus])[0];
$Expect('si lo estimado ya queda en el pasado, se deja el horario publicado (no se esconde ni se deja en 0 min)', $aNoBusLate['status'] === 'scheduled' && $aNoBusLate['etaSeconds'] === T0 + 720);
// Horario mucho más lento que los buses (A3514 por San Juan, Amorebieta: 59 min en horario, 28 de verdad). J_SLOW
// hace el mismo recorrido con 15 min por tramo: con los tiempos aprendidos el bus ya pasó. No se esconde (es una
// estimación): sale como «probablemente ya pasó» con su hora oficial, y la app lo pone al final y en gris.
$PdoSlow = buildFixture(40);
$PdoSlow->exec("INSERT INTO service_journeys VALUES ('J_SLOW', '3414', 'P1', '808', 'C1', " . T0 . ", 'SH_LINE')");
for ($i = 0; $i < 5; $i++) {
    $PdoSlow->exec("INSERT INTO passing_times VALUES ('J_SLOW', " . ($i + 1) . ", 'S$i', " . (T0 + $i * 900) . ', ' . (T0 + $i * 900) . ', ' . ($i * 1000.0) . ')');
}
$aRowSlow = array_merge($aRowNoBus, ['service_journey_id' => 'J_SLOW', 'arrival_seconds' => T0 + 3600]);
$aSlowPassed = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 3000, new PaceFactors(), DATE_WEEKDAY, $SegLearned))->enrich([$aRowSlow])[0];
$Expect('horario mucho más lento que lo aprendido y el bus ya debió pasar: sale como «probablemente ya pasó», con la hora oficial', $aSlowPassed['status'] === 'likely_departed' && $aSlowPassed['etaSeconds'] === T0 + 3600, json_encode([$aSlowPassed['status'], hms($aSlowPassed['etaSeconds'])]));
$aSlowSoon = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 300, new PaceFactors(), DATE_WEEKDAY, $SegLearned))->enrich([$aRowSlow])[0];
$Expect('mismo viaje antes de la hora estimada: sale estimado, con la hora estimada', $aSlowSoon['status'] === 'estimated' && $aSlowSoon['etaSeconds'] === T0 + 460);
$aSlowJustPassed = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 1000, new PaceFactors(), DATE_WEEKDAY, $SegLearned))->enrich([$aRowSlow])[0];
$Expect('si la hora estimada pasó hace menos de 10 min (el bus puede ir con retraso), se deja el horario publicado', $aSlowJustPassed['status'] === 'scheduled' && $aSlowJustPassed['etaSeconds'] === T0 + 3600);
$aSlowUnlearned = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 3000, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => ['S0>S1' => ['n' => 5, 'all' => 100]], 'lengthRatios' => ['3' => ['all' => 0.1]]])))->enrich([$aRowSlow])[0];
$Expect('si casi ningún tramo tiene tiempo aprendido, no se esconde el viaje', $aSlowUnlearned['status'] === 'scheduled' && $aSlowUnlearned['etaSeconds'] === T0 + 3600, json_encode([$aSlowUnlearned['status'], hms($aSlowUnlearned['etaSeconds'])]));
$MNoLearned = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$Expect('sin tiempos aprendidos, se deja el horario publicado', $MNoLearned->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
// Tramos sin tiempo aprendido: la proporción tiempo real / horario depende del largo del tramo (en la fixture, 1000 m
// por tramo y 180 s de horario).
$SegOne = ['S0>S1' => ['n' => 5, 'all' => 100]];
$MNoRatio = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne]));
$Expect('sin proporciones por largo, un tramo sin aprender usa k x horario (08:11:40)', $MNoRatio->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 180, (string)$MNoRatio->enrich([$aRowNoBus])[0]['etaSeconds']);
$MRatio = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne, 'lengthRatios' => ['3' => ['all' => 0.5]]]));
$Expect('con proporciones por largo, el tramo sin aprender de 1000 m usa la de su cubo (0,5 x 180 s = 90 s)', $MRatio->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 90, (string)$MRatio->enrich([$aRowNoBus])[0]['etaSeconds']);
$MRatioOther = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne, 'lengthRatios' => ['0' => ['all' => 0.5]]]));
$Expect('si el cubo de su largo no tiene proporción, se vuelve a k x horario', $MRatioOther->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 180);
$MRatioNoShape = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne, 'lengthRatios' => ['3' => ['all' => 0.5]]]));
$aRowNoShape = array_merge($aRowNoBus, ['service_journey_id' => 'J_NOSHAPE', 'trip_number' => '809']);
$Expect('sin largo del tramo (viaje sin dist_m) se usa k x horario', $MRatioNoShape->enrich([$aRowNoShape])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 180);
$Ratios = new SegmentTimes(['lengthRatios' => ['0' => ['all' => 1.1], '5' => ['all' => 0.5, 'laborable.noche' => 0.4]]]);
$Expect('cubos de largo: 299 m -> 0, 300 m -> 1, 2999 m -> 4, 3000 m -> 5', SegmentTimes::lengthBucket(299) === 0 && SegmentTimes::lengthBucket(300) === 1 && SegmentTimes::lengthBucket(2999) === 4 && SegmentTimes::lengthBucket(3000) === 5);
$Expect('la proporción de la franja manda sobre la del cubo', abs($Ratios->fallbackSeconds(100.0, 4000.0, 'laborable.noche') - 40.0) < 1e-9 && abs($Ratios->fallbackSeconds(100.0, 4000.0, 'laborable.manana') - 50.0) < 1e-9);
$Expect('sin largo, sin proporciones o con largo no positivo no hay estimación', $Ratios->fallbackSeconds(100.0, null, 'x') === null && (new SegmentTimes())->fallbackSeconds(100.0, 1000.0, 'x') === null && $Ratios->fallbackSeconds(100.0, 0.0, 'x') === null);
Config::set('euskotren');
$MOtherNoBus = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$Expect('en otras redes se deja el horario publicado', $MOtherNoBus->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
Config::set('bus');

/* GPS y parada registrada que no cuadran (más de 6 min): no se fía de ninguna, horario oficial. */
$aDisagree = live(1500, 0, 5, $iGps);
$MDisagree = matcher($aDisagree, $iGps + 30, new PaceFactors(['global' => ['laborable.punta_manana' => 0.0]]));
$aDisagreeOut = $MDisagree->enrich([$aRowTarget]);
$Expect('GPS y parada registrada discrepan más de 6 min: la salida sale con el horario oficial', $aDisagreeOut[0]['status'] === 'scheduled', $aDisagreeOut[0]['status']);
$aAgreeOut = matcher($aGpsPassed, $iGps + 30)->enrich([$aRowTarget]);
$Expect('GPS y parada registrada que cuadran: sigue en vivo', $aAgreeOut[0]['status'] === 'live', $aAgreeOut[0]['status']);

// Un viaje del feed que no es el que hace el bus (50 min de diferencia con el horario): la llegada estimada sigue
// valiendo, el retraso no se afirma.
$iFarGps = T0 + 3300;
$aFar = live(1500, 0, 3, $iFarGps);
$MFarDelay = matcher($aFar, $iFarGps + 30);
$aFarGpsOut = $MFarDelay->enrich([$aRowTarget])[0];
$Expect('con GPS, 50 min de diferencia con el horario: sigue en vivo y el retraso queda sin afirmar', $aFarGpsOut['status'] === 'live' && $aFarGpsOut['delaySeconds'] === null, json_encode([$aFarGpsOut['status'], $aFarGpsOut['delaySeconds']]));
$Expect('el retraso para mostrar es null si pasa de 45 min y un entero si no', $MFarDelay->displayDelayMinutes(50 * 60) === null && $MFarDelay->displayDelayMinutes(40 * 60) === 40 && $MFarDelay->displayDelayMinutes(null) === null);
$aNear = live(1500, 0, 3, T0 + 240 + 40 * 60);
$Expect('40 min de retraso sí se muestran', matcher($aNear, T0 + 240 + 40 * 60 + 30)->isLive('J_LINE', $aNear));

/* Otras redes con SIRI no usan la parada registrada: siguen con el cálculo de siempre. */
Config::set('euskotren');
$MOtherNetwork = matcher($aRegistered, T0 + 300);
$Expect('en otra red no se da ninguna fila por pasada con la parada registrada', $MOtherNetwork->enrich([$aRowPassed])[0]['status'] !== 'departed');
[$iEta] = $MOtherNetwork->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$Expect('en otra red, sin GPS, la ETA es la de siempre (ahora + horario desde la próxima parada)', $iEta === T0 + 300 + 360, hms($iEta));
Config::set('bus');

// Corrección por viaje. J_LINE (S0 a S4, sale a las 08:00) llega a S4 a las 08:07:40 con los tramos aprendidos.
if (class_exists(TripOffsets::class)) {
    $Section('TripOffsets: corrección por viaje');
    $sLineKey = TripOffsets::key('3414', 'S0', 'S4', T0, 5);
    $Expect('el viaje se reconoce por línea, primera y última parada, salida y número de paradas', $sLineKey === '3414|S0|S4|28800|5', $sLineKey);
    $Expect('tercios de un recorrido de 5 paradas: 0 y 1 en el primero, 2 y 3 en el segundo, 4 en el tercero', [TripOffsets::part(0, 5), TripOffsets::part(1, 5), TripOffsets::part(2, 5), TripOffsets::part(3, 5), TripOffsets::part(4, 5), TripOffsets::part(9, 5)] === [0, 0, 1, 1, 2, 2]);
    $Trips = new TripOffsets([
        'trips' => [$sLineKey => ['laborable' => [10, 20, 30]]],
        'lines' => ['3414' => ['laborable.punta_manana' => 50]],
        'global' => ['laborable.punta_manana' => 70, 'fin_semana.punta_manana' => 90],
    ]);
    $Expect('con datos del viaje se usa el de su tercio', $Trips->seconds($sLineKey, '3414', DATE_WEEKDAY, T0, 4, 5) === 30.0 && $Trips->seconds($sLineKey, '3414', DATE_WEEKDAY, T0, 2, 5) === 20.0);
    $Expect('sin datos del viaje, el de su línea y franja', $Trips->seconds('3414|S0|S9|28800|9', '3414', DATE_WEEKDAY, T0, 4, 9) === 50.0);
    $Expect('sin datos de la línea, el de la franja', $Trips->seconds('1|A|B|28800|3', '1', DATE_WEEKDAY, T0, 1, 3) === 70.0);
    $Expect('el viaje solo tiene datos de laborable: en fin de semana va a la franja de fin de semana', $Trips->seconds($sLineKey, '3414', DATE_WEEKEND, T0, 4, 5) === 90.0);
    $Expect('sin tabla, no se corrige nada', (new TripOffsets())->seconds($sLineKey, '3414', DATE_WEEKDAY, T0, 4, 5) === 0.0 && (new TripOffsets())->isEmpty());
    // El feed escribe la línea con ceros delante (0651) y el horario sin ellos (651): las dos formas son la misma línea.
    $Expect('la clave del viaje no cambia por un cero delante de la línea', TripOffsets::key('03414', 'S0', 'S4', T0, 5) === $sLineKey);
    $Expect('con la línea como la escribe el feed se encuentran el viaje y la línea', $Trips->seconds('03414|S0|S4|28800|5', '03414', DATE_WEEKDAY, T0, 4, 5) === 30.0 && $Trips->seconds('03414|S0|S9|28800|9', '03414', DATE_WEEKDAY, T0, 4, 9) === 50.0);
    $TripsFeed = new TripOffsets(['lines' => ['03414' => ['laborable.punta_manana' => 50]]]);
    $Expect('una tabla escrita con ceros delante vale para la línea del horario', $TripsFeed->seconds('3414|S0|S9|28800|9', '3414', DATE_WEEKDAY, T0, 4, 9) === 50.0);
    $PaceFeed = new PaceFactors(['lines' => ['0651' => ['laborable.punta_manana' => 0.8]]]);
    $Expect('el factor de ritmo de 0651 vale para la línea 651 del horario, y al revés', $PaceFeed->factor('651', DATE_WEEKDAY, T0) === 0.8 && (new PaceFactors(['lines' => ['651' => ['laborable.punta_manana' => 0.8]]]))->factor('0651', DATE_WEEKDAY, T0) === 0.8);

    $MTrip = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned, $Trips);
    $aTrip = $MTrip->enrich([$aRowNoBus])[0];
    $Expect('sin datos del bus, a la hora estimada se suma la corrección del viaje en S4 (tercer tercio: 30 s, 08:08:10)', $aTrip['status'] === 'estimated' && $aTrip['etaSeconds'] === T0 + 460 + 30, hms($aTrip['etaSeconds']));
    $Expect('la hora estimada es lo aprendido más la corrección', $MTrip->learnedArrival('J_LINE', T0 + 720, '3414') === T0 + 460 && $MTrip->tripOffsetSeconds('J_LINE', 4, '3414') === 30);
    $aRowNoSeq = $aRowNoBus;
    unset($aRowNoSeq['stop_seq']);
    $aRowNoSeq['stop_id'] = 'S4';
    $Expect('sin el orden de la parada, se encuentra por parada y hora', $MTrip->enrich([$aRowNoSeq])[0]['etaSeconds'] === T0 + 460 + 30);
    $Expect('con tramos pasados a mano y sin tabla de viajes, no se lee la del fichero', $MNoBus->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 460);

    // Festivo entre semana: tramos y corrección de fin de semana (40 s en S4 en vez de 30).
    $PdoHoliday = buildFixture(40);
    $PdoHoliday->exec("
        CREATE TABLE service_calendars (id TEXT PRIMARY KEY, from_date TEXT NOT NULL, to_date TEXT NOT NULL, weekday_mask INTEGER NOT NULL);
        CREATE TABLE service_calendar_exceptions (calendar_id TEXT NOT NULL, date TEXT NOT NULL, available INTEGER NOT NULL);
        INSERT INTO service_calendars VALUES ('C_DOM', '2026-01-01', '2026-12-31', 64), ('C_LAB', '2026-01-01', '2026-12-31', 31);
        INSERT INTO service_calendar_exceptions VALUES ('C_DOM', '" . DATE_WEEKDAY . "', 1), ('C_LAB', '" . DATE_WEEKDAY . "', 0), ('C_LAB', '2026-10-03', 1);
    ");
    $HolidayJourneys = new ServiceJourney($PdoHoliday);
    $Expect('festivo: el día que circulan por excepción los calendarios de solo domingo', $HolidayJourneys->isHoliday(DATE_WEEKDAY) && !$HolidayJourneys->isHoliday('2026-10-02') && !$HolidayJourneys->isHoliday('2026-10-03'));
    $Expect('festivo: sin calendarios en la base, ningún día lo es', !(new ServiceJourney(buildFixture(40)))->isHoliday(DATE_WEEKDAY));
    $Expect('festivo: la capa 3 calcula con el domingo de esa semana', TripOffsets::serviceDate(DATE_WEEKDAY, true) === DATE_WEEKEND && TripOffsets::serviceDate(DATE_WEEKDAY, false) === DATE_WEEKDAY);
    $TripsBoth = new TripOffsets(['trips' => [$sLineKey => ['laborable' => [10, 20, 30], 'fin_semana' => [10, 20, 40]]]]);
    $MHoliday = new RealtimeMatcher([], $HolidayJourneys, T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned, $TripsBoth);
    $aHoliday = $MHoliday->enrich([$aRowNoBus])[0];
    $Expect('festivo entre semana: la hora estimada lleva la corrección de fin de semana (08:08:20)', $MHoliday->serviceDate() === DATE_WEEKEND && $aHoliday['etaSeconds'] === T0 + 460 + 40, hms($aHoliday['etaSeconds']));
    $MWorkday = new RealtimeMatcher([], $HolidayJourneys, T0 - 600, new PaceFactors(), '2026-10-02', $SegLearned, $TripsBoth);
    $Expect('el día siguiente, laborable, la de laborable (08:08:10)', $MWorkday->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 460 + 30);
}

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: el bus que llega a la parada no desaparece antes de pasarla');

$aRowS2 = ['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0 + 360, 'stop_seq' => 3];
$iLate = T0 + 600;
$aAtStop = live(2020, 0, 3, $iLate - 70);
$aAtStopOut = matcher($aAtStop, $iLate)->enrich([$aRowS2])[0];
$Expect('a 20 m de la parada y con el feed aún hacia ella, sigue en directo y llega «ahora», aunque el GPS sea de hace 70 s', $aAtStopOut['status'] === 'live' && $aAtStopOut['etaSeconds'] === $iLate, $aAtStopOut['status'] . ' ' . hms((int)$aAtStopOut['etaSeconds']));
$Expect('su retraso es al menos lo que ya va tarde', $aAtStopOut['delaySeconds'] >= $iLate - (T0 + 360), (string)$aAtStopOut['delaySeconds']);
$aFeedNext = live(2020, 0, 4, $iLate - 70);
$Expect('si el feed ya apunta a la siguiente parada, está pasada', matcher($aFeedNext, $iLate)->enrich([$aRowS2])[0]['status'] === 'departed');
$aFarPast = live(2300, 0, 3, $iLate - 70);
$Expect('si el GPS está a más de 150 m después de la parada, está pasada aunque el feed no lo diga', matcher($aFarPast, $iLate)->enrich([$aRowS2])[0]['status'] === 'departed');
$aStuck = live(1950, 0, 3, $iLate - 300);
$aStuckOut = matcher($aStuck, $iLate)->enrich([$aRowS2])[0];
$Expect('con la llegada cumplida hace más de 3 min y sin confirmación, «puede que ya haya pasado», sin desaparecer', $aStuckOut['status'] === 'likely_departed' && $aStuckOut['etaSeconds'] === $iLate, $aStuckOut['status']);
$Expect('la ficha del viaje usa la misma regla', !matcher($aAtStop, $iLate)->hasPassed('J_LINE', $aAtStop, T0 + 360, 3) && matcher($aFeedNext, $iLate)->hasPassed('J_LINE', $aFeedNext, T0 + 360, 3));

$Section('RealtimeMatcher: sin trazado (tramos entre paradas)');

$aSeg = live(1500, 100, 3, $iGps, 'trp_A3414_809_X_28800_x');
$MSeg = new RealtimeMatcher(['3414|809' => [$aSeg]], new ServiceJourney(buildFixture(40)), $iGps, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aPos = $MSeg->positionFor('J_NOSHAPE', $aSeg);
$Expect('sin shape se proyecta sobre la recta entre paradas', $aPos !== null && $aPos['method'] === 'segment');
$Expect('sin shape el pasillo es max(150 m, 15 %): 100 m vale', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 270);
$aSegFar = live(1500, 200, 3, $iGps, 'trp_A3414_809_X_28800_x');
$Expect('sin shape a 200 m queda fuera de ruta', $MSeg->positionFor('J_NOSHAPE', $aSegFar) === null);

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: trazados que vuelven sobre sí mismos');

$aOut = live(250, 0, 2, T0 + 60);
$aPos = matcher($aOut, T0 + 60)->positionFor('J_LOOP', $aOut);
$Expect('en la ida (próxima = P2) se queda en la ida', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 45, positionText($aPos));

$aBack = live(250, 40, 2, T0 + 300);
$aPos = matcher($aBack, T0 + 300, null, 40.0)->positionFor('J_LOOP', $aBack);
$Expect('en la vuelta, 40 m más cerca, gana la vuelta aunque el feed diga P2', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 315, positionText($aPos));

$aClose = live(250, 10, 2, T0 + 300);
$aPos = matcher($aClose, T0 + 300, null, 10.0)->positionFor('J_LOOP', $aClose);
$Expect('si la vuelta está a menos de 20 m de diferencia, manda la próxima parada del feed', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 45, positionText($aPos));

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: emparejar el feed con el horario y enrich()');

$M = matcher($aLive, $iGps + 30);
$Expect('el VehicleJourneyRef se empareja por trip_aliases', $M->lookup('3414', '808', T0, 'J_LINE') === $aLive);
$aVariant = live(1500, 0, 3, $iGps, 'trp_A3414_808_OP9LJIN_28800_x');
$MVariant = new RealtimeMatcher(['3414|808' => [$aVariant]], new ServiceJourney(buildFixture(40)), $iGps, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$Expect('una variante de calendario fusionada (OP9LJIN) apunta al mismo viaje', $MVariant->journeyIdFor($aVariant) === 'J_LINE' && $MVariant->lookup('3414', '808', T0, 'J_LINE') === $aVariant);

$aOld = ['3414|808' => [
    ['departureSeconds' => T0 + 200, 'tag' => 'lejos'],
    ['departureSeconds' => T0 + 60, 'tag' => 'cerca'],
]];
$MOld = new RealtimeMatcher($aOld, null, T0, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aHit = $MOld->lookup('3414', '808', T0);
$Expect('sin alias, gana la salida más cercana dentro de 180 s', $aHit !== null && $aHit['tag'] === 'cerca');
$Expect('sin alias, más de 180 s de diferencia no empareja', $MOld->lookup('3414', '808', T0 + 600) === null);
$Expect('una línea sin buses en el feed no empareja', $MOld->lookup('9999', '808', T0) === null);

$aRow = ['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0 + 720];
$aOut = $M->enrich([$aRow])[0];
$Expect('enrich marca la salida como live con la ETA calculada', $aOut['status'] === 'live' && $aOut['etaSeconds'] === T0 + 690 && $aOut['vehicleRef'] === 'BUS1');
$Expect('enrich conserva los campos originales de la fila', $aOut['arrival_seconds'] === T0 + 720 && $aOut['trip_number'] === '808');

$aPassed = ['arrival_seconds' => T0] + $aRow;
$aOut = $M->enrich([$aPassed])[0];
$Expect('una parada que el bus ya dejó atrás sale como departed', $aOut['status'] === 'departed');

$aOther = ['service_journey_id' => 'J_NOSHAPE', 'trip_number' => '777'] + $aRow;
$aOut = $M->enrich([$aOther])[0];
$Expect('una salida sin bus en el feed queda programada', $aOut['status'] === 'scheduled' && $aOut['etaSeconds'] === T0 + 720 && $aOut['delaySeconds'] === 0);

$aOut = $MFar->enrich([$aRow])[0];
$Expect('un bus fuera de ruta deja la salida como programada', $aOut['status'] === 'scheduled');

/* ------------------------------------------------------------------ */
$Section('PaceFactors');

$Expect('jueves 07:30 es laborable.punta_manana', PaceFactors::bandFor(DATE_WEEKDAY, 7 * 3600 + 1800) === 'laborable.punta_manana');
$Expect('domingo 10:00 es fin_semana.manana', PaceFactors::bandFor(DATE_WEEKEND, 10 * 3600) === 'fin_semana.manana');
$Expect('16:00 en punto ya es punta_tarde', PaceFactors::bandFor(DATE_WEEKDAY, 16 * 3600) === 'laborable.punta_tarde');
$Expect('pasada la medianoche (25:10 GTFS) es madrugada', PaceFactors::bandFor(DATE_WEEKDAY, 25 * 3600 + 600) === 'laborable.madrugada');
$P = new PaceFactors(['global' => ['laborable.manana' => 0.9, 'laborable.noche' => 0.1, 'laborable.mediodia' => 5], 'lines' => ['A1' => ['laborable.manana' => 0.8]]]);
$Expect('el factor por línea tiene prioridad sobre el global', $P->factor('A1', DATE_WEEKDAY, 10 * 3600) === 0.8);
$Expect('otra línea usa el global', $P->factor('B2', DATE_WEEKDAY, 10 * 3600) === 0.9);
$Expect('sin valor para la franja, k = 1', $P->factor('B2', DATE_WEEKEND, 10 * 3600) === 1.0);
$Expect('k se limita por abajo a 0,6', $P->factor(null, DATE_WEEKDAY, 21 * 3600) === PaceFactors::MIN_FACTOR);
$Expect('k se limita por arriba a 1,2', $P->factor(null, DATE_WEEKDAY, 14 * 3600) === PaceFactors::MAX_FACTOR);
$Expect('un fichero que no existe da k = 1', PaceFactors::fromFile('/no/existe.json')->factor(null, DATE_WEEKDAY, 10 * 3600) === 1.0);

$aData = json_decode((string)file_get_contents(PaceFactors::defaultPath()), true);
$aBands = [];
foreach (['laborable', 'fin_semana'] as $sDay) {
    foreach (['madrugada', 'punta_manana', 'manana', 'mediodia', 'punta_tarde', 'noche'] as $sBand) {
        $aBands[] = $sDay . '.' . $sBand;
    }
}
$bValid = \is_array($aData) && isset($aData['global']) && \is_array($aData['global']);
$aBad = [];
if ($bValid) {
    $aAll = ['global' => $aData['global']];
    foreach ($aData['lines'] ?? [] as $sLine => $aLineBands) {
        $aAll['línea ' . $sLine] = $aLineBands;
    }
    foreach ($aAll as $sWhere => $aValues) {
        foreach ($aValues as $sBand => $dValue) {
            if (!\in_array($sBand, $aBands, true) || !is_numeric($dValue) || $dValue < PaceFactors::MIN_FACTOR || $dValue > PaceFactors::MAX_FACTOR) {
                $aBad[] = $sWhere . ':' . $sBand . '=' . json_encode($dValue);
            }
        }
    }
}
$Expect('engine/bizkaibus/data/pace-factors.json es válido (franjas conocidas, k entre 0,6 y 1,2)', $bValid && empty($aBad), implode(', ', $aBad));

$aSegmentsBad = [];
$sSegmentsPath = SegmentTimes::defaultPath();
if (is_file($sSegmentsPath)) {
    $aSegmentsFile = json_decode((string)file_get_contents($sSegmentsPath), true);
    if (!is_array($aSegmentsFile) || !is_array($aSegmentsFile['segments'] ?? null)) {
        $aSegmentsBad[] = 'formato';
    } else {
        foreach ($aSegmentsFile['segments'] as $sKey => $aSegment) {
            foreach ($aSegment as $sGroup => $Value) {
                $bGroupOk = $sGroup === 'n' || $sGroup === 'all' || \in_array($sGroup, $aBands, true);
                if (!preg_match('/^[^>]+>[^>]+$/', (string)$sKey) || !$bGroupOk || !is_int($Value) || $Value <= 0 || $Value > 3 * 3600) {
                    $aSegmentsBad[] = $sKey . ':' . $sGroup . '=' . json_encode($Value);
                }
            }
        }
    }
}
$Expect('engine/bizkaibus/data/segment-times.json es válido si existe (tramos desde>hasta, segundos entre 1 y 3 h)', empty($aSegmentsBad), implode(', ', array_slice($aSegmentsBad, 0, 5)));

/* ------------------------------------------------------------------ */
$Section('SIRI-VM (parseo del feed)');

$sXml = <<<XML
<Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery>
<VehicleActivity><RecordedAtTime>2026-10-01T08:04:00+02:00</RecordedAtTime><MonitoredVehicleJourney>
  <VehicleJourneyRef>trp_A3414_808_OP9LSEPT_28800_abc</VehicleJourneyRef><Delay>PT1M30S</Delay><VehicleRef>4521</VehicleRef>
  <VehicleLocation><Longitude>-2.93</Longitude><Latitude>43.27</Latitude></VehicleLocation>
  <MonitoredCall><StopPointRef>0123</StopPointRef><VisitNumber>3</VisitNumber></MonitoredCall>
</MonitoredVehicleJourney></VehicleActivity>
<VehicleActivity><MonitoredVehicleJourney>
  <VehicleJourneyRef>trp_A3414_808_OP9LJIN_30000_abc</VehicleJourneyRef><Delay>-PT45S</Delay>
  <LocationRecordedAtTime>2026-09-30T23:59:00+02:00</LocationRecordedAtTime>
  <VehicleLocation><Longitude>-2.9</Longitude><Latitude>43.2</Latitude></VehicleLocation>
  <MonitoredCall><Order>7</Order></MonitoredCall>
</MonitoredVehicleJourney></VehicleActivity>
<VehicleActivity><MonitoredVehicleJourney><VehicleJourneyRef>referencia-rara</VehicleJourneyRef></MonitoredVehicleJourney></VehicleActivity>
</VehicleMonitoringDelivery></ServiceDelivery></Siri>
XML;
$aMap = SiriVehicleMonitoringClient::parse($sXml, DATE_WEEKDAY);
$aFirst = $aMap['3414|808'][0] ?? null;
$aSecond = $aMap['3414|808'][1] ?? null;
$Expect('agrupa por línea|número de viaje y descarta referencias que no encajan', array_keys($aMap) === ['3414|808'] && \count($aMap['3414|808']) === 2);
$Expect('lee la salida del VehicleJourneyRef (28800 s)', $aFirst !== null && $aFirst['departureSeconds'] === 28800);
$Expect('Delay PT1M30S = 90 s y -PT45S = -45 s', $aFirst !== null && $aSecond !== null && $aFirst['delaySeconds'] === 90 && $aSecond['delaySeconds'] === -45);
$Expect('lee GPS, hora del dato (08:04:00), parada y VisitNumber', $aFirst !== null && $aFirst['lat'] === 43.27 && $aFirst['locationSeconds'] === T0 + 240 && $aFirst['currentStopId'] === '0123' && $aFirst['order'] === 3);
$Expect('Order tiene prioridad y un GPS de otro día se descarta', $aSecond !== null && $aSecond['order'] === 7 && $aSecond['lat'] === null && $aSecond['locationSeconds'] === null);
$Expect('guarda la hora del dato (RecordedAtTime) aunque no haya GPS', $aFirst !== null && $aFirst['recordedSeconds'] === T0 + 240 && $aSecond !== null && $aSecond['recordedSeconds'] === null);
$Expect('un XML roto devuelve un mapa vacío', SiriVehicleMonitoringClient::parse('<Siri><roto', DATE_WEEKDAY) === []);
$sXmlZero = '<Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery><VehicleActivity>'
    . '<RecordedAtTime>2026-10-01T08:04:00+02:00</RecordedAtTime><MonitoredVehicleJourney>'
    . '<VehicleJourneyRef>trp_A0651_738_OP43SIN_57900_x</VehicleJourneyRef><MonitoredCall><Order>4</Order></MonitoredCall>'
    . '</MonitoredVehicleJourney></VehicleActivity></VehicleMonitoringDelivery></ServiceDelivery></Siri>';
$aMapZero = SiriVehicleMonitoringClient::parse($sXmlZero, DATE_WEEKDAY);
$Expect('la línea A0651 del feed se lee como 651, igual que en el horario', array_keys($aMapZero) === ['651|738'] && ($aMapZero['651|738'][0]['lineId'] ?? '') === '651', implode(', ', array_keys($aMapZero)));

/* ------------------------------------------------------------------ */
$Section('Buses que faltan en una lectura del feed (FeedMemory)');
$iHeldGps = T0 + 240;
$aHeldBus = live(1500, 0, 3, $iHeldGps);
$aOtherBus = live(0, 0, 2, $iHeldGps);
$aOtherBus['vehicleRef'] = 'BUS2';
$aPrevious = ['3414|808' => [$aHeldBus]];
$aHeld = FeedMemory::withHeld([], $aPrevious, $iHeldGps + 60);
$Expect('un bus que falta se sigue usando con su último dato', ($aHeld['3414|808'][0]['vehicleRef'] ?? '') === 'BUS1' && !empty($aHeld['3414|808'][0]['held']));
$Expect('pasado HOLD_SECONDS ya no se guarda', FeedMemory::withHeld([], $aPrevious, $iHeldGps + FeedMemory::HOLD_SECONDS + 1) === []);
$aOtherTrip = live(0, 0, 1, $iHeldGps + 60, 'trp_A3414_809_OP9LSEPT_30600_x');
$Expect('si el bus ya va en otro viaje, el anterior no se guarda', FeedMemory::withHeld(['3414|809' => [$aOtherTrip]], $aPrevious, $iHeldGps + 60) === ['3414|809' => [$aOtherTrip]]);
$Expect('si su viaje ya lo lleva otro bus, no se duplica', FeedMemory::withHeld(['3414|808' => [$aOtherBus]], $aPrevious, $iHeldGps + 60) === ['3414|808' => [$aOtherBus]]);
$Expect('sin hora del dato no se guarda', FeedMemory::withHeld([], ['3414|808' => [['vehicleRef' => 'BUS1']]], $iHeldGps) === []);
$MHeld = new RealtimeMatcher($aHeld, new ServiceJourney(buildFixture(40)), $iHeldGps + 60, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aHeldRow = $MHeld->enrich([['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5]])[0];
$Expect('con el dato guardado la parada sigue en directo, con la llegada de esa posición', $aHeldRow['status'] === 'live' && $aHeldRow['etaSeconds'] === T0 + 690, json_encode([$aHeldRow['status'], hms($aHeldRow['etaSeconds'])]));

/* ------------------------------------------------------------------ */
$Section('Registro de pasos (buses que ya pasaron aunque hayan salido del directo)');
$aVmPass = ['3414|808' => [
    ['tripRef' => 'trp_A', 'order' => 4, 'recordedSeconds' => T0 + 500, 'locationSeconds' => T0 + 490],
    ['tripRef' => 'trp_B', 'order' => null, 'recordedSeconds' => T0 + 500],
    ['tripRef' => 'trp_C', 'order' => 2, 'recordedSeconds' => null, 'locationSeconds' => T0 + 300],
    ['tripRef' => 'trp_D', 'order' => 3],
]];
$Expect('se apunta «parada a la que va:hora» de cada viaje con parada y hora', PassageLog::fields($aVmPass) === ['trp_A' => '4:' . (T0 + 500), 'trp_C' => '2:' . (T0 + 300)], json_encode(PassageLog::fields($aVmPass)));
$Expect('valores mal formados no cuentan', PassageLog::decode('x') === null && PassageLog::decode(null) === null && PassageLog::decode('4:100') === ['order' => 4, 'seconds' => 100]);
$Expect('de los alias de un viaje vale el que llegó más lejos', PassageLog::merge(['J1', 'J1', 'J2'], ['2:100', '5:200', null]) === ['J1' => ['order' => 5, 'seconds' => 200]]);
$Expect('la clave es por red y por día', PassageLog::key('bus', '2026-10-29') === 'bide:bus:pass:2026-10-29');
$Expect('historial: una entrada por viaje y parada, con la hora', PassageLog::stopFields($aVmPass) === ['trp_A#4' => (string)(T0 + 500), 'trp_C#2' => (string)(T0 + 300)], json_encode(PassageLog::stopFields($aVmPass)));
$aHistory = PassageLog::stopsFromHash(['trp_X#5' => '200', 'trp_X#3' => '100', 'trp_Y#1' => '50', 'mal' => '1', 'trp_Z#2' => 'x']);
$Expect('historial: se agrupa por viaje y se ordena por parada', $aHistory === ['trp_X' => [3 => 100, 5 => 200], 'trp_Y' => [1 => 50]], json_encode($aHistory));
$Expect('sin almacén configurado no se consulta nada', PassageLog::forJourneys(new ServiceJourney(buildFixture(40)), ['J_LINE'], 'bus', DATE_WEEKDAY) === []);
$Expect('nombres de un viaje en el directo: su id y sus alias', (new ServiceJourney(buildFixture(40)))->tripRefsForJourneys(['J_LINE'])['J_LINE'] === ['J_LINE', 'trp_A3414_808_OP9LJIN_28800_x', 'trp_A3414_808_OP9LSEPT_28800_x'] || (new ServiceJourney(buildFixture(40)))->tripRefsForJourneys(['J_LINE'])['J_LINE'] === ['J_LINE', 'trp_A3414_808_OP9LSEPT_28800_x', 'trp_A3414_808_OP9LJIN_28800_x']);

$aAsked = [];
$MPass = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$MPass->setPassageLoader(function (array $aIds) use (&$aAsked) {
    $aAsked = array_merge($aAsked, $aIds);
    return ['J_LINE' => ['order' => 4, 'seconds' => T0 + 500]];
});
$aPassRows = [
    ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 360, 'stop_seq' => 3],
    ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 540, 'stop_seq' => 4],
];
$aPassOut = $MPass->enrich($aPassRows);
$Expect('el registro dice que el bus ya va hacia la parada 4: la 3 sale como pasada, con la hora del registro', $aPassOut[0]['status'] === 'departed' && $aPassOut[0]['etaSeconds'] === T0 + 500, json_encode([$aPassOut[0]['status'], $aPassOut[0]['etaSeconds']]));
$Expect('la parada a la que va todavía no ha pasado', $aPassOut[1]['status'] === 'scheduled', $aPassOut[1]['status']);
$Expect('el registro se pide una vez por consulta, sin repetir viajes', $aAsked === ['J_LINE'], json_encode($aAsked));
$MPass->enrich($aPassRows);
$Expect('lo ya leído no se vuelve a pedir', $aAsked === ['J_LINE'], json_encode($aAsked));

$aAskedLive = [];
$MPassLive = matcher(live(1500, 0, 3, T0 + 270), T0 + 300);
$MPassLive->setPassageLoader(function (array $aIds) use (&$aAskedLive) {
    $aAskedLive = array_merge($aAskedLive, $aIds);
    return [];
});
$MPassLive->enrich([$aPassRows[1]]);
$Expect('los viajes que están en el directo no se buscan en el registro', $aAskedLive === [], json_encode($aAskedLive));
$MPassBroken = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$MPassBroken->setPassageLoader(function (array $aIds) {
    throw new RuntimeException('almacén caído');
});
$Expect('si el registro falla, se sigue con el horario', @$MPassBroken->enrich([$aPassRows[0]])[0]['status'] === 'scheduled');

/* ------------------------------------------------------------------ */
$Section('De madrugada: buses de viajes de ayer que pasan de las 24:00');

// El mismo recorrido que J_LINE, de ayer a las 26:00 (02:00 de hoy). El bus va a mitad del primer tramo a las 02:04:50:
// en horas de su horario, 26:04:50, unos 200 s tarde.
const NIGHT = 26 * 3600;
$PdoNight = buildFixture(40);
$PdoNight->exec('UPDATE passing_times SET arrival_seconds = arrival_seconds + ' . (NIGHT - T0) . ', departure_seconds = departure_seconds + ' . (NIGHT - T0));
$PdoNight->exec('UPDATE service_journeys SET first_departure_seconds = ' . NIGHT);
$iNightNow = 2 * 3600 + 300;
$aNightBus = ['departureSeconds' => NIGHT, 'recordedSeconds' => $iNightNow - 10] + live(500, 0, 2, $iNightNow - 10);
$aDayBus = ['vehicleRef' => 'BUS2', 'departureSeconds' => 2 * 3600] + live(0, 0, 1, $iNightNow - 10, 'trp_A3414_809_x');
$aNightMap = ['3414|808' => [$aNightBus], '3414|809' => [$aDayBus]];
$Expect('a las 02:05, el bus del viaje de las 23:30 o de las 26:00 de ayer es de ayer; el de las 02:00 de hoy, de hoy',
    RealtimeMatcher::daysAgo(['departureSeconds' => 84600], $iNightNow) === 1 && RealtimeMatcher::daysAgo($aNightBus, $iNightNow) === 1
    && RealtimeMatcher::daysAgo($aDayBus, $iNightNow) === 0);
$Expect('a las 23:58, el de las 24:10 del horario de hoy es de hoy; de día, uno de hace una hora también',
    RealtimeMatcher::daysAgo(['departureSeconds' => 87000], 86280) === 0 && RealtimeMatcher::daysAgo(['departureSeconds' => 46800], 50400) === 0
    && RealtimeMatcher::daysAgo(['departureSeconds' => 0], 50400) === 0);
$aYesterdayMap = RealtimeMatcher::vehiclesOfDay($aNightMap, $iNightNow, 1);
$Expect('los de ayer van con sus horas en las de ayer (+24 h); los de hoy, aparte y tal cual',
    array_keys($aYesterdayMap) === ['3414|808'] && $aYesterdayMap['3414|808'][0]['locationSeconds'] === $iNightNow - 10 + 86400
    && $aYesterdayMap['3414|808'][0]['recordedSeconds'] === $iNightNow - 10 + 86400 && RealtimeMatcher::vehiclesOfDay($aNightMap, $iNightNow, 0) === ['3414|809' => [$aDayBus]],
    json_encode($aYesterdayMap));
$aDayMap = ['3414|808' => [live(500, 0, 2, T0 + 80)], '3414|809' => [['vehicleRef' => 'BUS2', 'lat' => null, 'locationSeconds' => null, 'recordedSeconds' => T0 + 60] + live(0, 0, 1, T0)]];
$Expect('de día, los de hoy son todos y sin tocar', RealtimeMatcher::vehiclesOfDay($aDayMap, T0 + 90, 0) === $aDayMap && RealtimeMatcher::vehiclesOfDay($aDayMap, T0 + 90, 1) === []);

// De 23:58 a 05:00 el feed de Bizkaibus repite los buses de antes de medianoche (capturas del 1 y del 7 de noviembre):
// leídos pasada la medianoche no traen hora de hoy y no cuentan para nadie, ni hoy ni ayer.
$sFrozenXml = '<Siri><ServiceDelivery><VehicleMonitoringDelivery><VehicleActivity><RecordedAtTime>2026-11-06T23:58:26+01:00</RecordedAtTime>'
    . '<MonitoredVehicleJourney><VehicleJourneyRef>trp_A3136_668_OP40VIN_84600_O40VI3136_313603_12</VehicleJourneyRef>'
    . '<LocationRecordedAtTime>2026-11-06T23:58:26+01:00</LocationRecordedAtTime><VehicleLocation><Longitude>-2.9</Longitude><Latitude>43.2</Latitude></VehicleLocation>'
    . '<MonitoredCall><StopPointRef>0418</StopPointRef><Order>12</Order></MonitoredCall></MonitoredVehicleJourney></VehicleActivity>'
    . '</VehicleMonitoringDelivery></ServiceDelivery></Siri>';
$aFrozen = SiriVehicleMonitoringClient::parse($sFrozenXml, '2026-11-07');
$Expect('el feed congelado de madrugada: el bus de las 23:58 no lleva hora de hoy y no es de nadie',
    \count($aFrozen['3136|668'] ?? []) === 1 && !RealtimeMatcher::hasTime($aFrozen['3136|668'][0])
    && RealtimeMatcher::vehiclesOfDay($aFrozen, 3600, 0) === [] && RealtimeMatcher::vehiclesOfDay($aFrozen, 3600, 1) === [],
    json_encode($aFrozen));
$Expect('leído antes de medianoche, sí: de hoy y con su hora', RealtimeMatcher::vehiclesOfDay(SiriVehicleMonitoringClient::parse($sFrozenXml, '2026-11-06'), 86340, 0)['3136|668'][0]['recordedSeconds'] === 86306);

$MNight = new RealtimeMatcher($aYesterdayMap, new ServiceJourney($PdoNight), $iNightNow + 86400, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aNightRow = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => NIGHT, 'service_journey_id' => 'J_LINE', 'stop_seq' => 3, 'stop_id' => 'S2', 'arrival_seconds' => NIGHT + 360];
$aNightOut = $MNight->enrich([$aNightRow])[0];
$Expect('con el emparejador de ayer, el bus de las 26:00 va en directo con su retraso (unos 200 s), no con 24 h',
    $aNightOut['status'] === 'live' && abs($aNightOut['delaySeconds'] - 200) <= 30 && $aNightOut['etaSeconds'] > $iNightNow + 86400 && $aNightOut['etaSeconds'] < $iNightNow + 86400 + 600,
    json_encode($aNightOut));
$aSheetStops = (new ServiceJourney($PdoNight))->stopsWithCoordinates('J_LINE');
$Expect('y la ficha lo sitúa en el primer tramo', positionText($MNight->positionFor('J_LINE', $aYesterdayMap['3414|808'][0])) !== 'null'
    && $MNight->hasPassed('J_LINE', $aYesterdayMap['3414|808'][0], (int)$aSheetStops[0]['arrival_seconds'], 1) && !$MNight->hasPassed('J_LINE', $aYesterdayMap['3414|808'][0], (int)$aSheetStops[2]['arrival_seconds'], 3));
$MNightOld = new RealtimeMatcher($aNightMap, new ServiceJourney($PdoNight), $iNightNow, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aNightOld = $MNightOld->enrich([['arrival_seconds' => NIGHT + 360 - 86400] + $aNightRow])[0];
$Expect('(con el de hoy y el bus tal cual, como antes, no sale en directo)', $aNightOld['status'] !== 'live', json_encode($aNightOld));

/* ------------------------------------------------------------------ */
$Section('Registro: cada red con motor tiene el suyo entero, con sus datos');

// Lo que hace falta para que una red aprenda sola (captura, recalibrado, horas estimadas sin conexión y en el mapa):
// sus cinco clases, sus datos aprendidos en su carpeta y su nombre en el emparejador. Lo crea new-engine.php.
foreach (Core\Networks::all() as $sSlug => $aNet) {
    if (empty($aNet['engine'])) {
        continue;
    }
    $sSpace = 'Engine\\' . ucfirst($aNet['engine']) . '\\';
    $aMissing = array_filter(['Live', 'PaceFactors', 'SegmentTimes', 'TripOffsets', 'RealtimeMatcher'], fn($sClass) => !class_exists($sSpace . $sClass));
    $Expect("$sSlug: las cinco clases de su motor", $aMissing === [], implode(', ', $aMissing));
    if ($aMissing !== []) {
        continue;
    }
    $sDataDir = realpath(__DIR__ . '/../engine/' . $aNet['engine']) . '/data';
    $aWrongData = array_filter(['PaceFactors', 'SegmentTimes', 'TripOffsets'], fn($sClass) => dirname(($sSpace . $sClass)::defaultPath()) !== $sDataDir);
    $Expect("$sSlug: lo aprendido, en su carpeta y no en la de otra red", $aWrongData === [], implode(', ', $aWrongData));
    $sMatcherNetwork = (new ReflectionClassConstant($sSpace . 'RealtimeMatcher', 'NETWORK'))->getValue();
    $Expect("$sSlug: el emparejador lleva su nombre", $sMatcherNetwork === $sSlug, $sMatcherNetwork);
}

$Finish('algoritmo');
