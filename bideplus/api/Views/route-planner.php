<?php
// «¿A dónde vas?» del inicio: la tarjeta con origen y destino (sugerencias debajo de cada campo), las opciones debajo
// («Cómo llegar»), la hoja de filtros y la pantalla del viaje elegido. Lo mueve js/route/main.js con el planificador.
$sFiltersIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M4 7H20M7 12H17M10 17H14"/></svg>';
$sSwapIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 4V20M12 8L8 4L4 8M16 20V4M20 16L16 20L12 16"/></svg>';
$sHomeNetworks = implode(',', \Core\Networks::defaultFavorites());
?>
        <form class="planner" aria-labelledby="planner-title" novalidate data-planner-card data-planner-screens data-home-networks="<?= htmlspecialchars($sHomeNetworks) ?>">
            <div class="planner-head">
                <h2 id="planner-title" data-i18n="menu.planner.title">¿A dónde vas?</h2>
            </div>
            <div class="planner-body">
                <div class="planner-places">
                    <div class="planner-place">
                        <span class="planner-dot planner-dot--from" aria-hidden="true"></span>
                        <label class="bide-visually-hidden" for="from-input" data-i18n="menu.planner.fromLabel">Origen</label>
                        <input id="from-input" class="planner-input" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="from-list" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="next" maxlength="120" placeholder="Origen: parada, calle o sitio" data-i18n-attr="placeholder:menu.planner.fromPlaceholder">
                    </div>
                    <p id="from-error" class="planner-error" role="alert" hidden></p>
                    <ul id="from-list" class="planner-suggestions" role="listbox" data-i18n-attr="aria-label:route.fromList" hidden></ul>
                    <div class="planner-place planner-place--to">
                        <span class="planner-dot planner-dot--to" aria-hidden="true"></span>
                        <label class="bide-visually-hidden" for="to-input" data-i18n="menu.planner.toLabel">Destino</label>
                        <input id="to-input" class="planner-input" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="to-list" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" maxlength="120" placeholder="Destino: parada, calle o sitio" data-i18n-attr="placeholder:menu.planner.to">
                    </div>
                    <p id="to-error" class="planner-error" role="alert" hidden></p>
                    <ul id="to-list" class="planner-suggestions" role="listbox" data-i18n-attr="aria-label:route.toList" hidden></ul>
                    <button type="button" class="planner-swap" data-planner-swap data-i18n-attr="aria-label:menu.planner.swap" aria-label="Intercambiar origen y destino"><?= $sSwapIcon ?></button>
                </div>
                <fieldset class="planner-when">
                    <legend class="bide-visually-hidden" data-i18n="menu.planner.when">Cuándo</legend>
                    <label><input type="radio" name="cuando" value="now" checked><span data-i18n="menu.planner.now">Ahora</span></label>
                    <label><input type="radio" name="cuando" value="depart"><span data-i18n="menu.planner.leave">Salir a</span></label>
                    <label><input type="radio" name="cuando" value="arrive"><span data-i18n="menu.planner.arrive">Llegar a</span></label>
                </fieldset>
                <div class="planner-time" data-planner-time hidden>
                    <label>
                        <span class="bide-visually-hidden" data-i18n="menu.planner.day">Día</span>
                        <select name="dia" data-planner-day><option value="" data-i18n="menu.planner.today">Hoy</option></select>
                    </label>
                    <label>
                        <span class="bide-visually-hidden" data-i18n="menu.planner.time">Hora</span>
                        <input type="time" name="hora" step="60" data-planner-hour>
                    </label>
                </div>
                <button class="planner-go" type="submit" data-i18n="menu.planner.search">Buscar ruta</button>
            </div>
        </form>

        <section class="route" id="route" aria-labelledby="route-title" hidden>
            <div class="route-head">
                <h2 id="route-title" tabindex="-1" data-i18n="route.title">Cómo llegar</h2>
                <div class="route-tools">
                    <button type="button" class="route-tool" id="route-filters-open" aria-haspopup="dialog"><?= $sFiltersIcon ?><span data-i18n="route.filters.title">Filtros</span><span class="route-tool-count" id="route-filters-count" hidden></span></button>
                    <button type="button" class="route-tool route-tool--round" id="route-share" data-i18n-attr="aria-label:route.share.options" aria-label="Compartir las opciones"><?= BIDE_ICONS['share'] ?></button>
                </div>
            </div>
            <p class="route-summary" id="route-summary" role="status"></p>
            <div class="route-notices" id="route-notices"></div>
            <p class="route-searching" id="route-searching" hidden><span class="route-spinner" aria-hidden="true"></span><span data-i18n="route.searching">Buscando el viaje…</span></p>
            <ol class="route-options" id="route-options"></ol>
            <p class="route-note" id="route-note" data-i18n="route.note">Horas del horario oficial de cada red.</p>
        </section>
        <p class="bide-visually-hidden" id="route-announcer" aria-live="polite"></p>

        <dialog id="route-filters" class="route-sheet" aria-labelledby="route-filters-title">
            <div class="route-sheet-page" id="route-filters-main">
                <span class="route-sheet-grip" aria-hidden="true"></span>
                <h2 id="route-filters-title" data-i18n="route.filters.title">Filtros</h2>
                <p class="route-sheet-hint" data-i18n="route.filters.saved">Se guardan para la próxima vez.</p>
                <div class="route-switch-row">
                    <span><strong id="route-filter-accessible-label" data-i18n="route.filters.accessible">Accesible en silla de ruedas</strong><span data-i18n="route.filters.accessibleText">Paradas y vehículos adaptados</span></span>
                    <button type="button" class="route-switch" role="switch" aria-checked="false" aria-labelledby="route-filter-accessible-label" data-route-filter="accessible"><span></span></button>
                </div>
                <div class="route-switch-row">
                    <span><strong id="route-filter-margin-label" data-i18n="route.filters.margin">Transbordos con margen</strong><span data-i18n="route.filters.marginText">10 min o más para cambiar</span></span>
                    <button type="button" class="route-switch" role="switch" aria-checked="false" aria-labelledby="route-filter-margin-label" data-route-filter="margin"><span></span></button>
                </div>
                <div class="route-switch-row">
                    <span><strong id="route-filter-live-label" data-i18n="route.filters.live">Solo en directo</strong><span data-i18n="route.filters.liveText">Solo lo que va con hora real</span></span>
                    <button type="button" class="route-switch" role="switch" aria-checked="false" aria-labelledby="route-filter-live-label" data-route-filter="liveOnly"><span></span></button>
                </div>
                <button type="button" class="route-sheet-link" id="route-networks-open">
                    <span><strong data-i18n="route.filters.networks">Redes</strong><span id="route-networks-value"></span></span><?= BIDE_ICONS['next'] ?>
                </button>
                <button type="button" class="route-sheet-done" id="route-filters-done" data-i18n="route.filters.done">Ver las opciones</button>
            </div>
            <div class="route-sheet-page" id="route-filters-networks" hidden>
                <span class="route-sheet-grip" aria-hidden="true"></span>
                <div class="route-sheet-bar">
                    <button type="button" class="route-sheet-back" id="route-networks-back" data-i18n-attr="aria-label:route.filters.back" aria-label="Volver a los filtros"><?= BIDE_ICONS['back'] ?></button>
                    <h3 id="route-networks-title" tabindex="-1" data-i18n="route.filters.networks">Redes</h3>
                    <button type="button" class="route-tool" id="route-networks-all" data-i18n="route.filters.all">Marcar todas</button>
                </div>
                <p class="route-sheet-hint" id="route-networks-count"></p>
                <p class="route-sheet-group" data-i18n="route.filters.yours">Tus redes</p>
                <div id="route-networks-yours"></div>
                <p class="route-sheet-group" data-i18n="route.filters.others">Las demás</p>
                <div id="route-networks-others"></div>
                <button type="button" class="route-sheet-done" id="route-networks-done" data-i18n="route.filters.ready">Hecho</button>
            </div>
        </dialog>

        <section class="route-trip" id="route-trip" aria-labelledby="route-trip-title" hidden>
            <header class="route-trip-bar">
                <button type="button" class="route-trip-back" id="route-trip-back" data-i18n-attr="aria-label:bide.back" aria-label="Volver"><?= BIDE_ICONS['back'] ?></button>
                <span class="bide-wordmark" aria-hidden="true">BIDE<span>+</span></span>
                <h2 id="route-trip-title" tabindex="-1"></h2>
                <button type="button" class="route-tool route-tool--round" id="route-trip-share" data-i18n-attr="aria-label:route.share.trip" aria-label="Compartir este viaje"><?= BIDE_ICONS['share'] ?></button>
            </header>
            <div class="route-trip-body" id="route-trip-body"></div>
        </section>
        <p class="route-toast" id="route-toast" role="status" hidden></p>
