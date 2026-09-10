<?php

namespace Services;

class Calendar
{
    /** Fecha/hora actual en zona horaria de España, para no depender de la zona horaria del servidor. */
    public static function todayMadrid(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
    }
}
