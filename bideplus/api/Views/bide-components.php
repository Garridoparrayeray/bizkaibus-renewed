<?php

require_once __DIR__ . '/../Core/Networks.php';

// [red, nombre, carpeta de iconos] de cada red, en el orden del registro.
function bideApps(): array
{
    $aApps = [];
    foreach (\Core\Networks::all() as $sSlug => $aNetwork) {
        $aApps[] = [$sSlug, \Core\Networks::text($aNetwork, 'name'), $aNetwork['brand']['icons']];
    }
    return $aApps;
}

const BIDE_ICONS = [
    'apps' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/></svg>',
    'favoritos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
    'avisos' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'mapa' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21 3 6"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>',
    'ajustes' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><line x1="12" y1="11" x2="12" y2="16.5"/><line x1="12" y1="7.5" x2="12.01" y2="7.5"/></svg>',
    'back' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 6 9 12 15 18"/></svg>',
    'next' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>',
    'down' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>',
    'screen' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>',
    'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>',
];

// Preguntas frecuentes de Info: [pregunta, respuesta] en castellano; el euskera está en i18n.js (bide.faq.N).
const BIDE_FAQ = [
    ['¿Las llegadas son en tiempo real?', 'Depende de la red. En Bizkaibus+ se calculan con la posición GPS de cada autobús y en Metro+ llegan en directo del Consorcio de Transportes de Bizkaia. En Euskotren, los tranvías, Renfe y Lurraldebus se muestra el horario oficial, con la etiqueta «Solo horario».'],
    ['¿Qué significa «Estimado»?', 'Que el autobús va en servicio pero no envía su posición. La llegada se calcula con el algoritmo de Bide+, a partir de lo que tardan de verdad los autobuses en ese tramo.'],
    ['¿Funciona sin conexión?', 'Sí, si guardas el horario de tus redes en «Horarios sin conexión», en Ajustes. Sin cobertura, las paradas muestran sus próximas salidas según el horario oficial, y el directo vuelve en cuanto hay conexión.'],
    ['¿Cómo instalo Bide+ en el móvil?', 'Instálala desde Chrome o Firefox en Android, o desde Safari en iPhone. En Android, abre Bide+ en Chrome y pulsa «Instalar» en el aviso de abajo o en el menú ⋮ del navegador; en Firefox, menú ⋮ → «Instalar». En iPhone, ábrela en Safari, toca Compartir y elige «Añadir a pantalla de inicio». Otros navegadores, como DuckDuckGo, solo crean un acceso directo a la web: se abre dentro del navegador y sin avisos con la app cerrada.'],
    ['¿Cómo guardo una parada o una línea?', 'Toca el corazón en la parada, la estación o la línea. Tus favoritos salen en la pestaña Favoritos y en todas las apps.'],
    ['¿Cómo me entero de las incidencias?', 'En Avisos ves las incidencias de tus favoritos, y en Ajustes puedes activar que el móvil te avise de cada una nueva, aunque tengas la app cerrada.'],
    ['¿Cada cuánto se actualizan los horarios?', 'Cada día, con los datos oficiales que publican los operadores. En el horario de cada línea ves desde y hasta cuándo es válido.'],
    ['¿Es una app oficial?', 'No. Bide+ es un proyecto independiente que usa los datos abiertos de los operadores. Si hay dudas, vale lo que diga cada operador.'],
];

function bideTabbar(): string
{
    $aTabs = [
        ['apps', 'bide.tab.apps', 'Apps'],
        ['favoritos', 'bide.tab.favorites', 'Favoritos'],
        ['avisos', 'bide.tab.alerts', 'Avisos'],
        ['ajustes', 'bide.tab.settings', 'Ajustes'],
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
        . '<button type="button" data-bide-lang="en" aria-pressed="false">EN</button>'
        . '</div>';
}

function bideRail(string|null $sCurrentApp): string
{
    $sHtml = '<aside class="bide-rail">';
    $sHtml .= '<a class="bide-rail-brand" href="/" data-i18n-attr="aria-label:bide.home" aria-label="Bide+, todas las apps"><span class="bide-wordmark">BIDE<span>+</span></span><span data-i18n="menu.tagline">Movilidad en Euskadi</span></a>';
    $sHtml .= '<nav class="bide-rail-nav" data-i18n-attr="aria-label:bide.nav" aria-label="Navegación de Bide+">';
    $sHtml .= '<button type="button" class="bide-rail-item bide-rail-apps" aria-expanded="false">' . BIDE_ICONS['apps'] . '<span data-i18n="bide.allApps">Todas las apps</span><span class="bide-rail-chevron">' . BIDE_ICONS['down'] . '</span></button>';
    $sHtml .= '<div class="bide-rail-applist" hidden>';
    foreach (bideApps() as [$sApp, $sName, $sIcons]) {
        $sCurrent = '';
        if ($sApp === $sCurrentApp) {
            $sCurrent = ' aria-current="page"';
        }
        $sHtml .= '<a class="bide-rail-item" href="/?red=' . $sApp . '"' . $sCurrent . '><img class="bide-app-logo" src="/' . $sIcons . '/icon-192.png" alt="" width="22" height="22">' . $sName . '</a>';
    }
    $sHtml .= '</div>';
    $sHtml .= '<div class="bide-rail-sep"></div>';
    $sHtml .= '<a class="bide-rail-item" href="/mapa">' . BIDE_ICONS['mapa'] . '<span data-i18n="map.title">Mapa de la red</span></a>';
    $sHtml .= '<button type="button" class="bide-rail-item" data-bide-tab="avisos">' . BIDE_ICONS['avisos'] . '<span data-i18n="bide.tab.alerts">Avisos</span></button>';
    $sHtml .= '<button type="button" class="bide-rail-item" data-bide-tab="ajustes">' . BIDE_ICONS['ajustes'] . '<span data-i18n="bide.tab.settings">Ajustes</span></button>';
    $sHtml .= '<button type="button" class="bide-rail-item" data-bide-tab="info">' . BIDE_ICONS['info'] . '<span data-i18n="bide.tab.info">Info</span></button>';
    $sHtml .= '</nav>';
    $sHtml .= '<section class="bide-rail-favorites"><h2 data-i18n="app.favorites.title">Tus favoritos</h2><ul class="bide-fav-list" data-bide-favorites="compact"></ul></section>';
    return $sHtml . '</aside>';
}

function bideChangelogEntry(array $aEntry): string
{
    [$sIsoDate, $sDate, $iNumber, $sTitle, $sText] = $aEntry;
    return '<article class="bide-changelog-entry"><p class="bide-changelog-head"><time datetime="' . $sIsoDate . '">' . $sDate . '</time><strong data-i18n="bide.changelog.' . $iNumber . '.title">' . $sTitle . '</strong></p>'
        . '<p data-i18n="bide.changelog.' . $iNumber . '.text">' . $sText . '</p></article>';
}

function bideChangelog(): string
{
    $sHtml = '<section class="bide-card bide-changelog"><h3 data-i18n="bide.changelog.title">Novedades</h3>';
    $sHtml .= bideChangelogEntry(\Services\Changelog::ENTRIES[0]);
    $sHtml .= '<details class="bide-changelog-more"><summary><span class="bide-changelog-open" data-i18n="bide.changelog.more">Ver todas las novedades</span><span class="bide-changelog-close" data-i18n="bide.changelog.less">Ver menos</span></summary>';
    foreach (array_slice(\Services\Changelog::ENTRIES, 1) as $aEntry) {
        $sHtml .= bideChangelogEntry($aEntry);
    }
    return $sHtml . '</details></section>';
}

function bideFaq(): string
{
    $sHtml = '<section class="bide-card bide-faq"><h3 data-i18n="bide.faq.title">Preguntas frecuentes</h3>';
    foreach (BIDE_FAQ as $iIndex => [$sQuestion, $sAnswer]) {
        $iNumber = $iIndex + 1;
        $sHtml .= '<details class="bide-faq-item"><summary data-i18n="bide.faq.' . $iNumber . '.q">' . $sQuestion . '</summary>'
            . '<p data-i18n="bide.faq.' . $iNumber . '.a">' . $sAnswer . '</p></details>';
    }
    return $sHtml . '</section>';
}

// Redes cuyo horario se guarda en el móvil para usarlo sin conexión. La lista la rellena js/bide-info.js.
function bideOffline(): string
{
    return '<section class="bide-card bide-offline"><h3 data-i18n="bide.offline.title">Horarios sin conexión</h3>'
        . '<p data-i18n="bide.offline.text">Guarda en el móvil el horario oficial de las redes que uses. Sin cobertura, las paradas mostrarán sus próximas salidas según el horario. Se actualiza solo cuando tienes conexión.</p>'
        . '<ul class="bide-offline-list" data-bide-offline></ul></section>';
}

// Datos del sitio (api/Config/site.php): nombre, autor, enlaces y correo de contacto.
function bideSite(): array
{
    static $aSite = null;
    if ($aSite === null) {
        $aSite = require __DIR__ . '/../Config/site.php';
    }
    return $aSite;
}

function bideAuthorLinks(): string
{
    $aSite = bideSite();
    $sHtml = '';
    foreach ($aSite['author_links'] as $sLabel => $sUrl) {
        $sHtml .= '<a href="' . htmlspecialchars($sUrl) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($sLabel) . '</a>';
    }
    return $sHtml . '<a href="' . htmlspecialchars($aSite['source_url']) . '" target="_blank" rel="noopener noreferrer" data-i18n="info.source">Código abierto en GitHub</a>';
}

// Pie del menú y de las apps: Ajustes e Info (en el navegador del móvil no hay barra de pestañas), el autor y el aviso
// legal, que cada página abre a su manera.
function bideFooterAuthor(string $sLegalButton): string
{
    return '<div class="bide-footer-author"><p class="bide-footer-title"><span data-i18n="app.madeBy">Hecho por</span> ' . htmlspecialchars(bideSite()['author']) . '</p>'
        . '<p class="bide-footer-links">'
        . '<button type="button" class="bide-footer-legal" data-bide-tab="ajustes" data-i18n="bide.tab.settings">Ajustes</button>'
        . '<button type="button" class="bide-footer-legal" data-bide-tab="info" data-i18n="bide.tab.info">Info</button>'
        . bideAuthorLinks() . $sLegalButton . '</p></div>';
}

// Sugerencias y errores: el mensaje se manda por correo desde el móvil de quien escribe (o se copia).
function bideFeedback(): string
{
    $aSite = bideSite();
    $sTypes = '';
    foreach (['suggestion' => 'Sugerencia', 'error' => 'Error', 'other' => 'Otro'] as $sType => $sLabel) {
        $sPressed = 'false';
        if ($sType === 'suggestion') {
            $sPressed = 'true';
        }
        $sTypes .= '<button type="button" data-feedback-type="' . $sType . '" aria-pressed="' . $sPressed . '" data-i18n="bide.feedback.' . $sType . '">' . $sLabel . '</button>';
    }
    return '<section class="bide-card bide-feedback" data-feedback-email="' . htmlspecialchars($aSite['contact_email']) . '" data-feedback-version="' . htmlspecialchars(bideVersion()) . '">'
        . '<h3 data-i18n="bide.feedback.title">Sugerencias y errores</h3>'
        . '<p data-i18n="bide.feedback.text">¿Algo no funciona o se te ocurre una mejora? Cuéntamelo y lo reviso.</p>'
        . '<div class="bide-seg bide-feedback-types" role="group" data-i18n-attr="aria-label:bide.feedback.type" aria-label="Tipo de mensaje">' . $sTypes . '</div>'
        . '<label class="bide-feedback-field"><span class="bide-visually-hidden" data-i18n="bide.feedback.message">Mensaje</span>'
        . '<textarea maxlength="1500" rows="5" data-feedback-text data-i18n-attr="placeholder:bide.feedback.placeholder" placeholder="Cuéntame qué ha pasado o qué te gustaría, y en qué pantalla."></textarea>'
        . '<span class="bide-feedback-count" data-feedback-count>0 / 1500</span></label>'
        . '<label class="bide-feedback-tech"><input type="checkbox" checked data-feedback-tech><span><strong data-i18n="bide.feedback.tech">Incluir datos técnicos</strong><small data-i18n="bide.feedback.techHint">Versión, sistema y pantalla. Nunca tu ubicación ni tus datos.</small></span></label>'
        . '<div class="bide-feedback-actions"><button type="button" class="bide-button" data-feedback-send data-i18n="bide.feedback.send">Enviar por correo</button>'
        . '<button type="button" class="bide-button is-secondary" data-feedback-copy data-i18n="bide.feedback.copy">Copiar mensaje</button></div>'
        . '<p class="bide-feedback-status" data-feedback-status role="status"></p></section>';
}

// La versión que se ve en Ajustes y va en los avisos de error: el número y la fecha de la última novedad y, en Vercel,
// el commit publicado, para saber exactamente qué código tiene quien avisa de un fallo.
function bideVersion(): string
{
    [, $sDate, $iNumber] = \Services\Changelog::ENTRIES[0];
    $sVersion = $iNumber . ' · ' . $sDate;
    $sCommit = (string)getenv('VERCEL_GIT_COMMIT_SHA');
    if ($sCommit !== '') {
        $sVersion .= ' · ' . substr($sCommit, 0, 7);
    }
    return $sVersion;
}

// Ajustes. Lo que cambia lo rellenan js/settings.js, js/app-update.js y js/incident-alerts.js.
function bideSettings(string $sBack): string
{
    $sHtml = '<section class="bide-view" data-bide-view="ajustes" hidden>';
    $sHtml .= '<header class="bide-view-head">' . $sBack . '<h2 data-i18n="bide.settings.title">Ajustes</h2></header>';
    $sHtml .= '<div class="bide-info-grid">';

    $sHtml .= '<section class="bide-card"><h3 data-i18n="bide.settings.lang">Idioma</h3>' . bideLangSwitch() . '</section>';

    $sHtml .= '<section class="bide-card bide-settings-notifications"><h3 data-i18n="bide.settings.notifications">Notificaciones</h3>';
    $sHtml .= '<label class="alerts-toggle"><input type="checkbox" id="alerts-toggle"><span data-i18n="app.notifyMeFavorites">Avisarme de las incidencias nuevas de mis favoritos</span></label>';
    $sHtml .= '<p id="alerts-note" class="alerts-note" role="status" hidden></p>';
    $sHtml .= '<p class="bide-note" data-settings-permission></p>';
    $sHtml .= '<h4 data-i18n="bide.settings.arrivals">Avisos de llegada</h4>';
    $sHtml .= '<ul class="bide-settings-arrivals" data-settings-arrivals></ul>';
    $sHtml .= '<p class="bide-note" data-settings-arrivals-empty data-i18n="bide.settings.arrivalsEmpty">No tienes avisos de llegada activos. Se crean desde la ficha de un viaje.</p>';
    $sHtml .= '</section>';

    $sHtml .= '<section class="bide-card bide-settings-location"><h3 data-i18n="bide.settings.location">Ubicación</h3>';
    $sHtml .= '<p data-i18n="bide.settings.locationText">Para «Cerca de mí»: ver las paradas que tienes al lado.</p>';
    $sHtml .= '<p class="bide-note" data-settings-location role="status"></p>';
    $sHtml .= '<button type="button" class="bide-button is-secondary" data-settings-location-ask data-i18n="bide.settings.locationAsk" hidden>Permitir la ubicación</button>';
    $sHtml .= '</section>';

    $sHtml .= bideOffline();

    $sHtml .= '<section class="bide-card bide-settings-app"><h3 data-i18n="bide.settings.app">La app</h3>';
    $sHtml .= '<div class="bide-update" data-settings-update hidden>';
    $sHtml .= '<p class="bide-update-title"><span class="bide-update-badge" aria-hidden="true">1</span><strong data-i18n="bide.update.title">Nueva actualización de la app</strong></p>';
    $sHtml .= '<p class="bide-note" data-i18n="bide.update.text">Trae estas novedades. Para tenerla, vacía la caché y actualiza.</p>';
    $sHtml .= '<ul class="bide-update-notes" data-settings-update-notes></ul>';
    $sHtml .= '</div>';
    $sHtml .= '<h4 data-i18n="bide.settings.cache">Caché</h4>';
    $sHtml .= '<p data-i18n="bide.settings.cacheText">Si algo no carga o la app se queda con una versión vieja, vacía la caché: se vuelve a descargar entera. Tus favoritos, avisos y horarios sin conexión se quedan.</p>';
    $sHtml .= '<div class="bide-update-action"><button type="button" class="bide-button is-secondary" data-settings-cache data-i18n="bide.settings.cacheButton">Vaciar caché y actualizar</button>';
    $sHtml .= '<span class="bide-update-badge" data-settings-update-badge aria-hidden="true" hidden>1</span></div>';
    $sHtml .= '<p class="bide-feedback-status" data-settings-cache-status role="status"></p>';
    $sHtml .= '<p class="bide-settings-version"><span data-i18n="bide.settings.version">Versión</span> <span data-settings-version>' . htmlspecialchars(bideVersion()) . '</span></p>';
    $sHtml .= '</section>';

    $sHtml .= '<section class="bide-card bide-settings-data"><h3 data-i18n="bide.settings.data">Tus datos</h3>';
    $sHtml .= '<p data-i18n="bide.settings.dataText">Tus favoritos, el idioma, los avisos y los horarios guardados están solo en este móvil, salvo lo justo para mandarte las notificaciones. Puedes borrarlo todo de una vez.</p>';
    $sHtml .= '<button type="button" class="bide-button is-danger" data-settings-delete data-i18n="bide.settings.delete">Borrar mis datos de este móvil</button>';
    $sHtml .= '<p class="bide-feedback-status" data-settings-delete-status role="status"></p>';
    $sHtml .= '</section>';

    return $sHtml . '</div></section>';
}

// Fuentes de datos: la atribución de cada red (api/Networks, campo attribution), que es la que pide cada licencia.
function bideSources(): string
{
    $sHtml = '<section class="bide-card"><h3 data-i18n="bide.info.sources">Fuentes de datos</h3><ul class="bide-sources">';
    foreach (\Core\Networks::all() as $sSlug => $aNetwork) {
        $sHtml .= '<li><strong data-i18n="net.' . $sSlug . '.name">' . htmlspecialchars(\Core\Networks::text($aNetwork, 'name')) . '</strong> ' . htmlspecialchars($aNetwork['attribution']) . '</li>';
    }
    return $sHtml . '</ul></section>';
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
    $sHtml .= '<button type="button" class="bide-card bide-card-link" data-bide-tab="ajustes"><span data-i18n="bide.alerts.settings">Avisos en el móvil, en Ajustes</span>' . BIDE_ICONS['next'] . '</button>';
    $sHtml .= '<div class="bide-alerts-list" data-bide-alerts></div>';
    $sHtml .= '<p class="bide-note" data-i18n="bide.alerts.note">Solo aparecen los avisos de lo que tienes en Favoritos, en todas las apps.</p>';
    $sHtml .= '</section>';

    $sHtml .= bideSettings($sBack);

    $sHtml .= '<section class="bide-view" data-bide-view="info" hidden>';
    $sHtml .= '<header class="bide-view-head">' . $sBack . '<h2 data-i18n="info.title">Información</h2></header>';
    $sHtml .= '<div class="bide-info-grid">';
    $sHtml .= '<section class="bide-card"><h3 data-i18n="bide.info.about">Sobre Bide+</h3><p data-i18n="bide.info.aboutText">Horarios y tiempo real del transporte público de Euskadi en una sola app. En Bizkaibus+, las llegadas se calculan con la posición GPS de cada autobús. Proyecto independiente y no oficial, sin relación con los operadores.</p></section>';
    $sHtml .= bideFaq();
    $sHtml .= bideFeedback();
    $sHtml .= bideSources();
    $sHtml .= '<section class="bide-card"><h3><span data-i18n="app.madeBy">Hecho por</span> ' . htmlspecialchars(bideSite()['author']) . '</h3><p class="bide-links">' . bideAuthorLinks() . '</p></section>';
    $sHtml .= bideChangelog();
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
    foreach (bideApps() as [$sApp, $sName]) {
        $sHtml .= '<a href="/?red=' . $sApp . '">' . $sName . '</a>';
    }
    return $sHtml . '</nav>';
}
