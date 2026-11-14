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
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Bizkaibus ni con la Diputación Foral de Bizkaia. Las llegadas se calculan con la posición GPS de cada autobús.",
                "dataPublished": "La posición GPS de cada autobús, cada minuto.",
                "dataMethod": "Algoritmo propio: dónde va cada autobús y lo que tardan de verdad los autobuses en cada tramo.",
                "dataLimits": "Sin GPS de un autobús, hora estimada con lo aprendido. De 23:58 a 05:00 el operador repite datos viejos: horario oficial.",
                "attribution": "Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
            },
            "eu": {
                "name": "Bizkaibus+",
                "tile": "Ordutegiak eta zuzenekoa",
                "switcher": "Bizkaibusen ordutegiak eta zuzenekoa",
                "subtitle": "Bizkaibusen ordutegiak eta zuzenekoa",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Bizkaibusekin eta Bizkaiko Foru Aldundiarekin loturarik gabea. Iritsierak autobus bakoitzaren GPS kokapenarekin kalkulatzen dira.",
                "dataPublished": "Autobus bakoitzaren GPS kokapena, minuturo.",
                "dataMethod": "Algoritmo propioa: autobus bakoitza non doan eta autobusek tarte bakoitzean benetan behar dutena.",
                "dataLimits": "Autobus baten GPSrik gabe, ikasitakoarekin kalkulatutako ordua. 23:58etik 05:00etara operadoreak datu zaharrak errepikatzen ditu: ordutegi ofiziala.",
                "attribution": "Datuak: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
            },
            "en": {
                "name": "Bizkaibus+",
                "tile": "Timetables and real time",
                "switcher": "Bizkaibus timetables and real time",
                "subtitle": "Bizkaibus timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Bizkaibus or with the Provincial Council of Bizkaia. Arrivals are calculated from the GPS position of every bus.",
                "dataPublished": "The GPS position of every bus, every minute.",
                "dataMethod": "Our own algorithm: where each bus is and how long buses really take on each stretch.",
                "dataLimits": "Without a bus's GPS, an estimated time from what it has learned. From 23:58 to 05:00 the operator repeats old data: official timetable.",
                "attribution": "Data: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)"
            },
            "fr": {
                "name": "Bizkaibus+",
                "tile": "Horaires et temps réel",
                "switcher": "Horaires et temps réel de Bizkaibus",
                "subtitle": "Horaires et temps réel de Bizkaibus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Bizkaibus ni avec la Députation forale de Bizkaia. Les arrivées sont calculées avec la position GPS de chaque bus.",
                "dataPublished": "La position GPS de chaque bus, chaque minute.",
                "dataMethod": "Algorithme propre : où se trouve chaque bus et le temps que les bus mettent réellement sur chaque tronçon.",
                "dataLimits": "Sans GPS d’un bus, heure estimée avec ce qui a été appris. De 23 h 58 à 5 h 00, l’opérateur répète d’anciennes données : horaire officiel.",
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
                "dataPublished": "La hora prevista de cada tren en cada estación y su posición.",
                "dataMethod": "La previsión del operador; si manda una estación con el número de otra, se corrige.",
                "dataLimits": "Sin limitaciones conocidas.",
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
                "dataPublished": "Tren bakoitzaren aurreikusitako ordua geltoki bakoitzean, eta haren kokapena.",
                "dataMethod": "Operadorearen aurreikuspena; geltoki bat beste baten zenbakiarekin bidaltzen badu, zuzentzen da.",
                "dataLimits": "Ez dago muga ezagunik.",
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
                "dataPublished": "The predicted time of each train at each station, and its position.",
                "dataMethod": "The operator's forecast; if it sends a station with another one's number, it is corrected.",
                "dataLimits": "No known limitations.",
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
                "dataPublished": "L’heure prévue de chaque train à chaque station et sa position.",
                "dataMethod": "La prévision de l’opérateur ; s’il envoie une station avec le numéro d’une autre, c’est corrigé.",
                "dataLimits": "Aucune limite connue.",
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
                "dataPublished": "Solo su horario: su directo repite el horario y no da posiciones.",
                "dataMethod": "Horario oficial.",
                "dataLimits": "Sin retrasos ni cancelaciones.",
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
                "dataPublished": "Bere ordutegia bakarrik: zuzenekoak ordutegia errepikatzen du eta ez du kokapenik ematen.",
                "dataMethod": "Ordutegi ofiziala.",
                "dataLimits": "Atzerapenik eta bertan behera utzitakorik gabe.",
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
                "dataPublished": "Only its timetable: its live feed repeats the timetable and gives no positions.",
                "dataMethod": "The official timetable.",
                "dataLimits": "No delays or cancellations.",
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
                "dataPublished": "Seulement son horaire : son flux en direct répète l’horaire et ne donne pas de positions.",
                "dataMethod": "Horaire officiel.",
                "dataLimits": "Ni retards ni suppressions.",
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
                "dataPublished": "El retraso de cada tren, los cancelados y su posición.",
                "dataMethod": "El retraso que publica Renfe.",
                "dataLimits": "Hasta que sale de su primera estación, un tren va con su horario: Renfe lo publica sin retraso.",
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
                "dataPublished": "Tren bakoitzaren atzerapena, bertan behera utzitakoak eta haren kokapena.",
                "dataMethod": "Renfek argitaratzen duen atzerapena.",
                "dataLimits": "Lehen geltokitik irten arte, trena bere ordutegiarekin doa: Renfek atzerapenik gabe argitaratzen du.",
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
                "dataPublished": "Each train's delay, cancellations and its position.",
                "dataMethod": "The delay Renfe publishes.",
                "dataLimits": "Until it leaves its first station, a train runs on its timetable: Renfe publishes it with no delay.",
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
                "dataPublished": "Le retard de chaque train, les trains supprimés et leur position.",
                "dataMethod": "Le retard publié par Renfe.",
                "dataLimits": "Tant qu’il n’a pas quitté sa première gare, un train suit son horaire : Renfe le publie sans retard.",
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
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Lurraldebus ni con la Diputación Foral de Gipuzkoa. Las llegadas se calculan con la posición GPS de cada autobús; sin datos del autobús, horario oficial.",
                "dataPublished": "La posición GPS de cada autobús de sus 8 operadores, aunque a menudo llega vacía.",
                "dataMethod": "El mismo algoritmo que Bizkaibus.",
                "dataLimits": "Cuando su feed llega vacío, horario oficial.",
                "attribution": "Datos: Lurraldebus / Diputación Foral de Gipuzkoa, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "name": "Lurraldebus+",
                "tile": "Gipuzkoako autobusak",
                "switcher": "Lurraldebusen ordutegiak Gipuzkoan",
                "subtitle": "Lurraldebusen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Lurraldebusekin eta Gipuzkoako Foru Aldundiarekin loturarik gabea. Iritsierak autobus bakoitzaren GPS kokapenarekin kalkulatzen dira; autobusaren daturik gabe, ordutegi ofiziala.",
                "dataPublished": "Autobus bakoitzaren GPS kokapena bere 8 operadoreetan, nahiz eta askotan hutsik iritsi.",
                "dataMethod": "Bizkaibusen algoritmo bera.",
                "dataLimits": "Jarioa hutsik datorrenean, ordutegi ofiziala.",
                "attribution": "Datuak: Lurraldebus / Gipuzkoako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "name": "Lurraldebus+",
                "tile": "Gipuzkoa buses",
                "switcher": "Lurraldebus timetables in Gipuzkoa",
                "subtitle": "Lurraldebus timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Lurraldebus or with the Provincial Council of Gipuzkoa. Arrivals are calculated from the GPS position of every bus; without data from the bus, the official timetable.",
                "dataPublished": "The GPS position of every bus for its 8 operators, although it often comes empty.",
                "dataMethod": "The same algorithm as Bizkaibus.",
                "dataLimits": "When its feed comes empty, the official timetable.",
                "attribution": "Data: Lurraldebus / Provincial Council of Gipuzkoa, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "name": "Lurraldebus+",
                "tile": "Bus du Gipuzkoa",
                "switcher": "Horaires de Lurraldebus au Gipuzkoa",
                "subtitle": "Horaires de Lurraldebus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Lurraldebus ni avec la Députation forale du Gipuzkoa. Les arrivées sont calculées avec la position GPS de chaque bus ; sans données du bus, horaire officiel.",
                "dataPublished": "La position GPS de chaque bus de ses 8 opérateurs, même si elle arrive souvent vide.",
                "dataMethod": "Le même algorithme que Bizkaibus.",
                "dataLimits": "Quand son flux arrive vide, horaire officiel.",
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
                "dataPublished": "La hora prevista de algunos tranvías, en el feed de Euskotren.",
                "dataMethod": "La previsión del operador cuando es real; si no, horario oficial.",
                "dataLimits": "Muchos viajes llegan sin directo y salen con su horario.",
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
                "dataPublished": "Tranbia batzuen aurreikusitako ordua, Euskotrenen jarioan.",
                "dataMethod": "Operadorearen aurreikuspena benetakoa denean; bestela, ordutegi ofiziala.",
                "dataLimits": "Bidaia asko zuzenekorik gabe iristen dira eta beren ordutegiarekin ateratzen dira.",
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
                "dataPublished": "The predicted time of some trams, in Euskotren's feed.",
                "dataMethod": "The operator's forecast when it is real; otherwise, the official timetable.",
                "dataLimits": "Many trips come without live data and show their timetable.",
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
                "dataPublished": "L’heure prévue de certains tramways, dans le flux d’Euskotren.",
                "dataMethod": "La prévision de l’opérateur quand elle est réelle ; sinon, horaire officiel.",
                "dataLimits": "Beaucoup de trajets arrivent sans temps réel et suivent leur horaire.",
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
                "dataPublished": "La hora prevista de algunos tranvías, en el feed de Euskotren.",
                "dataMethod": "La previsión del operador cuando es real; si no, horario oficial.",
                "dataLimits": "Muchos viajes llegan sin directo y salen con su horario.",
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
                "dataPublished": "Tranbia batzuen aurreikusitako ordua, Euskotrenen jarioan.",
                "dataMethod": "Operadorearen aurreikuspena benetakoa denean; bestela, ordutegi ofiziala.",
                "dataLimits": "Bidaia asko zuzenekorik gabe iristen dira eta beren ordutegiarekin ateratzen dira.",
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
                "dataPublished": "The predicted time of some trams, in Euskotren's feed.",
                "dataMethod": "The operator's forecast when it is real; otherwise, the official timetable.",
                "dataLimits": "Many trips come without live data and show their timetable.",
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
                "dataPublished": "L’heure prévue de certains tramways, dans le flux d’Euskotren.",
                "dataMethod": "La prévision de l’opérateur quand elle est réelle ; sinon, horaire officiel.",
                "dataLimits": "Beaucoup de trajets arrivent sans temps réel et suivent leur horaire.",
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
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Bilbobus ni con el Ayuntamiento de Bilbao. Las llegadas se calculan con la posición GPS de cada autobús; sin datos del autobús, horario oficial.",
                "dataPublished": "La posición GPS de cada autobús, con cortes frecuentes.",
                "dataMethod": "El mismo algoritmo que Bizkaibus.",
                "dataLimits": "Cuando su feed llega vacío, horario oficial.",
                "attribution": "Datos: Bilbobus / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0)"
            },
            "eu": {
                "name": "Bilbobus+",
                "tile": "Bilboko autobusak",
                "switcher": "Bilbobusen ordutegiak Bilbon",
                "subtitle": "Bilbobusen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Bilbobusekin eta Bilboko Udalarekin loturarik gabea. Iritsierak autobus bakoitzaren GPS kokapenarekin kalkulatzen dira; autobusaren daturik gabe, ordutegi ofiziala.",
                "dataPublished": "Autobus bakoitzaren GPS kokapena, sarritan etenda.",
                "dataMethod": "Bizkaibusen algoritmo bera.",
                "dataLimits": "Jarioa hutsik datorrenean, ordutegi ofiziala.",
                "attribution": "Datuak: Bilbobus / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0)"
            },
            "en": {
                "name": "Bilbobus+",
                "tile": "Bilbao buses",
                "switcher": "Bilbobus timetables in Bilbao",
                "subtitle": "Bilbobus timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Bilbobus or with Bilbao City Council. Arrivals are calculated from the GPS position of every bus; without data from the bus, the official timetable.",
                "dataPublished": "The GPS position of every bus, with frequent outages.",
                "dataMethod": "The same algorithm as Bizkaibus.",
                "dataLimits": "When its feed comes empty, the official timetable.",
                "attribution": "Data: Bilbobus / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0)"
            },
            "fr": {
                "name": "Bilbobus+",
                "tile": "Bus de Bilbao",
                "switcher": "Horaires de Bilbobus à Bilbao",
                "subtitle": "Horaires de Bilbobus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Bilbobus ni avec la mairie de Bilbao. Les arrivées sont calculées avec la position GPS de chaque bus ; sans données du bus, horaire officiel.",
                "dataPublished": "La position GPS de chaque bus, avec des coupures fréquentes.",
                "dataMethod": "Le même algorithme que Bizkaibus.",
                "dataLimits": "Quand son flux arrive vide, horaire officiel.",
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
                "dataPublished": "La hora prevista y la posición GPS de cada autobús.",
                "dataMethod": "La previsión del operador.",
                "dataLimits": "Sin limitaciones conocidas.",
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
                "dataPublished": "Autobus bakoitzaren aurreikusitako ordua eta GPS kokapena.",
                "dataMethod": "Operadorearen aurreikuspena.",
                "dataLimits": "Ez dago muga ezagunik.",
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
                "dataPublished": "The predicted time and GPS position of every bus.",
                "dataMethod": "The operator's forecast.",
                "dataLimits": "No known limitations.",
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
                "dataPublished": "L’heure prévue et la position GPS de chaque bus.",
                "dataMethod": "La prévision de l’opérateur.",
                "dataLimits": "Aucune limite connue.",
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
                "dataPublished": "La hora prevista y la posición GPS de cada autobús.",
                "dataMethod": "La previsión del operador.",
                "dataLimits": "Sin limitaciones conocidas.",
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
                "dataPublished": "Autobus bakoitzaren aurreikusitako ordua eta GPS kokapena.",
                "dataMethod": "Operadorearen aurreikuspena.",
                "dataLimits": "Ez dago muga ezagunik.",
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
                "dataPublished": "The predicted time and GPS position of every bus.",
                "dataMethod": "The operator's forecast.",
                "dataLimits": "No known limitations.",
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
                "dataPublished": "L’heure prévue et la position GPS de chaque bus.",
                "dataMethod": "La prévision de l’opérateur.",
                "dataLimits": "Aucune limite connue.",
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
                "disclaimer": "Proyecto independiente y no oficial, sin relación con Álavabus ni con la Diputación Foral de Álava. Los tiempos en directo salen de la posición GPS de cada autobús o, si no la hay, de la previsión del operador.",
                "dataPublished": "La posición GPS y la hora prevista de cada autobús, con fallos: horas adelantadas una hora, paradas ya pasadas, viajes de otros días y el feed vacío o parado a ratos.",
                "dataMethod": "La posición GPS de cada autobús; la previsión del operador, solo sin GPS y si no se aparta más de 45 min.",
                "dataLimits": "Con el feed vacío o parado, horario oficial. En preparación: el algoritmo de Bizkaibus.",
                "attribution": "Datos: Álavabus / Diputación Foral de Álava, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "liveSource": "Álavabusek",
                "name": "Álavabus+",
                "tile": "Arabako autobusak",
                "switcher": "Álavabusen ordutegiak eta denbora errealean Araban",
                "subtitle": "Álavabusen ordutegiak eta denbora errealean",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Álavabusekin eta Arabako Foru Aldundiarekin loturarik gabea. Zuzeneko denborak autobus bakoitzaren GPS kokapenetik ateratzen dira edo, ez badago, operadorearen aurreikuspenetik.",
                "dataPublished": "Autobus bakoitzaren GPS kokapena eta aurreikusitako ordua, akatsekin: ordu bat aurreratuta, dagoeneko pasatutako geltokiak, beste egun batzuetako bidaiak eta jarioa hutsik edo geldirik batzuetan.",
                "dataMethod": "Autobus bakoitzaren GPS kokapena; operadorearen aurreikuspena, GPSrik gabe eta 45 minutu baino gehiago aldentzen ez bada bakarrik.",
                "dataLimits": "Jarioa hutsik edo geldirik dagoenean, ordutegi ofiziala. Prestatzen: Bizkaibusen algoritmoa.",
                "attribution": "Datuak: Álavabus / Arabako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "liveSource": "Álavabus",
                "name": "Álavabus+",
                "tile": "Álava buses",
                "switcher": "Álavabus timetables and real time in Álava",
                "subtitle": "Álavabus timetables and real time",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Álavabus or with the Provincial Council of Álava. Live times come from the GPS position of every bus or, without it, from the operator's forecast.",
                "dataPublished": "The GPS position and predicted time of every bus, with errors: times an hour ahead, stops already passed, trips from other days and a feed that is empty or frozen at times.",
                "dataMethod": "Each bus's GPS position; the operator's forecast only without GPS and if it is no more than 45 min off.",
                "dataLimits": "With the feed empty or frozen, the official timetable. In preparation: the Bizkaibus algorithm.",
                "attribution": "Data: Álavabus / Provincial Council of Álava, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "liveSource": "Álavabus",
                "name": "Álavabus+",
                "tile": "Bus d’Álava",
                "switcher": "Horaires et temps réel d’Álavabus en Álava",
                "subtitle": "Horaires et temps réel d’Álavabus",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Álavabus ni avec la Députation forale d’Álava. Les temps en direct viennent de la position GPS de chaque bus ou, à défaut, de la prévision de l’opérateur.",
                "dataPublished": "La position GPS et l’heure prévue de chaque bus, avec des erreurs : heures avancées d’une heure, arrêts déjà passés, trajets d’autres jours et flux vide ou figé par moments.",
                "dataMethod": "La position GPS de chaque bus ; la prévision de l’opérateur, seulement sans GPS et si elle ne s’écarte pas de plus de 45 min.",
                "dataLimits": "Avec le flux vide ou figé, horaire officiel. En préparation : l’algorithme de Bizkaibus.",
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
                "dataPublished": "Solo su horario.",
                "dataMethod": "Horario oficial.",
                "dataLimits": "Sin retrasos ni cancelaciones.",
                "attribution": "Datos: Autobuses La Unión, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "eu": {
                "name": "La Unión+",
                "tile": "Bilbo - Gasteiz autobusak",
                "switcher": "La Uniónen ordutegiak Bizkaia eta Vitoria-Gasteiz artean",
                "subtitle": "La Uniónen ordutegiak",
                "liveEmpty": "Bilatu parada bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Autobuses La Uniónekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.",
                "dataPublished": "Bere ordutegia bakarrik.",
                "dataMethod": "Ordutegi ofiziala.",
                "dataLimits": "Atzerapenik eta bertan behera utzitakorik gabe.",
                "attribution": "Datuak: Autobuses La Unión, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)"
            },
            "en": {
                "name": "La Unión+",
                "tile": "Bilbao - Vitoria buses",
                "switcher": "La Unión timetables between Bizkaia and Vitoria-Gasteiz",
                "subtitle": "La Unión timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Autobuses La Unión. Official scheduled timetable: it does not reflect delays or cancellations.",
                "dataPublished": "Only its timetable.",
                "dataMethod": "The official timetable.",
                "dataLimits": "No delays or cancellations.",
                "attribution": "Data: Autobuses La Unión, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)"
            },
            "fr": {
                "name": "La Unión+",
                "tile": "Bus Bilbao - Vitoria",
                "switcher": "Horaires de La Unión entre la Bizkaia et Vitoria-Gasteiz",
                "subtitle": "Horaires de La Unión",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec Autobuses La Unión. Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.",
                "dataPublished": "Seulement son horaire.",
                "dataMethod": "Horaire officiel.",
                "dataLimits": "Ni retards ni suppressions.",
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
                "dataPublished": "El retraso de cada tren, los cancelados y su posición, en un directo de toda España.",
                "dataMethod": "El retraso que publica Renfe, en los trenes que salen de Euskadi y Navarra.",
                "dataLimits": "Algunos trenes no salen en su directo y van con su horario.",
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
                "dataPublished": "Tren bakoitzaren atzerapena, bertan behera utzitakoak eta haren kokapena, Espainia osoko zuzeneko batean.",
                "dataMethod": "Renfek argitaratzen duen atzerapena, Euskaditik eta Nafarroatik ateratzen diren trenetan.",
                "dataLimits": "Tren batzuk ez daude zuzenekoan eta beren ordutegiarekin doaz.",
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
                "dataPublished": "Each train's delay, cancellations and its position, in a live feed for all of Spain.",
                "dataMethod": "The delay Renfe publishes, for the trains leaving the Basque Country and Navarre.",
                "dataLimits": "Some trains are missing from the live feed and show their timetable.",
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
                "dataPublished": "Le retard de chaque train, les trains supprimés et leur position, dans un flux en direct pour toute l’Espagne.",
                "dataMethod": "Le retard publié par Renfe, pour les trains au départ d’Euskadi et de Navarre.",
                "dataLimits": "Certains trains n’apparaissent pas dans le flux et suivent leur horaire.",
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
                "dataPublished": "La hora prevista de cada autobús en cada parada, sin su posición.",
                "dataMethod": "La previsión del operador.",
                "dataLimits": "Sin posición GPS: no se ve por dónde va el autobús.",
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
                "dataPublished": "Autobus bakoitzaren aurreikusitako ordua geltoki bakoitzean, kokapenik gabe.",
                "dataMethod": "Operadorearen aurreikuspena.",
                "dataLimits": "GPS kokapenik gabe: ezin da ikusi autobusa non doan.",
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
                "dataPublished": "The predicted time of each bus at each stop, without its position.",
                "dataMethod": "The operator's forecast.",
                "dataLimits": "No GPS position: you cannot see where the bus is.",
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
                "dataPublished": "L’heure prévue de chaque bus à chaque arrêt, sans sa position.",
                "dataMethod": "La prévision de l’opérateur.",
                "dataLimits": "Sans position GPS : on ne voit pas où se trouve le bus.",
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
                "dataPublished": "La hora prevista de cada tren en cada estación, sin su posición.",
                "dataMethod": "La previsión del operador, en los trenes que paran en las estaciones de Iparralde.",
                "dataLimits": "Sin posición GPS: no se ve por dónde va el tren.",
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
                "dataPublished": "Tren bakoitzaren aurreikusitako ordua geltoki bakoitzean, kokapenik gabe.",
                "dataMethod": "Operadorearen aurreikuspena, Iparraldeko geltokietan gelditzen diren trenetan.",
                "dataLimits": "GPS kokapenik gabe: ezin da ikusi trena non doan.",
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
                "dataPublished": "The predicted time of each train at each station, without its position.",
                "dataMethod": "The operator's forecast, for the trains that stop at the stations of Iparralde.",
                "dataLimits": "No GPS position: you cannot see where the train is.",
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
                "dataPublished": "L’heure prévue de chaque train dans chaque gare, sans sa position.",
                "dataMethod": "La prévision de l’opérateur, pour les trains qui s’arrêtent dans les gares d’Iparralde.",
                "dataLimits": "Sans position GPS : on ne voit pas où se trouve le train.",
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
                "dataPublished": "Solo su horario.",
                "dataMethod": "Horario oficial.",
                "dataLimits": "Sin retrasos ni cancelaciones.",
                "attribution": "Datos: Mancomunidad de la Comarca de Pamplona (TCC), vía el Punto de Acceso Nacional de Transporte Multimodal (Licencia de datos abiertos del Ministerio de Transportes y Movilidad Sostenible)"
            },
            "eu": {
                "name": "Villavesas+",
                "tile": "Iruñeko autobusak",
                "switcher": "Iruñerriko billabesen ordutegiak",
                "subtitle": "Iruñerriko ordutegiak",
                "liveEmpty": "Bilatu geraleku bat hurrengo autobusa ikusteko.",
                "disclaimer": "Proiektu independentea eta ez-ofiziala, Mancomunidad de la Comarca de Pamplona (TCC) erakundearekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.",
                "dataPublished": "Bere ordutegia bakarrik.",
                "dataMethod": "Ordutegi ofiziala.",
                "dataLimits": "Atzerapenik eta bertan behera utzitakorik gabe.",
                "attribution": "Datuak: Mancomunidad de la Comarca de Pamplona (TCC), Garraio Multimodalaren Sarbide Puntu Nazionalaren bidez (Garraio eta Mugikortasun Jasangarriaren Ministerioaren datu irekien lizentzia)"
            },
            "en": {
                "name": "Villavesas+",
                "tile": "Pamplona buses",
                "switcher": "Timetables for the Pamplona area buses",
                "subtitle": "Pamplona area timetables",
                "liveEmpty": "Search for a stop to see the next bus.",
                "disclaimer": "An independent, unofficial project, not affiliated with Mancomunidad de la Comarca de Pamplona (TCC). Official scheduled timetable: it does not reflect delays or cancellations.",
                "dataPublished": "Only its timetable.",
                "dataMethod": "The official timetable.",
                "dataLimits": "No delays or cancellations.",
                "attribution": "Data: Mancomunidad de la Comarca de Pamplona (TCC), via the Spanish National Access Point for Multimodal Transport (Open Data Licence of the Ministry of Transport and Sustainable Mobility)"
            },
            "fr": {
                "name": "Villavesas+",
                "tile": "Bus de Pampelune",
                "switcher": "Horaires des villavesas de l’agglomération de Pampelune",
                "subtitle": "Horaires de l’agglomération de Pampelune",
                "liveEmpty": "Cherchez un arrêt pour voir le prochain bus.",
                "disclaimer": "Projet indépendant et non officiel, sans lien avec la Mancomunidad de la Comarca de Pamplona (TCC). Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.",
                "dataPublished": "Seulement son horaire.",
                "dataMethod": "Horaire officiel.",
                "dataLimits": "Ni retards ni suppressions.",
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
