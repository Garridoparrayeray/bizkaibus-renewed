# Política de seguridad

## Versiones con soporte

Solo se mantiene la versión que está en producción, que es la rama `master` desplegada en <https://bideplus.vercel.app>. Los fallos de seguridad se corrigen allí y no en versiones anteriores.

## Cómo informar de una vulnerabilidad

**No abras una issue pública.** Usa el aviso privado de GitHub: pestaña **Security** del repositorio → **Report a vulnerability**. Así solo lo ve quien mantiene el proyecto.

Si no puedes usar GitHub, contacta a través de <https://www.yeraygarrido.dev/> y di solo que quieres informar de un problema de seguridad; ya se acordará un canal privado para los detalles.

Incluye, si puedes:

- Qué has encontrado y qué efecto tiene.
- La dirección o el archivo afectado y los pasos para reproducirlo.
- Si hay datos de otras personas expuestos.
- Cómo prefieres que se te cite, si quieres reconocimiento.

## Qué esperar

Este es un proyecto personal sin ánimo de lucro. Intentaré responder en unos 7 días y te iré informando de cuándo se corrige. Pido que no hagas públicos los detalles hasta que haya una solución o hayan pasado 90 días, lo que ocurra antes.

## Alcance

Dentro del alcance: la aplicación web, su API (`/api/...`), el código del repositorio y los flujos de GitHub Actions.

Fuera del alcance:

- Los servicios de los operadores (Bizkaibus, Metro Bilbao, Euskotren, Renfe) y de las plataformas de datos abiertos. Si encuentras un problema allí, hay que comunicarlo a sus responsables.
- Vercel y GitHub como plataformas.
- Ataques de denegación de servicio, pruebas de carga y el *spam*.
- Hallazgos que dependan de un navegador o dispositivo ya comprometido.

## Datos que maneja la aplicación

La aplicación no tiene cuentas ni guarda datos personales en el servidor. Los favoritos y los ajustes se quedan en tu dispositivo, y la ubicación de «Cerca de mí» se envía solo para buscar paradas y no se guarda. Solo se recogen estadísticas básicas sin cookies con Vercel Web Analytics. Los detalles están en el aviso legal de la propia aplicación.
