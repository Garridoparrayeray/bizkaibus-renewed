<?php

namespace Services;

/**
 * Parseo heurístico del campo "Horario" del feed, texto libre no
 * estructurado. Verificado contra datos reales del feed que el separador
 * entre bloques de días es ";" (ej. "L-V: 07:00-22:00; S-D: 09:00-14:00"), y
 * que un mismo día puede tener horario partido con varias franjas separadas
 * por "Y" (ej. "L-V: 08:00-14:00 Y 15:00-20:00", cierre a mediodía). No
 * pretende cubrir el 100% de los formatos reales: cuando un horario no
 * encaja en ningún patrón reconocido, isOpenAt() devuelve null en vez de
 * adivinar, y describe() cae al texto crudo bajo "ver horario completo".
 * Nunca se inventa un estado "abierto"/"cerrado" sin evidencia clara en el
 * propio texto.
 */
class OpeningHours
{
    private const DAY_CODES = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    /**
     * true si el horario indica apertura 24 horas todos los días, false si
     * el patrón se reconoce pero no es 24h, null si el texto no encaja en
     * ningún patrón reconocido (no se puede afirmar nada con confianza).
     */
    public static function isOpenAt(string $horarioRaw, \DateTime $when): ?bool
    {
        $normalized = self::normalize($horarioRaw);
        if ($normalized === '') {
            return null;
        }

        if (self::is24h($normalized)) {
            return true;
        }

        $ranges = self::parseRanges($normalized);
        if ($ranges === null) {
            return null;
        }

        $isoWeekday = (int)$when->format('N'); // 1=lunes..7=domingo
        $minutesNow = ((int)$when->format('H')) * 60 + (int)$when->format('i');

        foreach ($ranges as $range) {
            if (!in_array($isoWeekday, $range['days'], true)) {
                continue;
            }
            if (self::minutesWithinRange($minutesNow, $range['fromMinutes'], $range['toMinutes'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Descripción legible del estado actual: "Abierto 24h", "Abierto, cierra
     * a las HH:MM", "Cerrado, abre a las HH:MM", o, si el horario no se
     * reconoce, el texto crudo del feed marcado como no interpretado.
     */
    public static function describe(string $horarioRaw, \DateTime $when): array
    {
        $normalized = self::normalize($horarioRaw);
        if ($normalized === '') {
            return ['estado' => 'desconocido', 'texto' => 'Horario no disponible', 'raw' => $horarioRaw];
        }

        if (self::is24h($normalized)) {
            return ['estado' => 'abierto', 'texto' => 'Abierto 24 horas', 'raw' => $horarioRaw];
        }

        $ranges = self::parseRanges($normalized);
        if ($ranges === null) {
            return ['estado' => 'desconocido', 'texto' => $horarioRaw, 'raw' => $horarioRaw];
        }

        $isOpen = self::isOpenAt($horarioRaw, $when);
        if ($isOpen === true) {
            $closesAt = self::nextBoundary($ranges, $when, true);
            if ($closesAt !== null) {
                return ['estado' => 'abierto', 'texto' => 'Abierto, cierra a las ' . $closesAt, 'raw' => $horarioRaw];
            }
            return ['estado' => 'abierto', 'texto' => 'Abierto ahora', 'raw' => $horarioRaw];
        }

        $opensAt = self::nextBoundary($ranges, $when, false);
        if ($opensAt !== null) {
            return ['estado' => 'cerrado', 'texto' => 'Cerrado, abre a las ' . $opensAt, 'raw' => $horarioRaw];
        }
        return ['estado' => 'cerrado', 'texto' => 'Cerrado ahora', 'raw' => $horarioRaw];
    }

    private static function normalize(string $raw): string
    {
        return mb_strtoupper(trim($raw), 'UTF-8');
    }

    private static function is24h(string $normalized): bool
    {
        if (str_contains($normalized, '24H') || str_contains($normalized, '24 H')) {
            return true;
        }
        if (str_contains($normalized, '00:00-24:00') || str_contains($normalized, '00:00-23:59')) {
            return true;
        }
        return false;
    }

    /**
     * Parsea patrones tipo "L-D: 07:00-22:00" o "L-V: 07:00-22:00, S-D:
     * 08:00-21:00" a una lista de {days: int[], fromMinutes, toMinutes}. Los
     * bloques de días se separan con ";" (verificado contra datos reales del
     * feed: la coma NO es el separador entre días, aparece dentro de otros
     * contextos). Dentro de un mismo bloque puede haber varias franjas del
     * mismo día separadas por la palabra "Y" (horario partido con cierre a
     * mediodía, ej. "L-V: 08:00-14:00 Y 15:00-20:00", muy común en el feed
     * real), cada una se expande a su propia entrada de rango. Un rango con
     * fromMinutes > toMinutes se interpreta como que cruza medianoche (ej.
     * 22:00-02:00). Devuelve null si el texto no encaja en este patrón (no
     * se intenta adivinar más allá de lo que el propio formato permite
     * reconocer con confianza).
     */
    private static function parseRanges(string $normalized): ?array
    {
        $blocks = explode(';', $normalized);
        $ranges = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if (!preg_match('/^([LMXJVSD])(?:-([LMXJVSD]))?\s*:\s*(.+)$/', $block, $blockMatch)) {
                return null;
            }
            $fromDay = $blockMatch[1];
            $toDay = $fromDay;
            if ($blockMatch[2] !== '') {
                $toDay = $blockMatch[2];
            }
            $days = self::dayRange($fromDay, $toDay);
            if ($days === null) {
                return null;
            }

            $timeSpans = preg_split('/\s+Y\s+/u', trim($blockMatch[3]));
            foreach ($timeSpans as $span) {
                if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($span), $timeMatch)) {
                    return null;
                }
                $fromMinutes = ((int)$timeMatch[1]) * 60 + (int)$timeMatch[2];
                $toMinutes = ((int)$timeMatch[3]) * 60 + (int)$timeMatch[4];
                $ranges[] = ['days' => $days, 'fromMinutes' => $fromMinutes, 'toMinutes' => $toMinutes];
            }
        }

        if (empty($ranges)) {
            return null;
        }
        return $ranges;
    }

    /** @return int[]|null Días ISO (1=lunes..7=domingo) entre $fromCode y $toCode, en el orden habitual L-M-X-J-V-S-D. */
    private static function dayRange(string $fromCode, string $toCode): ?array
    {
        $fromIdx = array_search($fromCode, self::DAY_CODES, true);
        $toIdx = array_search($toCode, self::DAY_CODES, true);
        if ($fromIdx === false || $toIdx === false) {
            return null;
        }
        $days = [];
        $i = $fromIdx;
        while (true) {
            $days[] = $i + 1;
            if ($i === $toIdx) {
                break;
            }
            $i = ($i + 1) % 7;
        }
        return $days;
    }

    private static function minutesWithinRange(int $minutesNow, int $fromMinutes, int $toMinutes): bool
    {
        if ($fromMinutes <= $toMinutes) {
            return $minutesNow >= $fromMinutes && $minutesNow < $toMinutes;
        }
        // Cruza medianoche (ej. 22:00-02:00): abierto si es después del inicio O antes del final.
        return $minutesNow >= $fromMinutes || $minutesNow < $toMinutes;
    }

    /**
     * Hora del próximo límite relevante, formateada "HH:MM". Con
     * $seekingClose=true busca la franja que contiene AHORA MISMO (para
     * "cierra a las"), necesario porque un horario partido (ej. cierre a
     * mediodía) tiene varias franjas el mismo día y hay que identificar en
     * cuál se está, no la primera del día. Con $seekingClose=false busca la
     * próxima franja futura, hoy o en los siguientes días de la semana
     * (hasta 7 días vista), para "abre a las". null si no hay ninguna
     * franja que aplique dentro de ese horizonte.
     */
    private static function nextBoundary(array $ranges, \DateTime $when, bool $seekingClose): ?string
    {
        $isoWeekday = (int)$when->format('N');
        $minutesNow = ((int)$when->format('H')) * 60 + (int)$when->format('i');

        if ($seekingClose) {
            foreach ($ranges as $range) {
                if (!in_array($isoWeekday, $range['days'], true)) {
                    continue;
                }
                if (self::minutesWithinRange($minutesNow, $range['fromMinutes'], $range['toMinutes'])) {
                    $minutes = $range['toMinutes'];
                    return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
                }
            }
            return null;
        }

        // Buscar la próxima franja futura: primero hoy con inicio posterior
        // a ahora, y si no hay ninguna, la primera franja de los próximos
        // días (empezando por mañana), tomando la de inicio más temprano.
        $todayCandidates = [];
        foreach ($ranges as $range) {
            if (in_array($isoWeekday, $range['days'], true) && $range['fromMinutes'] > $minutesNow) {
                $todayCandidates[] = $range['fromMinutes'];
            }
        }
        if (!empty($todayCandidates)) {
            $minutes = min($todayCandidates);
            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }

        for ($offset = 1; $offset <= 7; $offset++) {
            $futureWeekday = (($isoWeekday - 1 + $offset) % 7) + 1;
            $dayCandidates = [];
            foreach ($ranges as $range) {
                if (in_array($futureWeekday, $range['days'], true)) {
                    $dayCandidates[] = $range['fromMinutes'];
                }
            }
            if (!empty($dayCandidates)) {
                $minutes = min($dayCandidates);
                return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
            }
        }
        return null;
    }
}
