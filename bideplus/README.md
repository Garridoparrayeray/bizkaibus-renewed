# Bide+

Apps de horarios de transporte de Euskadi (Bizkaibus, Metro Bilbao, Euskotren, Renfe Cercanías, los tranvías de Bilbao y Vitoria-Gasteiz, Lurraldebus, Bilbobus, Tuvisa, Dbus y Álavabus) servidas desde un único código: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia y del Consorcio de Transportes de Bizkaia (GTFS, SIRI y GTFS-Realtime), desplegable en Vercel.

## Licencia

El código, el diseño y los iconos de este proyecto son de Yeray Garrido Parra, con todos los derechos reservados salvo los permisos del archivo [LICENSE](../LICENSE): verlo, ejecutarlo en local, estudiarlo e investigarlo a título particular y sin fines comerciales. Cualquier uso por organizaciones, público o comercial necesita una licencia del autor. Los datos de transporte son de sus operadores y mantienen sus propias licencias (véase «Atribución de datos»). Los nombres y logotipos de las redes de transporte y de sus operadores son de sus titulares.

## Atribución de datos

Los horarios y el tiempo real son datos abiertos de cada operador, con sus propias licencias. Cada app los cita en su pantalla y en Info.

Bide+ es un proyecto independiente, sin relación con los operadores ni con los organismos que publican los datos.

## El algoritmo

La carpeta `engine/` (la capa de cálculo de llegadas de Bizkaibus, y de Lurraldebus y Bilbobus, que heredan de ella) y su documentación no están en este repositorio: viven en un repositorio privado y los workflows de GitHub Actions la traen con el secreto `ENGINE_REPO_TOKEN` antes de probar o desplegar. Para trabajar en local, clona el repositorio privado en `bideplus/engine`.
