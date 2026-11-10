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
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Bizkaibusekin eta Bizkaiko Foru Aldundiarekin loturarik gabea.",
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
            },
            "fr": {
                "name": "Bizkaibus+",
                "tile": "Horaires et temps réel",
                "switcher": "Horaires et temps réel de Bizkaibus",
                "subtitle": "Horaires et temps réel de Bizkaibus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Bizkaibus ni avec la Députation forale de Bizkaia.",
                "attribution": "Données : Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
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
                "liveSource": "el Consorcio de Transportes de Bizkaia",
                "name": "Metro+",
                "tile": "Metro Bilbao",
                "switcher": "Horarios y tiempo real de Metro Bilbao",
                "subtitle": "Horarios y tiempo real de Metro Bilbao",
                "liveEmpty": "Busca una estación para ver el próximo metro.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A. ni con el Consorcio de Transportes de Bizkaia. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0): horario y tiempo real"
            },
            "eu": {
                "liveSource": "Bizkaiko Garraio Partzuergoak",
                "name": "Metro+",
                "tile": "Bilboko Metroa",
                "switcher": "Bilboko Metroaren ordutegiak eta denbora errealean",
                "subtitle": "Bilboko Metroaren ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu geltoki bat hurrengo metroa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Metro Bilbao S.A.-rekin eta Bizkaiko Garraio Partzuergoarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Metro Bilbao / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0): ordutegia eta denbora errealean"
            },
            "en": {
                "liveSource": "the Bizkaia Transport Consortium",
                "name": "Metro+",
                "tile": "Metro Bilbao",
                "switcher": "Metro Bilbao timetables and real time",
                "subtitle": "Metro Bilbao timetables and real time",
                "liveEmpty": "Search for a station to see the next metro.",
                "disclaimer": "An independent, unofficial project, not affiliated with Metro Bilbao S.A. or with the Bizkaia Transport Consortium. Live times are the operator's forecast.",
                "attribution": "Data: Metro Bilbao / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0): timetable and real time"
            },
            "fr": {
                "liveSource": "le Consorcio de Transportes de Bizkaia",
                "name": "Metro+",
                "tile": "Metro Bilbao",
                "switcher": "Horaires et temps réel de Metro Bilbao",
                "subtitle": "Horaires et temps réel de Metro Bilbao",
                "liveEmpty": "Cherchez une station pour voir le prochain métro.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Metro Bilbao S.A. ni avec le Consorcio de Transportes de Bizkaia. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0) : horaires et temps réel"
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
        "live": true,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "liveSource": "Euskotren",
                "name": "Euskotren+",
                "tile": "Horarios",
                "switcher": "Horarios de Euskotren",
                "subtitle": "Horarios de Euskotren",
                "liveEmpty": "Busca una estación para ver el próximo tren.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Euskotren S.A. Euskotren no publica el directo de sus trenes: las horas son las del horario oficial.",
                "attribution": "Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Euskotrenek",
                "name": "Euskotren+",
                "tile": "Ordutegiak",
                "switcher": "Euskotrenen ordutegiak",
                "subtitle": "Euskotrenen ordutegiak",
                "liveEmpty": "Bilatu geltoki bat hurrengo trena ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Euskotrenek ez du bere trenen zuzenekoa argitaratzen: orduak ordutegi ofizialekoak dira.",
                "attribution": "Datuak: Euskotren / Open Data Euskadi (CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Euskotren",
                "name": "Euskotren+",
                "tile": "Timetables",
                "switcher": "Euskotren timetables",
                "subtitle": "Euskotren timetables",
                "liveEmpty": "Search for a station to see the next train.",
                "disclaimer": "An independent, unofficial project, not affiliated with Euskotren S.A. Euskotren does not publish live data for its trains: times are from the official timetable.",
                "attribution": "Data: Euskotren / Open Data Euskadi (CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Euskotren",
                "name": "Euskotren+",
                "tile": "Horaires",
                "switcher": "Horaires d’Euskotren",
                "subtitle": "Horaires d’Euskotren",
                "liveEmpty": "Cherchez une gare pour voir le prochain train.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Euskotren S.A. Euskotren ne publie pas le temps réel de ses trains : les heures sont celles de l’horaire officiel.",
                "attribution": "Données : Euskotren / Open Data Euskadi (CC-BY 4.0)"
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
        "live": true,
        "features": {
            "platforms": true,
            "line_buttons": true,
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
                "liveSource": "Renfe",
                "name": "Renfe Cercanías+",
                "tile": "Cercanías en Bilbao y Donostia",
                "switcher": "Horarios y tiempo real de Cercanías en Bilbao y Donostia",
                "subtitle": "Horarios y tiempo real de Cercanías",
                "liveEmpty": "Busca una parada para ver el próximo tren.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Renfe. Los retrasos y los trenes cancelados son los que publica Renfe en directo.",
                "attribution": "Datos: Renfe Cercanías / data.renfe.com (CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Renfek",
                "name": "Renfe Aldiriak+",
                "tile": "Bilbo eta Donostiako aldiriak",
                "switcher": "Bilbo eta Donostiako Aldirien ordutegiak eta denbora errealean",
                "subtitle": "Aldirien ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu geltoki bat hurrengo trena ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Renferekin loturarik gabea. Atzerapenak eta bertan behera utzitako trenak Renfek zuzenean argitaratzen dituenak dira.",
                "attribution": "Datuak: Renfe Aldiriak / data.renfe.com (CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Renfe",
                "name": "Renfe Cercanías+",
                "tile": "Commuter trains in Bilbao and Donostia",
                "switcher": "Commuter train timetables and real time in Bilbao and Donostia",
                "subtitle": "Commuter train timetables and real time",
                "liveEmpty": "Search for a stop to see the next train.",
                "disclaimer": "An independent, unofficial project, not affiliated with Renfe. Delays and cancelled trains are those Renfe publishes live.",
                "attribution": "Data: Renfe Cercanías / data.renfe.com (CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Renfe",
                "name": "Renfe Cercanías+",
                "tile": "Trains de banlieue à Bilbao et Donostia",
                "switcher": "Horaires et temps réel des Cercanías à Bilbao et Donostia",
                "subtitle": "Horaires et temps réel des Cercanías",
                "liveEmpty": "Cherchez une gare pour voir le prochain train.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Renfe. Les retards et les trains supprimés sont ceux que Renfe publie en direct.",
                "attribution": "Données : Renfe Cercanías / data.renfe.com (CC-BY 4.0)"
            }
        }
    },
    "lurraldebus": {
        "kind": "bus",
        "icons": "icons-lurraldebus",
        "themeColor": "#2A9AB2",
        "title": [
            "LURRALDEBUS",
            ""
        ],
        "favoritesKey": "lurraldebus_favorites",
        "live": true,
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
                "attribution": "Datos: Lurraldebus / Diputación Foral de Gipuzkoa, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "name": "Lurraldebus+",
                "tile": "Gipuzkoako autobusak",
                "switcher": "Lurraldebusen ordutegiak Gipuzkoan",
                "subtitle": "Lurraldebusen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Lurraldebusekin eta Gipuzkoako Foru Aldundiarekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen, operadoreak argitaratutako gorabehera-abisuak izan ezik.",
                "attribution": "Datuak: Lurraldebus / Gipuzkoako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "name": "Lurraldebus+",
                "tile": "Gipuzkoa buses",
                "switcher": "Lurraldebus timetables in Gipuzkoa",
                "subtitle": "Lurraldebus timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Lurraldebus or with the Provincial Council of Gipuzkoa. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.",
                "attribution": "Data: Lurraldebus / Provincial Council of Gipuzkoa, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "name": "Lurraldebus+",
                "tile": "Bus du Gipuzkoa",
                "switcher": "Horaires de Lurraldebus au Gipuzkoa",
                "subtitle": "Horaires de Lurraldebus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Lurraldebus ni avec la Députation forale du Gipuzkoa. Horaire théorique officiel : il ne reflète ni les retards ni les suppressions, sauf les alertes de perturbations publiées par l’opérateur.",
                "attribution": "Données : Lurraldebus / Députation forale du Gipuzkoa, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
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
        "live": true,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "liveSource": "Euskotren",
                "name": "Tranvía Bilbao+",
                "tile": "Horarios y tiempo real",
                "tile_title": "Tranvía",
                "tile_city": "Bilbao",
                "switcher": "Horarios y tiempo real del tranvía de Bilbao",
                "subtitle": "Horarios y tiempo real del tranvía",
                "liveEmpty": "Busca una parada para ver el próximo tranvía.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Euskotrenek",
                "name": "Tranbia Bilbo+",
                "tile_title": "Tranbia",
                "tile": "Ordutegiak eta zuzenekoa",
                "tile_city": "Bilbo",
                "switcher": "Bilboko tranbiaren ordutegiak eta denbora errealean",
                "subtitle": "Tranbiaren ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu geraleku bat hurrengo tranbia ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Euskotren (Bilboko tranbia) / Open Data Euskadi (CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Euskotren",
                "name": "Tranvía Bilbao+",
                "tile": "Timetables and real time",
                "tile_title": "Tranvía",
                "tile_city": "Bilbao",
                "switcher": "Bilbao tram timetables and real time",
                "subtitle": "Tram timetables and real time",
                "liveEmpty": "Search for a stop to see the next tram.",
                "disclaimer": "An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator's forecast.",
                "attribution": "Data: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Euskotren",
                "name": "Tranvía Bilbao+",
                "tile": "Horaires et temps réel",
                "tile_title": "Tranvía",
                "tile_city": "Bilbao",
                "switcher": "Horaires et temps réel du tramway de Bilbao",
                "subtitle": "Horaires et temps réel du tramway",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain tramway.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Euskotren S.A. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)"
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
        "live": true,
        "features": {
            "platforms": true,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "liveSource": "Euskotren",
                "name": "Tranvía Vitoria+",
                "tile": "Horarios y tiempo real",
                "tile_title": "Tranvía",
                "tile_city": "Vitoria",
                "switcher": "Horarios y tiempo real del tranvía de Vitoria-Gasteiz",
                "subtitle": "Horarios y tiempo real del tranvía",
                "liveEmpty": "Busca una parada para ver el próximo tranvía.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Euskotrenek",
                "name": "Tranbia Gasteiz+",
                "tile_title": "Tranbia",
                "tile": "Ordutegiak eta zuzenekoa",
                "tile_city": "Gasteiz",
                "switcher": "Gasteizko tranbiaren ordutegiak eta denbora errealean",
                "subtitle": "Tranbiaren ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu geraleku bat hurrengo tranbia ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Euskotren (Gasteizko tranbia) / Open Data Euskadi (CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Euskotren",
                "name": "Tranvía Vitoria+",
                "tile": "Timetables and real time",
                "tile_title": "Tranvía",
                "tile_city": "Vitoria",
                "switcher": "Vitoria-Gasteiz tram timetables and real time",
                "subtitle": "Tram timetables and real time",
                "liveEmpty": "Search for a stop to see the next tram.",
                "disclaimer": "An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator's forecast.",
                "attribution": "Data: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Euskotren",
                "name": "Tranvía Vitoria+",
                "tile": "Horaires et temps réel",
                "tile_title": "Tranvía",
                "tile_city": "Vitoria",
                "switcher": "Horaires et temps réel du tramway de Vitoria-Gasteiz",
                "subtitle": "Horaires et temps réel du tramway",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain tramway.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Euskotren S.A. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)"
            }
        }
    },
    "bilbobus": {
        "kind": "bus",
        "icons": "icons-bilbobus",
        "themeColor": "#C0362C",
        "title": [
            "BILBOBUS",
            "BILBAO"
        ],
        "favoritesKey": "bilbobus_favorites",
        "live": true,
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
                "name": "Bilbobus+",
                "tile": "Autobuses de Bilbao",
                "switcher": "Horarios de Bilbobus en Bilbao",
                "subtitle": "Horarios de Bilbobus",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Bilbobus ni con el Ayuntamiento de Bilbao. Horario programado oficial: no refleja retrasos ni cancelaciones.",
                "attribution": "Datos: Bilbobus / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0)"
            },
            "eu": {
                "name": "Bilbobus+",
                "tile": "Bilboko autobusak",
                "switcher": "Bilbobusen ordutegiak Bilbon",
                "subtitle": "Bilbobusen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Bilbobusekin eta Bilboko Udalarekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.",
                "attribution": "Datuak: Bilbobus / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0)"
            },
            "en": {
                "name": "Bilbobus+",
                "tile": "Bilbao buses",
                "switcher": "Bilbobus timetables in Bilbao",
                "subtitle": "Bilbobus timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Bilbobus or with Bilbao City Council. Official scheduled timetable: it does not reflect delays or cancellations.",
                "attribution": "Data: Bilbobus / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0)"
            },
            "fr": {
                "name": "Bilbobus+",
                "tile": "Bus de Bilbao",
                "switcher": "Horaires de Bilbobus à Bilbao",
                "subtitle": "Horaires de Bilbobus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Bilbobus ni avec la mairie de Bilbao. Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.",
                "attribution": "Données : Bilbobus / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0)"
            }
        }
    },
    "tuvisa": {
        "kind": "bus",
        "icons": "icons-tuvisa",
        "themeColor": "#00796B",
        "title": [
            "TUVISA",
            "VITORIA-GASTEIZ"
        ],
        "favoritesKey": "tuvisa_favorites",
        "live": true,
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
                "liveSource": "Tuvisa",
                "name": "Tuvisa+",
                "tile": "Autobuses de Vitoria-Gasteiz",
                "switcher": "Horarios y tiempo real de Tuvisa en Vitoria-Gasteiz",
                "subtitle": "Horarios y tiempo real de Tuvisa",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Tuvisa ni con el Ayuntamiento de Vitoria-Gasteiz. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Tuvisa / Open Data Euskadi, vía Moveuskadi (CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Tuvisak",
                "name": "Tuvisa+",
                "tile": "Gasteizko autobusak",
                "switcher": "Tuvisaren ordutegiak eta denbora errealean Gasteizen",
                "subtitle": "Tuvisaren ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Tuvisarekin eta Vitoria-Gasteizko Udalarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Tuvisa / Open Data Euskadi, Moveuskadiren bidez (CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Tuvisa",
                "name": "Tuvisa+",
                "tile": "Vitoria-Gasteiz buses",
                "switcher": "Tuvisa timetables and real time in Vitoria-Gasteiz",
                "subtitle": "Tuvisa timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Tuvisa or with Vitoria-Gasteiz City Council. Live times are the operator's forecast.",
                "attribution": "Data: Tuvisa / Open Data Euskadi, via Moveuskadi (CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Tuvisa",
                "name": "Tuvisa+",
                "tile": "Bus de Vitoria-Gasteiz",
                "switcher": "Horaires et temps réel de Tuvisa à Vitoria-Gasteiz",
                "subtitle": "Horaires et temps réel de Tuvisa",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Tuvisa ni avec la mairie de Vitoria-Gasteiz. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Tuvisa / Open Data Euskadi, via Moveuskadi (CC-BY 4.0)"
            }
        }
    },
    "dbus": {
        "kind": "bus",
        "icons": "icons-dbus",
        "themeColor": "#0141AD",
        "title": [
            "DBUS",
            "DONOSTIA"
        ],
        "favoritesKey": "dbus_favorites",
        "live": true,
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
                "liveSource": "Dbus",
                "name": "Dbus+",
                "tile": "Autobuses de Donostia",
                "switcher": "Horarios y tiempo real de Dbus en Donostia",
                "subtitle": "Horarios y tiempo real de Dbus",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Dbus ni con el Ayuntamiento de Donostia / San Sebastián. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Dbus / Open Data Euskadi, vía Moveuskadi (CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Dbusek",
                "name": "Dbus+",
                "tile": "Donostiako autobusak",
                "switcher": "Dbusen ordutegiak eta denbora errealean Donostian",
                "subtitle": "Dbusen ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Dbusekin eta Donostiako Udalarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Dbus / Open Data Euskadi, Moveuskadiren bidez (CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Dbus",
                "name": "Dbus+",
                "tile": "Donostia buses",
                "switcher": "Dbus timetables and real time in Donostia",
                "subtitle": "Dbus timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Dbus or with Donostia / San Sebastián City Council. Live times are the operator's forecast.",
                "attribution": "Data: Dbus / Open Data Euskadi, via Moveuskadi (CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Dbus",
                "name": "Dbus+",
                "tile": "Bus de Donostia",
                "switcher": "Horaires et temps réel de Dbus à Donostia",
                "subtitle": "Horaires et temps réel de Dbus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Dbus ni avec la mairie de Donostia / Saint-Sébastien. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Dbus / Open Data Euskadi, via Moveuskadi (CC-BY 4.0)"
            }
        }
    },
    "alavabus": {
        "kind": "bus",
        "icons": "icons-alavabus",
        "themeColor": "#A8034F",
        "title": [
            "ÁLAVABUS",
            "ARABA"
        ],
        "favoritesKey": "alavabus_favorites",
        "live": true,
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
                "liveSource": "Álavabus",
                "name": "Álavabus+",
                "tile": "Autobuses de Álava",
                "switcher": "Horarios y tiempo real de Álavabus en Álava",
                "subtitle": "Horarios y tiempo real de Álavabus",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Álavabus ni con la Diputación Foral de Álava. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Álavabus / Diputación Foral de Álava, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Álavabusek",
                "name": "Álavabus+",
                "tile": "Arabako autobusak",
                "switcher": "Álavabusen ordutegiak eta denbora errealean Araban",
                "subtitle": "Álavabusen ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Álavabusekin eta Arabako Foru Aldundiarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Álavabus / Arabako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Álavabus",
                "name": "Álavabus+",
                "tile": "Álava buses",
                "switcher": "Álavabus timetables and real time in Álava",
                "subtitle": "Álavabus timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Álavabus or with the Provincial Council of Álava. Live times are the operator's forecast.",
                "attribution": "Data: Álavabus / Provincial Council of Álava, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Álavabus",
                "name": "Álavabus+",
                "tile": "Bus d’Álava",
                "switcher": "Horaires et temps réel d’Álavabus en Álava",
                "subtitle": "Horaires et temps réel d’Álavabus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Álavabus ni avec la Députation forale d’Álava. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Álavabus / Députation forale d’Álava, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            }
        }
    },
    "launion": {
        "kind": "bus",
        "icons": "icons-launion",
        "themeColor": "#30609D",
        "title": [
            "LA UNIÓN",
            "BILBAO · GASTEIZ"
        ],
        "favoritesKey": "launion_favorites",
        "live": false,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": true,
            "stop_hints": true,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "stops",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "name": "La Unión+",
                "tile": "Autobuses Bilbao - Vitoria",
                "switcher": "Horarios de La Unión entre Bizkaia y Vitoria-Gasteiz",
                "subtitle": "Horarios de La Unión",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Autobuses La Unión. Horario programado oficial: no refleja retrasos ni cancelaciones.",
                "attribution": "Datos: Autobuses La Unión, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "name": "La Unión+",
                "tile": "Bilbo - Gasteiz autobusak",
                "switcher": "La Uniónen ordutegiak Bizkaia eta Vitoria-Gasteiz artean",
                "subtitle": "La Uniónen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Autobuses La Uniónekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.",
                "attribution": "Datuak: Autobuses La Unión, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "name": "La Unión+",
                "tile": "Bilbao - Vitoria buses",
                "switcher": "La Unión timetables between Bizkaia and Vitoria-Gasteiz",
                "subtitle": "La Unión timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Autobuses La Unión. Official scheduled timetable: it does not reflect delays or cancellations.",
                "attribution": "Data: Autobuses La Unión, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "name": "La Unión+",
                "tile": "Bus Bilbao - Vitoria",
                "switcher": "Horaires de La Unión entre la Bizkaia et Vitoria-Gasteiz",
                "subtitle": "Horaires de La Unión",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Autobuses La Unión. Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.",
                "attribution": "Données : Autobuses La Unión, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            }
        }
    },
    "renfe-ld": {
        "kind": "train",
        "icons": "icons-renfe-ld",
        "themeColor": "#74349A",
        "title": [
            "RENFE LD",
            ""
        ],
        "favoritesKey": "renfe-ld_favorites",
        "live": true,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "stops",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "liveSource": "Renfe",
                "name": "Renfe LD+",
                "tile": "Larga y media distancia en Euskadi y Navarra",
                "switcher": "Horarios y tiempo real de los trenes de larga y media distancia",
                "subtitle": "Larga y media distancia en tiempo real",
                "liveEmpty": "Busca una estación para ver el próximo tren.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Renfe. Los retrasos y los trenes cancelados son los que publica Renfe en directo.",
                "attribution": "Datos: Renfe, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Renfek",
                "name": "Renfe LD+",
                "tile": "Distantzia luzeko eta ertaineko trenak Euskadin eta Nafarroan",
                "switcher": "Distantzia luzeko eta ertaineko trenen ordutegiak eta denbora errealean",
                "subtitle": "Distantzia luzeko eta ertaineko trenak denbora errealean",
                "liveEmpty": "Bilatu geltoki bat hurrengo trena ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Renferekin loturarik gabea. Atzerapenak eta bertan behera utzitako trenak Renfek zuzenean argitaratzen dituenak dira.",
                "attribution": "Datuak: Renfe, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Renfe",
                "name": "Renfe LD+",
                "tile": "Long and medium distance trains in the Basque Country and Navarre",
                "switcher": "Long and medium distance train timetables and real time",
                "subtitle": "Long and medium distance trains in real time",
                "liveEmpty": "Search for a station to see the next train.",
                "disclaimer": "An independent, unofficial project, not affiliated with Renfe. Delays and cancelled trains are those Renfe publishes live.",
                "attribution": "Data: Renfe, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Renfe",
                "name": "Renfe LD+",
                "tile": "Longue et moyenne distance en Euskadi et en Navarre",
                "switcher": "Horaires et temps réel des trains de longue et moyenne distance",
                "subtitle": "Longue et moyenne distance en temps réel",
                "liveEmpty": "Cherchez une gare pour voir le prochain train.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Renfe. Les retards et les trains supprimés sont ceux que Renfe publie en direct.",
                "attribution": "Données : Renfe, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            }
        }
    },
    "txiktxak": {
        "kind": "bus",
        "icons": "icons-txiktxak",
        "themeColor": "#268991",
        "title": [
            "TXIK TXAK",
            ""
        ],
        "favoritesKey": "txiktxak_favorites",
        "live": true,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": false,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "liveSource": "el Syndicat des Mobilités Pays Basque-Adour",
                "name": "Txik Txak+",
                "tile": "Autobuses de Iparralde",
                "switcher": "Horarios y tiempo real de los autobuses de Iparralde",
                "subtitle": "Horarios y tiempo real",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Syndicat des Mobilités Pays Basque-Adour. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: Syndicat des Mobilités Pays Basque-Adour, vía transport.data.gouv.fr (Open Database License, ODbL 1.0)"
            },
            "eu": {
                "liveSource": "Syndicat des Mobilités Pays Basque-Adour erakundeak",
                "name": "Txik Txak+",
                "tile": "Iparraldeko autobusak",
                "switcher": "Iparraldeko autobusen ordutegiak eta denbora errealean",
                "subtitle": "Ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu geraleku bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Syndicat des Mobilités Pays Basque-Adour erakundearekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: Syndicat des Mobilités Pays Basque-Adour, transport.data.gouv.fr-en bidez (Open Database License, ODbL 1.0)"
            },
            "en": {
                "liveSource": "the Syndicat des Mobilités Pays Basque-Adour",
                "name": "Txik Txak+",
                "tile": "Buses in the Northern Basque Country",
                "switcher": "Timetables and real time for buses in the Northern Basque Country",
                "subtitle": "Timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Syndicat des Mobilités Pays Basque-Adour. Live times are the operator's forecast.",
                "attribution": "Data: Syndicat des Mobilités Pays Basque-Adour, via transport.data.gouv.fr (Open Database License, ODbL 1.0)"
            },
            "fr": {
                "liveSource": "le Syndicat des Mobilités Pays Basque-Adour",
                "name": "Txik Txak+",
                "tile": "Bus d’Iparralde",
                "switcher": "Horaires et temps réel des bus d’Iparralde",
                "subtitle": "Horaires et temps réel",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec le Syndicat des Mobilités Pays Basque-Adour. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : Syndicat des Mobilités Pays Basque-Adour, via transport.data.gouv.fr (Open Database License, ODbL 1.0)"
            }
        }
    },
    "sncf": {
        "kind": "train",
        "icons": "icons-sncf",
        "themeColor": "#0C131F",
        "title": [
            "SNCF",
            ""
        ],
        "favoritesKey": "sncf_favorites",
        "live": true,
        "features": {
            "platforms": false,
            "headsign_from_last_stop": true,
            "stop_hints": false,
            "line_map": true,
            "schedule_text": false,
            "trip_detail": "vehicle",
            "alerts": "network",
            "alerts_detail": true
        },
        "texts": {
            "es": {
                "liveSource": "SNCF",
                "name": "SNCF+",
                "tile": "Trenes de Iparralde",
                "switcher": "Horarios y tiempo real de los trenes de Iparralde",
                "subtitle": "TGV, Intercités y TER en tiempo real",
                "liveEmpty": "Busca una estación para ver el próximo tren.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con SNCF. Los tiempos en directo son una previsión del operador.",
                "attribution": "Datos: SNCF, vía transport.data.gouv.fr (Open Database License, ODbL 1.0)"
            },
            "eu": {
                "liveSource": "SNCFk",
                "name": "SNCF+",
                "tile": "Iparraldeko trenak",
                "switcher": "Iparraldeko trenen ordutegiak eta denbora errealean",
                "subtitle": "TGV, Intercités eta TER denbora errealean",
                "liveEmpty": "Bilatu geraleku bat hurrengo trena ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, SNCF erakundearekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.",
                "attribution": "Datuak: SNCF, transport.data.gouv.fr-en bidez (Open Database License, ODbL 1.0)"
            },
            "en": {
                "liveSource": "SNCF",
                "name": "SNCF+",
                "tile": "Trains in the Northern Basque Country",
                "switcher": "Timetables and real time for trains in the Northern Basque Country",
                "subtitle": "TGV, Intercités and TER in real time",
                "liveEmpty": "Search for a stop to see the next train.",
                "disclaimer": "An independent, unofficial project, not affiliated with SNCF. Live times are the operator's forecast.",
                "attribution": "Data: SNCF, via transport.data.gouv.fr (Open Database License, ODbL 1.0)"
            },
            "fr": {
                "liveSource": "SNCF",
                "name": "SNCF+",
                "tile": "Trains d’Iparralde",
                "switcher": "Horaires et temps réel des trains d’Iparralde",
                "subtitle": "TGV, Intercités et TER en temps réel",
                "liveEmpty": "Cherchez une gare pour voir le prochain train.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec SNCF. Les temps en direct sont une prévision de l’opérateur.",
                "attribution": "Données : SNCF, via transport.data.gouv.fr (Open Database License, ODbL 1.0)"
            }
        }
    },
    "villavesas": {
        "kind": "bus",
        "icons": "icons-villavesas",
        "themeColor": "#0A7A3E",
        "title": [
            "VILLAVESAS",
            ""
        ],
        "favoritesKey": "villavesas_favorites",
        "live": false,
        "features": {
            "platforms": false,
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
                "name": "Villavesas+",
                "tile": "Autobuses de Pamplona",
                "switcher": "Horarios de las villavesas de la Comarca de Pamplona",
                "subtitle": "Horarios de la Comarca de Pamplona",
                "liveEmpty": "Busca una parada para ver el próximo autobús.",
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Mancomunidad de la Comarca de Pamplona (TCC). Horario programado oficial: no refleja retrasos ni cancelaciones.",
                "attribution": "Datos: Mancomunidad de la Comarca de Pamplona (TCC), vía el Punto de Acceso Nacional de Transporte Multimodal (Licencia de datos abiertos del Ministerio de Transportes y Movilidad Sostenible)"
            },
            "eu": {
                "name": "Villavesas+",
                "tile": "Iruñeko autobusak",
                "switcher": "Iruñerriko billabesen ordutegiak",
                "subtitle": "Iruñerriko ordutegiak",
                "liveEmpty": "Bilatu geraleku bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Mancomunidad de la Comarca de Pamplona (TCC) erakundearekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.",
                "attribution": "Datuak: Mancomunidad de la Comarca de Pamplona (TCC), Garraio Multimodalaren Sarbide Puntu Nazionalaren bidez (Garraio eta Mugikortasun Jasangarriaren Ministerioaren datu irekien lizentzia)"
            },
            "en": {
                "name": "Villavesas+",
                "tile": "Pamplona buses",
                "switcher": "Timetables for the Pamplona area buses",
                "subtitle": "Pamplona area timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Mancomunidad de la Comarca de Pamplona (TCC). Official scheduled timetable: it does not reflect delays or cancellations.",
                "attribution": "Data: Mancomunidad de la Comarca de Pamplona (TCC), via the Spanish National Access Point for Multimodal Transport (Open Data Licence of the Ministry of Transport and Sustainable Mobility)"
            },
            "fr": {
                "name": "Villavesas+",
                "tile": "Bus de Pampelune",
                "switcher": "Horaires des villavesas de l’agglomération de Pampelune",
                "subtitle": "Horaires de l’agglomération de Pampelune",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec la Mancomunidad de la Comarca de Pamplona (TCC). Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.",
                "attribution": "Données : Mancomunidad de la Comarca de Pamplona (TCC), via le Point d’accès national espagnol aux données de transport multimodal (licence de données ouvertes du ministère des Transports et de la Mobilité durable)"
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
