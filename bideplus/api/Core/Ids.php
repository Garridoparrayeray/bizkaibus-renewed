<?php

namespace Core;

// Los ids numéricos salen como números en el JSON (4255, no «4255»); los que empiezan por cero, como texto.
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
