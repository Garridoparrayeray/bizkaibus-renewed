# Bide+

Apps de horarios de transporte de Euskadi y alrededores (una por red, en la tabla) servidas desde un único código: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia y del Consorcio de Transportes de Bizkaia (GTFS, SIRI y GTFS-Realtime), desplegable en Vercel.

<!-- apps: la genera bideplus/scripts/build-readme.php -->
| App | Qué muestra | Datos |
|---|---|---|
| **Bizkaibus+** | Horarios y tiempo real de Bizkaibus | Bizkaibus / Open Data Bizkaia (CC-BY 4.0) |
| **Metro+** | Horarios y tiempo real de Metro Bilbao | Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0): horario y tiempo real |
| **Euskotren+** | Horarios de Euskotren | Euskotren / Open Data Euskadi (CC-BY 4.0) |
| **Renfe Cercanías+** | Horarios y tiempo real de Cercanías en Bilbao y Donostia | Renfe Cercanías / data.renfe.com (CC-BY 4.0) |
| **Lurraldebus+** | Horarios de Lurraldebus en Gipuzkoa | Lurraldebus / Diputación Foral de Gipuzkoa, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0) |
| **Tranvía Bilbao+** | Horarios y tiempo real del tranvía de Bilbao | Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0) |
| **Tranvía Vitoria+** | Horarios y tiempo real del tranvía de Vitoria-Gasteiz | Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0) |
| **Bilbobus+** | Horarios de Bilbobus en Bilbao | Bilbobus / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0) |
| **Tuvisa+** | Horarios y tiempo real de Tuvisa en Vitoria-Gasteiz | Tuvisa / Open Data Euskadi, vía Moveuskadi (CC-BY 4.0) |
| **Dbus+** | Horarios y tiempo real de Dbus en Donostia | Dbus / Open Data Euskadi, vía Moveuskadi (CC-BY 4.0) |
| **Álavabus+** | Horarios y tiempo real de Álavabus en Álava | Álavabus / Diputación Foral de Álava, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0) |
| **La Unión+** | Horarios de La Unión entre Bizkaia y Vitoria-Gasteiz | Autobuses La Unión, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0) |
| **Renfe LD+** | Horarios y tiempo real de los trenes de larga y media distancia | Renfe, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0) |
| **Txik Txak+** | Horarios y tiempo real de los autobuses de Iparralde | Syndicat des Mobilités Pays Basque-Adour, vía transport.data.gouv.fr (Open Database License, ODbL 1.0) |
| **SNCF+** | Horarios y tiempo real de los trenes de Iparralde | SNCF, vía transport.data.gouv.fr (Open Database License, ODbL 1.0) |
| **Villavesas+** | Horarios de las villavesas de la Comarca de Pamplona | Mancomunidad de la Comarca de Pamplona (TCC), vía el Punto de Acceso Nacional de Transporte Multimodal (Licencia de datos abiertos del Ministerio de Transportes y Movilidad Sostenible) |
<!-- /apps -->

## Licencia

El código, el diseño y los iconos de este proyecto son de Yeray Garrido Parra, con todos los derechos reservados salvo los permisos del archivo [LICENSE](../LICENSE): verlo, ejecutarlo en local, estudiarlo e investigarlo a título particular y sin fines comerciales. Cualquier uso por organizaciones, público o comercial necesita una licencia del autor. Los datos de transporte son de sus operadores y mantienen sus propias licencias (véase «Atribución de datos»). Los nombres y logotipos de las redes de transporte y de sus operadores son de sus titulares.

## Atribución de datos

Los horarios y el tiempo real son datos abiertos de cada operador, con sus propias licencias. Cada app los cita en su pantalla y en Info.

Bide+ es un proyecto independiente, sin relación con los operadores ni con los organismos que publican los datos.

## El algoritmo

La carpeta `engine/` (la capa de cálculo de llegadas de Bizkaibus, y de Lurraldebus y Bilbobus, que heredan de ella) y su documentación no están en este repositorio: viven en un repositorio privado y los workflows de GitHub Actions la traen con el secreto `ENGINE_REPO_TOKEN` antes de probar o desplegar. Para trabajar en local, clona el repositorio privado en `bideplus/engine`.

Cómo se conectan: la ficha de una red con el algoritmo lleva `'live' => ['vehicle-monitoring']` y `'engine' => '<motor>'`; `Core\Live` busca la clase `Engine\<Motor>\Live`, que `api/autoload.php` carga de `engine/<motor>/`. Sin `engine/`, esas redes salen con el horario oficial, sin error. Las redes con GTFS-Realtime no usan el motor: se emparejan en la app (`TripDelayMatcher`, `TripUpdatesMatcher` y, en Metro, cuyo feed trae ids de viaje de otra generación del horario, `PlatformMatcher`, por andén y destino con los ajustes de `gtfs_rt.platform_matching` de su ficha). Una red que solo publica posiciones (`'live' => ['vehicle-positions']`) pasa por `TripDelayMatcher` con el retraso que da el GPS de cada vehículo sobre su horario (`VehicleDelays`).

Las pruebas de una rama de la app usan la rama del motor con el mismo nombre, si existe; el despliegue y la captura, siempre su `main`. Con cambios en los dos repositorios, primero se fusiona el motor en `main` y luego la app en `master`: al revés, producción saldría con la app nueva y el motor viejo.
