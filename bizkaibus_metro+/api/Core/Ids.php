<?php

namespace Core;

/**
 * Bus/Metro usan ids GTFS numéricos ("184", "3223"); Euskotren usa ids NeTEx
 * con dos puntos ("ES:Euskotren:Quay:1471_Plataforma_Q1:"). Todas las
 * columnas de id son TEXT en sqlite (misma representación para las tres
 * redes), así que la única diferencia real está al construir la respuesta
 * JSON: bus/metro deben seguir saliendo como número (mismo contrato de API
 * que antes de Euskotren+, no romper a los clientes existentes), Euskotren
 * sale como string porque nunca fue numérico.
 */
class Ids
{
    public static function forOutput(string|int|null $Value): string|int|null
    {
        if ($Value === null) {
            return null;
        }
        if (is_int($Value)) {
            return $Value;
        }
        if (ctype_digit($Value)) {
            return (int)$Value;
        }
        return $Value;
    }
}
