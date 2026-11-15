<?php
// Caducidad del horario publicado de cada red: los avisos y errores del resumen del flujo diario
// (scripts/published-schedule.php).

declare(strict_types=1);

namespace Build;

use DateTime;

class Expiry
{
    // Avisa con este margen (el de antes en Bizkaibus); falla con el segundo en las obligatorias.
    public const WARN_DAYS_LEFT = 10;
    public const FAIL_DAYS_LEFT = 7;
    // Una red que hoy no se pudo reconstruir conserva su última base buena: falla si caduca en estos días o menos.
    public const KEPT_FAIL_DAYS_LEFT = 7;
    // Una copia de respaldo publicada hace más de esto (más que el tiempo normal entre dos horarios) ya no vale.
    public const FALLBACK_MAX_AGE_DAYS = 45;

    // [errores, avisos]. Las redes de horario corto que se renueva a diario (Euskotren y los tranvías, +5 días) bajan
    // en su ficha todos los márgenes (gtfs.expiry_alert_days), también el de la última base buena conservada.
    public static function problems(array $aNetwork, array $aRow, bool $bKept, string $sToday): array
    {
        $aErrors = [];
        $aWarnings = [];
        $iWarnDays = $aNetwork['gtfs']['expiry_alert_days'] ?? self::WARN_DAYS_LEFT;
        $iFailDays = $aNetwork['gtfs']['expiry_alert_days'] ?? self::FAIL_DAYS_LEFT;
        $iKeptDays = $aNetwork['gtfs']['expiry_alert_days'] ?? self::KEPT_FAIL_DAYS_LEFT;
        if ($aRow['source'] === 'fallback') {
            $aWarnings[] = 'fuente principal caída, se usa el respaldo';
            // De cuándo es el GTFS (el día del zip o su feed_start_date); si no se sabe, el día que dice publicado.
            $sIssued = $aRow['issued'] ?? $aRow['published'];
            $iAge = 0;
            if ($sIssued !== '?') {
                $iAge = self::daysBetween($sIssued, $sToday);
            }
            if ($iAge > self::FALLBACK_MAX_AGE_DAYS) {
                $aErrors[] = "el respaldo es del $sIssued, de hace $iAge días";
            }
        }
        if ($aRow['to'] === null) {
            $aWarnings[] = 'la base no dice hasta cuándo vale su horario';
            return [$aErrors, $aWarnings];
        }
        $iLeft = self::daysBetween($sToday, $aRow['to']);
        $sEnds = "su horario acaba el {$aRow['to']} (quedan $iLeft días)";
        if ($iLeft < 0) {
            $sEnds = "su horario caducó el {$aRow['to']}";
        }
        if ($bKept && $iLeft <= $iKeptDays) {
            $aErrors[] = "hoy no se pudo reconstruir y la última base buena caduca pronto: $sEnds";
        } elseif (!empty($aNetwork['gtfs']['required']) && $iLeft <= $iFailDays) {
            $aErrors[] = "red obligatoria a punto de quedarse sin horario: $sEnds";
        } elseif ($iLeft <= $iWarnDays) {
            $aWarnings[] = $sEnds;
        }
        if ($bKept) {
            $aWarnings[] = 'hoy no se pudo reconstruir: sigue con su última base buena';
        }
        return [$aErrors, $aWarnings];
    }

    public static function daysBetween(string $sFrom, string $sTo): int
    {
        return (int)(new DateTime($sFrom))->diff(new DateTime($sTo))->format('%r%a');
    }
}
