# Redes preparadas

Fichas completas de redes que todavía no se pueden publicar. No se cargan: `Core\Networks` solo lee `api/Networks/*.php`.

| Red | Qué falta |
|---|---|
| `navarra.php` (interurbano de Navarra) | Que el Gobierno de Navarra publique un GTFS vigente: el actual acabó el 31/12/2025. |

Para publicar una: mover su ficha a `api/Networks/`, y ejecutar `php scripts/build-database.php --network=<red>`,
`build-network-map.php`, `build-offline-data.php`, `build-sitemap.php`, `build-networks-js.php`, `build-networks-css.php`
y `build-404.php`. Sus iconos provisionales ya están en `icons-<red>/`.
