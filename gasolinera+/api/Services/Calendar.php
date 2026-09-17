<?php

namespace Services;

class Calendar
{
    
    public static function todayMadrid(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
    }
}
