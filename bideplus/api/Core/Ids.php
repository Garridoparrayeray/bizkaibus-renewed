<?php

namespace Core;

class Ids
{
    public static function forOutput(string|int|null $Value): string|int|null
    {
        if (is_string($Value) && ctype_digit($Value) && (strlen($Value) === 1 || $Value[0] !== '0')) {
            return (int)$Value;
        }
        return $Value;
    }
}
