<?php
// Una muestra de cada red para las pruebas de API y de interfaz, sacada del registro (api/Networks) y de su base de
// datos: la parada con más pasos de viajes que circulan hoy (o su estación), una búsqueda que la encuentra, dónde
// está y el primer día que el horario ya no cubre. Una red nueva entra sola. Uso: php tests/network-samples.php (JSON por red: suffix, q,
// stopId, name, lat, lon, panel, live, beyondPublished).

declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';

use Core\Networks;
use Models\Search;
use Services\Calendar;
use Services\ScheduleRange;

$aSamples = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    if (!is_file($aNetwork['db_path'])) {
        continue;
    }
    $Pdo = new PDO('sqlite:' . $aNetwork['db_path']);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Solo viajes que circulan hoy (calendario y excepciones), como el horario de la app: un domingo, la parada con
    // más pasos de la semana puede no tener ninguno.
    $Today = Calendar::todayMadrid();
    $Busiest = $Pdo->prepare('
        SELECT COALESCE(s.station_id, s.id) AS id, COUNT(*) AS n
        FROM passing_times pt
        JOIN stops s ON s.id = pt.stop_id
        JOIN service_journeys sj ON sj.id = pt.service_journey_id
        JOIN service_calendars sc ON sc.id = sj.calendar_id
        WHERE sc.id != \'PRUEBA\'
          AND (
              (sc.weekday_mask & :weekdayBit) != 0
              OR EXISTS (SELECT 1 FROM service_calendar_exceptions e WHERE e.calendar_id = sc.id AND e.date = :date AND e.available = 1)
          )
          AND NOT EXISTS (SELECT 1 FROM service_calendar_exceptions e WHERE e.calendar_id = sc.id AND e.date = :date2 AND e.available = 0)
        GROUP BY COALESCE(s.station_id, s.id)
        ORDER BY n DESC, id
        LIMIT 1
    ');
    $Busiest->execute(['weekdayBit' => Calendar::weekdayBitFor($Today), 'date' => $Today->format('Y-m-d'), 'date2' => $Today->format('Y-m-d')]);
    $aBusiest = $Busiest->fetch(PDO::FETCH_ASSOC);
    if ($aBusiest === false) {
        continue;
    }
    $Stmt = $Pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id = ?');
    $Stmt->execute([$aBusiest['id']]);
    $aStop = $Stmt->fetch(PDO::FETCH_ASSOC);
    $sSuffix = '';
    if ($sSlug !== Networks::defaultSlug()) {
        $sSuffix = 'red=' . $sSlug;
    }
    $sPanel = 'live-card';
    if (!empty($aNetwork['features']['platforms'])) {
        $sPanel = 'platform-panel';
    }
    $aSamples[$sSlug] = [
        'suffix' => $sSuffix,
        'q' => searchWord($aStop['name']),
        'stopId' => (string)$aStop['id'],
        'name' => $aStop['name'],
        'lat' => (float)$aStop['lat'],
        'lon' => (float)$aStop['lon'],
        'panel' => $sPanel,
        'live' => !empty($aNetwork['live']),
        'beyondPublished' => dayAfter(ScheduleRange::forDatabase($Pdo)['to']),
    ];
}

// El día siguiente al último que cubre el horario (o uno lejano si el GTFS no lo dice).
function dayAfter(string|null $sDate): string
{
    if ($sDate === null) {
        return '2099-01-01';
    }
    return (new DateTime($sDate))->modify('+1 day')->format('Y-m-d');
}

// La palabra más larga del nombre, sin tildes ni mayúsculas: lo que escribiría alguien en el buscador.
function searchWord(string $sName): string
{
    $aWords = preg_split('/[^\p{L}\p{N}]+/u', Search::normalize($sName), -1, PREG_SPLIT_NO_EMPTY);
    usort($aWords, fn($sA, $sB) => mb_strlen($sB) <=> mb_strlen($sA));
    return $aWords[0];
}

echo json_encode($aSamples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
