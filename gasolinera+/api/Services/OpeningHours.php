<?php

namespace Services;

class OpeningHours
{
    private const DAY_CODES = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    
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

        $isoWeekday = (int)$when->format('N'); 
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
        
        return $minutesNow >= $fromMinutes || $minutesNow < $toMinutes;
    }

    
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
