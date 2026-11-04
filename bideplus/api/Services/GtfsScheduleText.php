<?php

namespace Services;

use Models\ServiceJourney;

// «Ver horario oficial» sacado del GTFS de la red, para las que no publican el suyo en texto (todas menos Bizkaibus).
// Los días del periodo publicado se juntan por horario igual (de lunes a viernes, sábados, domingos y festivos…) y cada
// bloque lleva las salidas de cabecera de cada sentido, con el mismo formato que el del operador.
final class GtfsScheduleText
{
    private const MAX_DAYS = 42;
    private const DAY_SECONDS = 86400;
    // El nombre de un grupo según los días de la semana que son suyos (1 = lunes … 7 = domingo).
    private const DAY_SETS = [
        '1,2,3,4,5' => 'app.days.weekdays',
        '1,2,3,4,5,6' => 'app.days.monToSat',
        '6' => 'app.days.saturdays',
        '7' => 'app.days.sundays',
        '6,7' => 'app.days.weekends',
        '1,2,3,4,5,6,7' => 'app.days.everyDay',
    ];
    // Con algún día suelto más (los festivos entre semana), el domingo pasa a «domingos y festivos».
    private const WITH_HOLIDAYS = [
        'app.days.sundays' => 'app.days.sundaysHolidays',
        'app.days.weekends' => 'app.days.weekendsHolidays',
    ];

    public function __construct(private ServiceJourney $JourneyModel, private bool $bLastStopHeadsign)
    {
    }

    // Los bloques de una línea desde hoy hasta el final del horario publicado (como mucho seis semanas).
    public function forLine(string $sLineId, \DateTime $Today, string|null $sLastDay): array
    {
        $aGroups = [];
        $aWeekdays = [];
        foreach (self::dates($Today, $sLastDay) as $Date) {
            $aWeekdays[$Date->format('N')][] = $Date;
            $aDirections = $this->departures($sLineId, $Date);
            if ($aDirections === []) {
                continue;
            }
            $sKey = json_encode($aDirections);
            $aGroups[$sKey]['directions'] = $aDirections;
            $aGroups[$sKey]['dates'][] = $Date;
        }
        // El mismo orden de sentidos en todos los bloques: primero el que más salidas tiene en todo el periodo.
        $aTotals = [];
        foreach ($aGroups as $aGroup) {
            foreach ($aGroup['directions'] as $sHeadsign => $aTimes) {
                $aTotals[$sHeadsign] = ($aTotals[$sHeadsign] ?? 0) + count($aTimes) * count($aGroup['dates']);
            }
        }
        arsort($aTotals);
        $aBlocks = [];
        foreach ($aGroups as $aGroup) {
            $aBlocks[] = self::block($aGroup, array_keys($aTotals), $aWeekdays, $Today);
        }
        return $aBlocks;
    }

    private static function dates(\DateTime $Today, string|null $sLastDay): array
    {
        $iLast = $Today->getTimestamp() + (self::MAX_DAYS - 1) * self::DAY_SECONDS;
        if ($sLastDay !== null) {
            $iLast = min($iLast, (new \DateTime($sLastDay, $Today->getTimezone()))->getTimestamp());
        }
        $aDates = [];
        for ($Date = clone $Today; $Date->getTimestamp() <= $iLast; $Date = (clone $Date)->modify('+1 day')) {
            $aDates[] = $Date;
        }
        return $aDates;
    }

    // [sentido => [salidas «HH:MM» de la cabecera]] de un día, en un orden fijo para comparar días.
    private function departures(string $sLineId, \DateTime $Date): array
    {
        $aDirections = [];
        foreach ($this->JourneyModel->timetableForLine($sLineId, $Date, 0, 2 * self::DAY_SECONDS - 1) as $aRow) {
            $sHeadsign = (string)$aRow['headsign'];
            if ($this->bLastStopHeadsign && !empty($aRow['last_stop_name'])) {
                $sHeadsign = $aRow['last_stop_name'];
            }
            $aDirections[$sHeadsign][] = Calendar::secondsToHm((int)$aRow['departure_seconds']);
        }
        ksort($aDirections);
        // Dos autobuses a la misma hora (un refuerzo) son una sola salida para quien espera.
        return array_map(fn($aTimes) => array_values(array_unique($aTimes)), $aDirections);
    }

    // Un bloque como los del operador: el nombre de los días, desde y hasta, y las salidas de ida y de vuelta. La ida
    // es el primer sentido del orden de la línea; los demás, la vuelta.
    private static function block(array $aGroup, array $aOrder, array $aWeekdays, \DateTime $Today): array
    {
        $aTexts = [];
        foreach ($aOrder as $sHeadsign) {
            if (isset($aGroup['directions'][$sHeadsign])) {
                $aTexts[] = '→ ' . $sHeadsign . "\n" . implode('  ', $aGroup['directions'][$sHeadsign]);
            }
        }
        $aDates = $aGroup['dates'];
        $sCurrent = '0';
        if (in_array($Today->format('Y-m-d'), array_map(fn($Date) => $Date->format('Y-m-d'), $aDates), true)) {
            $sCurrent = '1';
        }
        return [
            'TEMPORADA' => self::label($aDates, $aWeekdays),
            'PERIODO_DESDE' => $aDates[0]->format('d/m/Y'),
            'PERIODO_HASTA' => end($aDates)->format('d/m/Y'),
            'HORARIO_IDA_CAS' => array_shift($aTexts),
            'HORARIO_VUELTA_CAS' => implode("\n\n", $aTexts),
            'ACTUAL' => $sCurrent,
        ];
    }

    // El nombre de los días del grupo, como clave de traducción («i18n:…»): sus días de la semana son los que tiene en
    // más de la mitad de las fechas; las demás son días sueltos (festivos o especiales).
    private static function label(array $aDates, array $aWeekdays): string
    {
        $aCount = [];
        foreach ($aDates as $Date) {
            $aCount[$Date->format('N')] = ($aCount[$Date->format('N')] ?? 0) + 1;
        }
        $aOwn = array_keys(array_filter($aCount, fn($iCount, $sDay) => $iCount * 2 > count($aWeekdays[$sDay]), ARRAY_FILTER_USE_BOTH));
        sort($aOwn);
        if ($aOwn === []) {
            return 'i18n:app.days.special';
        }
        $sName = self::DAY_SETS[implode(',', $aOwn)] ?? null;
        if ($sName === null) {
            return 'i18n-days:' . implode(',', $aOwn);
        }
        if (count($aOwn) < count($aCount) && isset(self::WITH_HOLIDAYS[$sName])) {
            $sName = self::WITH_HOLIDAYS[$sName];
        }
        return 'i18n:' . $sName;
    }
}
