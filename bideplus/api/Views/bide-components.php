<?php

const BIDE_APPS = [
    ['bus', 'Bizkaibus+', 'icons-pro'],
    ['metro', 'Metro+', 'icons-metro'],
    ['euskotren', 'Euskotren+', 'icons-euskotren'],
    ['renfe', 'Renfe Cercanías+', 'icons-renfe'],
    ['tranvia-bilbao', 'Tranvía Bilbao+', 'icons-tranvia-bilbao'],
    ['tranvia-vitoria', 'Tranvía Vitoria+', 'icons-tranvia-vitoria'],
];

const BIDE_ICONS = [
    'apps' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/></svg>',
    'favoritos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
    'avisos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><line x1="12" y1="11" x2="12" y2="16.5"/><line x1="12" y1="7.5" x2="12.01" y2="7.5"/></svg>',
    'back' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 6 9 12 15 18"/></svg>',
    'next' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>',
    'down' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>',
    'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>',
];

function bideTabbar(): string
{
    $aTabs = [
        ['apps', 'bide.tab.apps', 'Apps'],
        ['favoritos', 'bide.tab.favorites', 'Favoritos'],
        ['avisos', 'bide.tab.alerts', 'Avisos'],
        ['info', 'bide.tab.info', 'Info'],
    ];
    $sHtml = '<nav class="bide-tabbar" data-i18n-attr="aria-label:bide.nav" aria-label="Navegación de Bide+">';
    foreach ($aTabs as [$sTab, $sKey, $sLabel]) {
        $sHtml .= '<button type="button" class="bide-tab" data-bide-tab="' . $sTab . '">' . BIDE_ICONS[$sTab] . '<span data-i18n="' . $sKey . '">' . $sLabel . '</span></button>';
    }
    return $sHtml . '</nav>';
}

function bideLangSwitch(): string
{
    return '<div class="bide-seg bide-lang" role="group" data-i18n-attr="aria-label:bide.lang" aria-label="Idioma">'
        . '<button type="button" data-bide-lang="es" aria-pressed="true">ES</button>'
        . '<button type="button" data-bide-lang="eu" aria-pressed="false">EU</button>'
        . '</div>';
}

function bideRail(string|null $sCurrentApp): string
{
    $sHtml = '<aside class="bide-rail">';
    $sHtml .= '<a class="bide-rail-brand" href="/" data-i18n-attr="aria-label:bide.home" aria-label="Bide+, todas las apps"><span class="bide-wordmark">BIDE<span>+</span></span><span data-i18n="menu.tagline">Movilidad en Euskadi</span></a>';
    $sHtml .= '<nav class="bide-rail-nav" data-i18n-attr="aria-label:bide.nav" aria-label="Navegación de Bide+">';
    $sHtml .= '<button type="button" class="bide-rail-item bide-rail-apps" aria-expanded="false">' . BIDE_ICONS['apps'] . '<span data-i18n="bide.allApps">Todas las apps</span><span class="bide-rail-chevron">' . BIDE_ICONS['down'] . '</span></button>';
    $sHtml .= '<div class="bide-rail-applist" hidden>';
    foreach (BIDE_APPS as [$sApp, $sName, $sIcons]) {
        $sCurrent = '';
        if ($sApp === $sCurrentApp) {
            $sCurrent = ' aria-current="page"';
        }
        $sHtml .= '<a class="bide-rail-item" href="/?red=' . $sApp . '"' . $sCurrent . '><img class="bide-app-logo" src="/' . $sIcons . '/icon-192.png" alt="" width="22" height="22">' . $sName . '</a>';
    }
    $sHtml .= '</div>';
    $sHtml .= '<div class="bide-rail-sep"></div>';
    $sHtml .= '<button type="button" class="bide-rail-item" data-bide-tab="avisos">' . BIDE_ICONS['avisos'] . '<span data-i18n="bide.tab.alerts">Avisos</span></button>';
    $sHtml .= '<button type="button" class="bide-rail-item" data-bide-tab="info">' . BIDE_ICONS['info'] . '<span data-i18n="bide.tab.info">Info</span></button>';
    $sHtml .= '</nav>';
    $sHtml .= '<section class="bide-rail-favorites"><h2 data-i18n="app.favorites.title">Tus favoritos</h2><ul class="bide-fav-list" data-bide-favorites="compact"></ul></section>';
    return $sHtml . '</aside>';
}

function bideViews(): string
{
    $sBack = '<button type="button" class="bide-view-back" data-bide-close data-i18n-attr="aria-label:bide.back" aria-label="Volver">' . BIDE_ICONS['back'] . '</button>';
    $sHtml = '<div class="bide-views">';

    $sHtml .= '<section class="bide-view" data-bide-view="favoritos" hidden>';
    $sHtml .= '<header class="bide-view-head">' . $sBack . '<h2 data-i18n="app.favorites.title">Tus favoritos</h2></header>';
    $sHtml .= '<ul class="bide-fav-list" data-bide-favorites="full"></ul>';
    $sHtml .= '<p class="bide-empty" data-bide-favorites-empty hidden data-i18n="bide.favorites.empty">Todavía no tienes favoritos. Entra en una app y guarda una parada, estación o línea con el corazón.</p>';
    $sHtml .= '</section>';

    $sHtml .= '<section class="bide-view" data-bide-view="avisos" hidden>';
    $sHtml .= '<header class="bide-view-head">' . $sBack . '<h2 data-i18n="bide.alerts.title">Avisos de tus favoritos</h2></header>';
    $sHtml .= '<div class="bide-alerts-toggle"></div>';
    $sHtml .= '<div class="bide-alerts-list" data-bide-alerts></div>';
    $sHtml .= '<p class="bide-note" data-i18n="bide.alerts.note">Solo aparecen los avisos de lo que tienes en Favoritos, en todas las apps.</p>';
    $sHtml .= '</section>';

    $sHtml .= '<section class="bide-view" data-bide-view="info" hidden>';
    $sHtml .= '<header class="bide-view-head">' . $sBack . '<h2 data-i18n="info.title">Información</h2></header>';
    $sHtml .= '<div class="bide-info-grid">';
    $sHtml .= '<section class="bide-card"><h3 data-i18n="bide.info.about">Sobre Bide+</h3><p data-i18n="bide.info.aboutText">Horarios y tiempo real del transporte público de Euskadi en una sola app. En Bizkaibus+, las llegadas se calculan con la posición GPS de cada autobús. Proyecto independiente y no oficial, sin relación con los operadores.</p></section>';
    $sHtml .= '<section class="bide-card"><h3 data-i18n="bide.info.sources">Fuentes de datos</h3><p>Bizkaibus (Open Data Bizkaia, CC-BY 4.0) · Metro Bilbao (Open Data Metro Bilbao; tiempo real: Consorcio de Transportes de Bizkaia, CC-BY 4.0) · Euskotren, Tranvía Bilbao y Tranvía Vitoria (Open Data Euskadi, CC-BY 4.0) · Renfe (NAP, Punto de Acceso Nacional de Transporte).</p></section>';
    $sHtml .= '<section class="bide-card"><h3><span data-i18n="app.madeBy">Hecho por</span> Yeray Garrido</h3><p class="bide-links"><a href="https://www.linkedin.com/in/yeray-garrido" target="_blank" rel="noopener noreferrer">LinkedIn</a><a href="https://www.yeraygarrido.dev/" target="_blank" rel="noopener noreferrer">Portfolio</a><a href="https://github.com/Garridoparrayeray/bizkaibus-renewed" target="_blank" rel="noopener noreferrer" data-i18n="info.source">Código abierto en GitHub</a></p></section>';
    $sHtml .= '<button type="button" class="bide-card bide-card-link" data-bide-legal><span data-i18n="app.legalNotice">Aviso legal y privacidad</span>' . BIDE_ICONS['next'] . '</button>';
    $sHtml .= '</div></section>';

    return $sHtml . '</div>';
}

function bideInstallBanner(): string
{
    return '<aside class="bide-install" hidden>'
        . '<span class="bide-install-icon" aria-hidden="true">B<span>+</span></span>'
        . '<span class="bide-install-text"><strong data-i18n="bide.install.title">Instala Bide+</strong><span class="bide-install-hint" data-i18n="bide.install.text">Todas las apps, a pantalla completa y sin navegador.</span></span>'
        . '<button type="button" class="bide-install-accept" data-i18n="install.button">Instalar</button>'
        . '<button type="button" class="bide-install-dismiss" data-i18n-attr="aria-label:install.close" aria-label="Cerrar">' . BIDE_ICONS['close'] . '</button>'
        . '</aside>';
}

function bideAppsLinks(): string
{
    $sHtml = '<nav class="bide-apps-links" data-i18n-attr="aria-label:bide.appsOfBide" aria-label="Apps de Bide+"><p class="bide-footer-title" data-i18n="bide.appsOfBide">Apps de Bide+</p>';
    foreach (BIDE_APPS as [$sApp, $sName]) {
        $sHtml .= '<a href="/?red=' . $sApp . '">' . $sName . '</a>';
    }
    return $sHtml . '</nav>';
}
