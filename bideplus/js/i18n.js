(function (global) {
    'use strict';

    var STORAGE_KEY = 'bide_lang';

    var dict = {
        es: {
            'menu.tagline': 'Movilidad en Euskadi',
            'menu.section': 'Apps de movilidad',
            'menu.view.tiles': 'Ver como fichas grandes',
            'menu.view.tilesTitle': 'Fichas grandes',
            'menu.view.list': 'Ver como lista compacta',
            'menu.view.listTitle': 'Lista compacta',
            'menu.lang.toggle': 'Euskaraz',
            'menu.footer': 'Proyecto independiente de Yeray Garrido. Horarios a partir de los datos abiertos de Euskadi y Metro Bilbao.',
            'menu.privacy': 'Esta página no recoge datos personales ni usa analíticas: solo guarda en tu dispositivo la vista y el idioma elegidos. Cada app tiene su aviso legal y de privacidad completo dentro.',
            'app.bus.name': 'Bizkaibus+',
            'app.bus.desc': 'Horarios y tiempo real',
            'app.metro.name': 'Metro+',
            'app.metro.desc': 'Metro Bilbao',
            'app.euskotren.name': 'Euskotren+',
            'app.euskotren.desc': 'Tren con horarios y tiempo real',
            'app.renfe.name': 'Renfe Cercanías+',
            'app.renfe.desc': 'Cercanías en Bilbao y Donostia',
            'app.lurraldebus.name': 'Lurraldebus+',
            'app.soon': 'Próximamente',
            'app.tranviaBilbao.city': 'Bilbao',
            'app.tranviaVitoria.city': 'Vitoria',
            'app.tram.desc': 'Horarios del tranvía',
            'app.last': 'Última que abriste',
            'app.nearby': 'Cerca de mí',
            'app.nearby.loading': 'Buscando tu ubicación…',
            'app.nearby.loadingStops': 'Buscando paradas cercanas…',
            'app.nearby.noGeolocation': 'Tu dispositivo no permite obtener la ubicación.',
            'app.nearby.denied': 'Has denegado el permiso de ubicación. Actívalo en los ajustes del navegador para ver las paradas cercanas.',
            'app.nearby.failed': 'No se pudo obtener tu ubicación. Inténtalo de nuevo.',
            'app.nearby.outOfArea': 'Tu ubicación está fuera de la zona cubierta por esta app.',
            'app.nearby.apiError': 'No se pudieron consultar las paradas cercanas.',
            'app.nearby.empty': 'No hay paradas a menos de 2 km de ti.',
            'app.search.placeholder': 'Buscar parada, línea o destino...',
            'app.status.onTime': 'En hora',
            'app.status.delay': 'Retraso +{min}m',
            'app.status.finished': 'Finalizado',
            'app.status.departed': 'Ya salió',
            'app.status.scheduled': 'Programado',
            'app.lang.toggle': 'Español',
            'switcher.bus.desc': 'Horarios y tiempo real de Bizkaibus',
            'switcher.metro.desc': 'Horarios de Metro Bilbao',
            'switcher.euskotren.desc': 'Horarios de Euskotren',
            'switcher.tranviaBilbao.name': 'Tranvía Bilbao+',
            'switcher.tranviaBilbao.desc': 'Horarios del tranvía de Bilbao',
            'switcher.tranviaVitoria.name': 'Tranvía Vitoria+',
            'switcher.tranviaVitoria.desc': 'Horarios del tranvía de Vitoria-Gasteiz',
            'switcher.renfe.desc': 'Horarios de Cercanías en Bilbao y Donostia',
            'switcher.renfeSubtitle': 'Horarios de Cercanías',
            'switcher.allApps': 'Todas las apps',
            'switcher.bus.liveEmpty': 'Busca una parada para ver el próximo autobús.',
            'switcher.metro.liveEmpty': 'Busca una estación para ver el próximo metro.',
            'switcher.euskotren.liveEmpty': 'Busca una estación para ver el próximo tren.',
            'switcher.tram.liveEmpty': 'Busca una parada para ver el próximo tranvía.',
            'switcher.renfe.liveEmpty': 'Busca una parada para ver el próximo tren.',
            'app.offline.banner': 'Sin conexión con el servidor. Puede que veas datos guardados.',
            'app.retry': 'Reintentar',
            'app.favorites.title': 'Tus favoritos',
            'app.favorites.empty': 'Busca una parada o línea y guárdala para verla aquí.',
            'app.fullSchedule': 'Ver horario completo',
            'app.incidents': 'Incidencias',
            'app.checkSchedules': 'Consultar Horarios',
            'app.noLiveBuses': 'No hay autobuses de esta línea circulando ahora mismo.',
            'app.departure': 'Salida',
            'app.destination': 'Destino',
            'app.state': 'Estado',
            'app.noDepartures': 'No hay salidas programadas en ese rango.',
            'app.officialSchedule2026': 'Ver horario oficial 2026',
            'app.madeBy': 'Hecho por',
            'app.legalNotice': 'Aviso legal y privacidad',
            'app.myLinesIncidents': 'Incidencias de mis líneas',
            'app.notifyMe': 'Avisarme de las incidencias nuevas de mis líneas favoritas',
            'app.noFavoriteIncidents': 'Guarda alguna línea en favoritos para ver aquí sus incidencias activas.',
            'app.direction': 'Dirección: {headsign}',
            'app.liveTracking': 'Vehículo en seguimiento en vivo · Ref. {ref}',
            'app.tripFinished': 'Este viaje ya ha finalizado. Se muestra el horario programado.',
            'app.noLiveTracking': 'Sin seguimiento en vivo en este momento. Se muestra el horario programado.',
            'app.incidents.view': 'Ver incidencias',
            'app.loading': 'Cargando…',
            'app.loadFailed': 'No se han podido cargar',
            'app.noActiveIncidents': 'Sin incidencias activas para esta línea.',
            'app.badge.scheduled': 'PROGRAMADO',
            'app.badge.arriving': 'LLEGANDO',
            'app.badge.enRoute': 'EN RUTA',
            'app.noUpcomingDepartures': 'Sin próximas salidas',
            'app.noData': 'Sin datos',
            'app.noOfficialScheduleText': 'No hay horario oficial en texto disponible para esta línea.',
            'app.schedule': 'Horario',
            'app.currentToday': 'Vigente hoy',
            'app.outbound': 'Ida',
            'app.returnTrip': 'Vuelta',
            'app.loadingIncidents': 'Cargando incidencias…',
            'app.incidentsLoadFailed': 'No se han podido cargar las incidencias ahora mismo.',
            'app.noActiveIncidentsNow': 'No hay incidencias activas ahora mismo.',
            'app.saveLineToSeeIncidents': 'Guarda una línea en favoritos, o abre una, para ver aquí sus incidencias activas.',
            'app.noneOfYourLinesHaveIncidents': 'Ninguna de esas líneas tiene incidencias activas ahora mismo.',
        },
        eu: {
            'menu.tagline': 'Mugikortasuna Euskadin',
            'menu.section': 'Mugikortasun aplikazioak',
            'menu.view.tiles': 'Fitxa handi gisa ikusi',
            'menu.view.tilesTitle': 'Fitxa handiak',
            'menu.view.list': 'Zerrenda trinko gisa ikusi',
            'menu.view.listTitle': 'Zerrenda trinkoa',
            'menu.lang.toggle': 'Castellano',
            'menu.footer': 'Yeray Garridoren proiektu independentea. Ordutegiak Euskadiko datu irekien eta Bilboko Metroren arabera.',
            'menu.privacy': 'Orri honek ez du datu pertsonalik biltzen ez eta analitikarik erabiltzen: zure gailuan bakarrik gordetzen ditu aukeratutako ikuspegia eta hizkuntza. Aplikazio bakoitzak bere abisu legal eta pribatutasun osoa dauka barruan.',
            'app.bus.name': 'Bizkaibus+',
            'app.bus.desc': 'Ordutegiak eta zuzenekoa',
            'app.metro.name': 'Metro+',
            'app.metro.desc': 'Bilboko Metroa',
            'app.euskotren.name': 'Euskotren+',
            'app.euskotren.desc': 'Trena, ordutegiekin eta zuzenekoa',
            'app.renfe.name': 'Renfe Aldiriak+',
            'app.renfe.desc': 'Bilbo eta Donostiako aldiriak',
            'app.lurraldebus.name': 'Lurraldebus+',
            'app.soon': 'Laster',
            'app.tranviaBilbao.city': 'Bilbo',
            'app.tranviaVitoria.city': 'Gasteiz',
            'app.tram.desc': 'Tranbiaren ordutegiak',
            'app.last': 'Azken irekitakoa',
            'app.nearby': 'Nire ondoan',
            'app.nearby.loading': 'Zure kokapena bilatzen…',
            'app.nearby.loadingStops': 'Inguruko geltokiak bilatzen…',
            'app.nearby.noGeolocation': 'Zure gailuak ez du kokapena lortzeko aukerarik ematen.',
            'app.nearby.denied': 'Kokapen baimena ukatu duzu. Gaitu nabigatzailearen ezarpenetan inguruko geltokiak ikusteko.',
            'app.nearby.failed': 'Ezin izan da zure kokapena lortu. Saiatu berriro.',
            'app.nearby.outOfArea': 'Zure kokapena aplikazio honek estaltzen duen eremutik kanpo dago.',
            'app.nearby.apiError': 'Ezin izan dira inguruko geltokiak kontsultatu.',
            'app.nearby.empty': 'Ez dago geltokirik 2 km-ra baino gutxiagora.',
            'app.search.placeholder': 'Bilatu geltokia, linea edo helmuga...',
            'app.status.onTime': 'Ordu onean',
            'app.status.delay': '+{min}m atzerapena',
            'app.status.finished': 'Amaituta',
            'app.status.departed': 'Jada irten da',
            'app.status.scheduled': 'Programatuta',
            'app.lang.toggle': 'Gaztelania',
            'switcher.bus.desc': 'Bizkaibusen ordutegiak eta zuzenekoa',
            'switcher.metro.desc': 'Bilboko Metroaren ordutegiak',
            'switcher.euskotren.desc': 'Euskotrenen ordutegiak',
            'switcher.tranviaBilbao.name': 'Tranbia Bilbo+',
            'switcher.tranviaBilbao.desc': 'Bilboko tranbiaren ordutegiak',
            'switcher.tranviaVitoria.name': 'Tranbia Gasteiz+',
            'switcher.tranviaVitoria.desc': 'Gasteizko tranbiaren ordutegiak',
            'switcher.renfe.desc': 'Bilbo eta Donostiako Aldirien ordutegiak',
            'switcher.renfeSubtitle': 'Aldirien ordutegiak',
            'switcher.allApps': 'Aplikazio guztiak',
            'switcher.bus.liveEmpty': 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'switcher.metro.liveEmpty': 'Bilatu geltoki bat hurrengo metroa ikusteko.',
            'switcher.euskotren.liveEmpty': 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'switcher.tram.liveEmpty': 'Bilatu geraleku bat hurrengo tranbia ikusteko.',
            'switcher.renfe.liveEmpty': 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'app.offline.banner': 'Ez dago konexiorik zerbitzariarekin. Baliteke gordetako datuak ikustea.',
            'app.retry': 'Berriz saiatu',
            'app.favorites.title': 'Zure gogokoak',
            'app.favorites.empty': 'Bilatu parada edo linea bat eta gorde hemen ikusteko.',
            'app.fullSchedule': 'Ordutegi osoa ikusi',
            'app.incidents': 'Intzidentziak',
            'app.checkSchedules': 'Ordutegiak Kontsultatu',
            'app.noLiveBuses': 'Linea honetako autobusik ez dabil orain.',
            'app.departure': 'Irteera',
            'app.destination': 'Helmuga',
            'app.state': 'Egoera',
            'app.noDepartures': 'Ez dago irteerarik programatuta tarte horretan.',
            'app.officialSchedule2026': '2026ko ordutegi ofiziala ikusi',
            'app.madeBy': 'Egilea:',
            'app.legalNotice': 'Lege oharra eta pribatutasuna',
            'app.myLinesIncidents': 'Nire lineen intzidentziak',
            'app.notifyMe': 'Jakinarazi nire gogoko lineen intzidentzia berriak',
            'app.noFavoriteIncidents': 'Gorde linea bat gogokoetan hemen bere intzidentzia aktiboak ikusteko.',
            'app.direction': 'Norabidea: {headsign}',
            'app.liveTracking': 'Ibilgailua zuzenean jarraitzen · Erref. {ref}',
            'app.tripFinished': 'Bidaia hau amaitu da. Ordutegi programatua erakusten da.',
            'app.noLiveTracking': 'Ez dago zuzeneko jarraipenik une honetan. Ordutegi programatua erakusten da.',
            'app.incidents.view': 'Intzidentziak ikusi',
            'app.loading': 'Kargatzen…',
            'app.loadFailed': 'Ezin izan da kargatu',
            'app.noActiveIncidents': 'Ez dago intzidentzia aktiborik linea honetarako.',
            'app.badge.scheduled': 'PROGRAMATUTA',
            'app.badge.arriving': 'IRISTEN',
            'app.badge.enRoute': 'BIDEAN',
            'app.noUpcomingDepartures': 'Hurrengo irteerarik ez',
            'app.noData': 'Daturik ez',
            'app.noOfficialScheduleText': 'Ez dago ordutegi ofizialik testu gisa linea honetarako.',
            'app.schedule': 'Ordutegia',
            'app.currentToday': 'Gaur indarrean',
            'app.outbound': 'Joan',
            'app.returnTrip': 'Itzuli',
            'app.loadingIncidents': 'Intzidentziak kargatzen…',
            'app.incidentsLoadFailed': 'Ezin izan dira intzidentziak kargatu orain.',
            'app.noActiveIncidentsNow': 'Ez dago intzidentzia aktiborik orain.',
            'app.saveLineToSeeIncidents': 'Gorde linea bat gogokoetan, edo ireki bat, hemen bere intzidentzia aktiboak ikusteko.',
            'app.noneOfYourLinesHaveIncidents': 'Linea horietako batek ere ez du intzidentzia aktiborik orain.',
        },
    };

    function getLang() {
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (stored === 'eu' || stored === 'es') return stored;
        } catch (e) { /* private mode / storage blocked */ }
        return 'es';
    }

    function setLang(lang) {
        try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) { /* ignore */ }
    }

    function t(key, vars) {
        var lang = getLang();
        var table = dict[lang] || dict.es;
        var text = table[key];
        if (text === undefined) text = dict.es[key];
        if (text === undefined) return key;
        if (vars) {
            Object.keys(vars).forEach(function (k) {
                text = text.replace('{' + k + '}', vars[k]);
            });
        }
        return text;
    }

    function applyTranslations(root) {
        root = root || document;
        root.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });
        root.querySelectorAll('[data-i18n-attr]').forEach(function (el) {
            el.getAttribute('data-i18n-attr').split(',').forEach(function (pair) {
                var parts = pair.split(':');
                var attr = parts[0];
                var key = parts[1];
                el.setAttribute(attr, t(key));
            });
        });
        document.documentElement.setAttribute('lang', getLang());
    }

    global.I18n = { t: t, getLang: getLang, setLang: setLang, applyTranslations: applyTranslations };
})(window);
