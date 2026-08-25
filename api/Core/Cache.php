<?php

namespace Core;

/**
 * Caché de fichero mínima en el temp del sistema (en Vercel /tmp es la única
 * ruta con permiso de escritura en runtime; funciona igual en local).
 * Nunca es la fuente de verdad: todo caller debe poder recalcular si falla.
 */
class Cache
{
    /**
     * Devuelve el valor cacheado bajo $key si existe y no ha caducado; si no,
     * ejecuta $producer(), guarda el resultado y lo devuelve. Usado por los
     * clientes de Services/ para no golpear SIRI ni el CMS de Metro Bilbao en
     * cada petición.
     */
    public static function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $file = sys_get_temp_dir() . '/bizkaibusplus_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key) . '.json';

        if (is_file($file) && (time() - filemtime($file)) < $ttlSeconds) {
            $cached = json_decode((string)file_get_contents($file), true);
            if ($cached !== null) {
                return $cached;
            }
        }

        $value = $producer();
        file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $value;
    }
}
