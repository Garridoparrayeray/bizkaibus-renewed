// Generado por scripts/build-networks-js.php a partir de api/Networks. No editar a mano.
(function (global) {
    const networks = {
    "bus": {
        "kind": "bus",
        "icons": "icons-pro",
        "themeColor": "#01573C",
        "title": [
            "BizkaiBus",
            ""
        ],
        "favoritesKey": "bizkaibus_favorites",
        "live": true,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": false,
            "stop_hints": true,
            "line_map": true,
            "schedule_text": true,
            "trip_detail": "vehicle",
            "alerts": "lines",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "Bizkaibus+",
                "tile": "Horarios y tiempo real",
                "switcher": "Horarios y tiempo real de Bizkaibus",
                "subtitle": "Horarios y tiempo real de Bizkaibus",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Bizkaibus ni con la Diputación Foral de Bizkaia.",
                "attribution": "Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
            },
            "eu": {
                "name": "Bizkaibus+",
                "tile": "Ordutegiak eta zuzenekoa",
                "switcher": "Bizkaibusen ordutegiak eta zuzenekoa",
                "subtitle": "Bizkaibusen ordutegiak eta zuzenekoa",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "attribution": "Datuak: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
            },
            "en": {
                "name": "Bizkaibus+",
                "tile": "Timetables and real time",
                "switcher": "Bizkaibus timetables and real time",
                "subtitle": "Bizkaibus timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Bizkaibus or with the Provincial Council of Bizkaia.",
                "attribution": "Data: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
            }
        }
    },
    "metro": {
        "kind": "metro",
        "icons": "icons-metro",
        "themeColor": "#C8102E",
        "title": [
            "METRO",
            ""
        ],
        "favoritesKey": "metrobilbao_favorites",
        "live": true,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": true,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": false
        },
        "texts": {
            "es": {
                "name": "Metro+",
                "tile": "Metro Bilbao",
                "switcher": "Horarios de Metro Bilbao",
                "subtitle": "Horarios de Metro Bilbao",
                "liveEmpty": "Busca una estación para ver el próximo metro.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A. ni con el Consorcio de Transportes de Bizkaia. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0): horario y tiempo real"
            },
            "eu": {
                "name": "Metro+",
                "tile": "Bilboko Metroa",
                "switcher": "Bilboko Metroaren ordutegiak",
                "subtitle": "Bilboko Metroaren ordutegiak",
                "liveEmpty": "Bilatu geltoki bat hurrengo metroa ikusteko.",
                "attribution": "Datuak: Metro Bilbao / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0): ordutegia eta denbora erreala"
            },
            "en": {
                "name": "Metro+",
                "tile": "Metro Bilbao",
                "switcher": "Metro Bilbao timetables",
                "subtitle": "Metro Bilbao timetables",
                "liveEmpty": "Search for a station to see the next metro.",
                "disclaimer": "An independent, unofficial project, not affiliated with Metro Bilbao S.A. or with the Bizkaia Transport Consortium. Live times are the operator's forecast.",
                "attribution": "Data: Metro Bilbao / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0): timetable and real time"
            }
        }
    },
    "euskotren": {
        "kind": "train",
        "icons": "icons-euskotren",
        "themeColor": "#003F8C",
        "title": [
            "EUSKOTREN",
            ""
        ],
        "favoritesKey": "euskotren_favorites",
        "live": false,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "stops",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "Euskotren+",
                "tile": "Tren con horarios oficiales",
                "switcher": "Horarios de Euskotren",
                "subtitle": "Horarios de Euskotren",
                "liveEmpty": "Busca una estación para ver el próximo tren.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Euskotren S.A. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.",
                "attribution": "Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)"
            },
            "eu": {
                "name": "Euskotren+",
                "tile": "Trena, ordutegi ofizialekin",
                "switcher": "Euskotrenen ordutegiak",
                "subtitle": "Euskotrenen ordutegiak",
                "liveEmpty": "Bilatu geltoki bat hurrengo trena ikusteko.",
                "attribution": "Datuak: Euskotren / Open Data Euskadi (CC-BY 4.0)"
            },
            "en": {
                "name": "Euskotren+",
                "tile": "Trains with official timetables",
                "switcher": "Euskotren timetables",
                "subtitle": "Euskotren timetables",
                "liveEmpty": "Search for a station to see the next train.",
                "disclaimer": "An independent, unofficial project, not affiliated with Euskotren S.A. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.",
                "attribution": "Data: Euskotren / Open Data Euskadi (CC-BY 4.0)"
            }
        }
    },
    "renfe": {
        "kind": "train",
        "icons": "icons-renfe",
        "themeColor": "#74349A",
        "title": [
            "RENFE",
            ""
        ],
        "favoritesKey": "renfe_favorites",
        "live": false,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": true,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "stops",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "Renfe Cercanías+",
                "tile": "Cercanías en Bilbao y Donostia",
                "switcher": "Horarios de Cercanías en Bilbao y Donostia",
                "subtitle": "Horarios de Cercanías",
                "liveEmpty": "Busca una parada para ver el próximo tren.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Renfe. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.",
                "attribution": "Datos: Renfe Cercanías / NAP (Punto de Acceso Nacional de Transporte)"
            },
            "eu": {
                "name": "Renfe Aldiriak+",
                "tile": "Bilbo eta Donostiako aldiriak",
                "switcher": "Bilbo eta Donostiako Aldirien ordutegiak",
                "subtitle": "Aldirien ordutegiak",
                "liveEmpty": "Bilatu geltoki bat hurrengo trena ikusteko.",
                "attribution": "Datuak: Renfe Aldiriak / NAP (Garraioaren Sarbide Puntu Nazionala)"
            },
            "en": {
                "name": "Renfe Cercanías+",
                "tile": "Commuter trains in Bilbao and Donostia",
                "switcher": "Commuter train timetables in Bilbao and Donostia",
                "subtitle": "Commuter train timetables",
                "liveEmpty": "Search for a stop to see the next train.",
                "disclaimer": "An independent, unofficial project, not affiliated with Renfe. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.",
                "attribution": "Data: Renfe Cercanías / NAP (Spain's National Access Point for transport data)"
            }
        }
    },
    "lurraldebus": {
        "kind": "bus",
        "icons": "icons-lurraldebus",
        "themeColor": "#0062A8",
        "title": [
            "LURRALDEBUS",
            ""
        ],
        "favoritesKey": "lurraldebus_favorites",
        "live": false,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": false,
            "stop_hints": true,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "Lurraldebus+",
                "tile": "Autobuses de Gipuzkoa",
                "switcher": "Horarios de Lurraldebus en Gipuzkoa",
                "subtitle": "Horarios de Lurraldebus",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Lurraldebus ni con la Diputación Foral de Gipuzkoa. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.",
                "attribution": "Datos: Lurraldebus / Diputación Foral de Gipuzkoa, vía Moveuskadi (Open Data Euskadi)"
            },
            "eu": {
                "name": "Lurraldebus+",
                "tile": "Gipuzkoako autobusak",
                "switcher": "Lurraldebusen ordutegiak Gipuzkoan",
                "subtitle": "Lurraldebusen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "attribution": "Datuak: Lurraldebus / Gipuzkoako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi)"
            },
            "en": {
                "name": "Lurraldebus+",
                "tile": "Gipuzkoa buses",
                "switcher": "Lurraldebus timetables in Gipuzkoa",
                "subtitle": "Lurraldebus timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Lurraldebus or with the Provincial Council of Gipuzkoa. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.",
                "attribution": "Data: Lurraldebus / Provincial Council of Gipuzkoa, via Moveuskadi (Open Data Euskadi)"
            }
        }
    },
    "tranvia-bilbao": {
        "kind": "tram",
        "icons": "icons-tranvia-bilbao",
        "themeColor": "#4EB848",
        "title": [
            "TRANVÍA",
            "BILBAO"
        ],
        "favoritesKey": "tranviabilbao_favorites",
        "live": false,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "stops",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "Tranvía Bilbao+",
                "tile": "Horarios del tranvía",
                "tile_title": "Tranvía",
                "tile_city": "Bilbao",
                "switcher": "Horarios del tranvía de Bilbao",
                "subtitle": "Horarios del tranvía",
                "liveEmpty": "Busca una parada para ver el próximo tranvía.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Euskotren S.A. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.",
                "attribution": "Datos: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)"
            },
            "eu": {
                "name": "Tranbia Bilbo+",
                "tile_title": "Tranbia",
                "tile": "Tranbiaren ordutegiak",
                "tile_city": "Bilbo",
                "switcher": "Bilboko tranbiaren ordutegiak",
                "subtitle": "Tranbiaren ordutegiak",
                "liveEmpty": "Bilatu geraleku bat hurrengo tranbia ikusteko.",
                "attribution": "Datuak: Euskotren (Bilboko tranbia) / Open Data Euskadi (CC-BY 4.0)"
            },
            "en": {
                "tile": "Tram timetables",
                "switcher": "Bilbao tram timetables",
                "subtitle": "Tram timetables",
                "liveEmpty": "Search for a stop to see the next tram.",
                "disclaimer": "An independent, unofficial project, not affiliated with Euskotren S.A. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.",
                "attribution": "Data: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)"
            }
        }
    },
    "tranvia-vitoria": {
        "kind": "tram",
        "icons": "icons-tranvia-vitoria",
        "themeColor": "#60AE27",
        "title": [
            "TRANVÍA",
            "VITORIA"
        ],
        "favoritesKey": "tranviavitoria_favorites",
        "live": false,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "stops",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "Tranvía Vitoria+",
                "tile": "Horarios del tranvía",
                "tile_title": "Tranvía",
                "tile_city": "Vitoria",
                "switcher": "Horarios del tranvía de Vitoria-Gasteiz",
                "subtitle": "Horarios del tranvía",
                "liveEmpty": "Busca una parada para ver el próximo tranvía.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Euskotren S.A. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.",
                "attribution": "Datos: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)"
            },
            "eu": {
                "name": "Tranbia Gasteiz+",
                "tile_title": "Tranbia",
                "tile": "Tranbiaren ordutegiak",
                "tile_city": "Gasteiz",
                "switcher": "Gasteizko tranbiaren ordutegiak",
                "subtitle": "Tranbiaren ordutegiak",
                "liveEmpty": "Bilatu geraleku bat hurrengo tranbia ikusteko.",
                "attribution": "Datuak: Euskotren (Gasteizko tranbia) / Open Data Euskadi (CC-BY 4.0)"
            },
            "en": {
                "tile": "Tram timetables",
                "switcher": "Vitoria-Gasteiz tram timetables",
                "subtitle": "Tram timetables",
                "liveEmpty": "Search for a stop to see the next tram.",
                "disclaimer": "An independent, unofficial project, not affiliated with Euskotren S.A. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.",
                "attribution": "Data: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)"
            }
        }
    }
};
    const order = Object.keys(networks);
    global.BideNetworks = {
        DEFAULT: "bus",
        all: networks,
        slugs: order,
        get: function (slug) { return networks[slug] || networks["bus"]; },
        exists: function (slug) { return Object.prototype.hasOwnProperty.call(networks, slug); },
        // En el idioma elegido si hay diccionario (páginas); en castellano si no (service worker).
        name: function (slug) {
            if (!networks[slug]) return slug;
            if (global.I18n) return global.I18n.t('net.' + slug + '.name');
            return networks[slug].texts.es.name;
        },
    };
    // Los textos de cada red entran en el diccionario como net.<red>.<clave>.
    if (global.I18n && global.I18n.extend) {
        order.forEach(function (slug) {
            Object.keys(networks[slug].texts).forEach(function (lang) {
                const table = {};
                Object.keys(networks[slug].texts[lang]).forEach(function (key) {
                    table['net.' + slug + '.' + key] = networks[slug].texts[lang][key];
                });
                global.I18n.extend(lang, table);
            });
        });
    }
})(self);
