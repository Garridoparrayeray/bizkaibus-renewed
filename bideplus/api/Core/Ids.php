<?php

namespace Core;

class Ids
{
    public static function forOutput(string|int|null $Value): string|int|null
    {
        if (is_string($Value) && ctype_digit($Value)) {
            return (int)$Value;
        }
        return $Value;
    }
}
