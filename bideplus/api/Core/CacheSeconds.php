<?php

namespace Core;

// Cuánto puede servir la caché de Vercel una respuesta antes de volver a pedirla.
final class CacheSeconds
{
    // Directo: salidas, vehículos y mapa de la línea.
    public const LIVE = 10;
    public const STATUS = 30;
    // Lo que cambia con el directo pero no a cada segundo: ficha del viaje, horario del día e incidencias.
    public const MINUTE = 60;
    // Solo cambia al desplegar, y cada despliegue vacía la caché.
    public const VERSION = 600;
    // Paradas, líneas, buscador y páginas: cambian con la reconstrucción diaria.
    public const STATIC = 3600;
    public const DAY = 86400;
}
