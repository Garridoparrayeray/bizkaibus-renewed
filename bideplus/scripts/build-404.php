<?php

// Genera 404.html con un enlace a cada app de api/Networks, en el orden del menú. Es un fichero estático (Vercel lo
// sirve tal cual), así que se vuelve a generar al añadir una red (scripts/new-network.php).
// Uso: php scripts/build-404.php [--output=ruta]

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

$aOptions = getopt('', ['output:']);
$sOutput = $aOptions['output'] ?? __DIR__ . '/../404.html';

$aLinks = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    $sName = htmlspecialchars($aNetwork['texts']['es']['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $aLinks[] = '                <li><a href="/?red=' . rawurlencode($sSlug) . '">' . $sName . '</a></li>';
}
$sLinks = implode("\n", $aLinks);

$sHtml = <<<HTML
<!DOCTYPE html>
<!-- Generado por scripts/build-404.php desde api/Networks: no se edita a mano. -->
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Página no encontrada · Bide+</title>
    <meta name="description" content="La página que buscas no existe o ha cambiado de sitio. Vuelve a Bide+ para consultar los horarios y el tiempo real del bus, el metro, el tren y el tranvía de Euskadi.">
    <meta name="robots" content="noindex, follow">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="icon" href="/icons-bide-rojo/bide-icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons-bide-rojo/apple-touch-icon.png">
    <link rel="manifest" href="/manifest-bide.json">
    <link rel="preload" href="/fonts/hanken-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/css/style-menu.css">
</head>
<body>
    <main>
        <header>
            <a href="/"><img src="/icons-bide-rojo/bide-wordmark-mayusculas.svg" alt="Bide+, ir al inicio" width="136" height="44"></a>
        </header>

        <section class="notfound">
            <h1>Esta página no existe</h1>
            <p>Puede que el enlace esté mal escrito o que la página ya no esté disponible. Vuelve al menú y elige una app.</p>
            <a class="back" href="/">Volver a Bide+</a>
            <ul aria-label="Apps de movilidad">
{$sLinks}
            </ul>
        </section>
    </main>
</body>
</html>

HTML;

file_put_contents($sOutput, $sHtml);
echo \count($aLinks) . " apps\n";
