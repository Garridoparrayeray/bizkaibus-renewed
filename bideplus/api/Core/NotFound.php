<?php

namespace Core;

// La página de error en el idioma de la dirección (?lang=): 404.html y sus versiones de scripts/build-404.php, con
// noindex.
class NotFound
{
    private const PAGE = __DIR__ . '/../../404.html';

    public static function send(string $sLang): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        // Que nadie la guarde: la de un fichero que falta no puede quedarse con la caché immutable de su ruta.
        header('Cache-Control: no-store');
        readfile(self::fileFor(self::PAGE, $sLang));
    }

    // La del castellano es la de siempre (404.html); las demás, a su lado: 404-eu.html, 404-en.html, 404-fr.html.
    public static function fileFor(string $sSpanishPath, string $sLang): string
    {
        if ($sLang === 'es') {
            return $sSpanishPath;
        }
        return preg_replace('/\.html$/', '-' . $sLang . '.html', $sSpanishPath);
    }
}
