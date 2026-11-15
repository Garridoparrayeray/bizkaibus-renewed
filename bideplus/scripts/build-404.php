<?php

// Genera la página de error (404.html en castellano y 404-eu.html, 404-en.html y 404-fr.html) con un enlace a cada app
// de api/Networks, en el orden del menú. Son ficheros estáticos que sirve Core\NotFound en el idioma de la dirección;
// se vuelven a generar al añadir una red (scripts/new-network.php). Los textos, de js/i18n.js.
// Uso: php scripts/build-404.php [--output=ruta del castellano; los demás, a su lado con -eu, -en y -fr]

declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';

use Core\Lang;
use Core\Networks;
use Core\NotFound;

$aOptions = getopt('', ['output:']);
$sOutput = $aOptions['output'] ?? __DIR__ . '/../404.html';

$Escape = fn(string $sText): string => htmlspecialchars($sText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
foreach (Lang::LANGS as $sLang) {
    $aLinks = [];
    foreach (Networks::all() as $sSlug => $aNetwork) {
        $sHref = Lang::url('/?red=' . rawurlencode($sSlug), $sLang);
        $aLinks[] = '                <li><a href="' . $Escape($sHref) . '">' . $Escape(Networks::text($aNetwork, 'name', $sLang)) . '</a></li>';
    }
    $sLinks = implode("\n", $aLinks);
    $T = fn(string $sKey): string => $Escape(Lang::t($sKey, $sLang));
    $sHome = $Escape(Lang::url('/', $sLang));

    $sHtml = <<<HTML
<!DOCTYPE html>
<!-- Generado por scripts/build-404.php desde api/Networks: no se edita a mano. -->
<html lang="{$sLang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{$T('notFound.title')}</title>
    <meta name="description" content="{$T('notFound.description')}">
    <meta name="robots" content="noindex, follow">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="icon" href="/icons-bide-rojo/bide-icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons-bide-rojo/apple-touch-icon.png">
    <link rel="manifest" href="/manifest-bide.json">
    <link rel="preload" href="/fonts/hanken-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/css/style-networks.css">
    <link rel="stylesheet" href="/css/style-menu.css">
</head>
<body>
    <main>
        <header>
            <a href="{$sHome}"><img src="/icons-bide-rojo/bide-wordmark-mayusculas.svg" alt="{$T('notFound.logo')}" width="136" height="44"></a>
        </header>

        <section class="notfound">
            <h1>{$T('notFound.heading')}</h1>
            <p>{$T('notFound.text')}</p>
            <a class="back" href="{$sHome}">{$T('notFound.back')}</a>
            <ul aria-label="{$T('menu.section')}">
{$sLinks}
            </ul>
        </section>
    </main>
</body>
</html>

HTML;

    file_put_contents(NotFound::fileFor($sOutput, $sLang), $sHtml);
}
echo \count($aLinks) . ' apps en ' . \count(Lang::LANGS) . " idiomas\n";
