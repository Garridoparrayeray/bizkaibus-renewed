<?php

namespace Core;

// «Nuevo» durante unos días: una red desde que llega (added en su ficha) y una función desde que sale
// (data-new-since="AAAA-MM-DD" en su botón o tarjeta, ver js/new-badge.js). Los días, en new_days de Config/site.php.
final class NewBadge
{
    private const DEFAULT_DAYS = 30;

    public static function days(): int
    {
        $aSite = require __DIR__ . '/../Config/site.php';
        return (int)($aSite['new_days'] ?? self::DEFAULT_DAYS);
    }

    // Nueva hasta new_days días después de $sSince (AAAA-MM-DD), en hora de Madrid. Sin fecha, no.
    public static function isNew(string|null $sSince, \DateTime|null $Today = null): bool
    {
        if ($sSince === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sSince)) {
            return false;
        }
        $Today ??= new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
        $sUntil = (new \DateTime($sSince))->modify('+' . (self::days() - 1) . ' days')->format('Y-m-d');
        return $Today->format('Y-m-d') <= $sUntil;
    }
}
