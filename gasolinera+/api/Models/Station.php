<?php

namespace Models;

use Services\OpeningHours;

class Station
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Estaciones dentro de un radio circular alrededor de (lat, lon),
     * ordenadas por precio del carburante pedido o por distancia. SQLite no
     * tiene extensión espacial garantizada en el runtime de Vercel, así que
     * primero se filtra por un bounding-box en grados (usando los índices de
     * lat/lon, barato) y luego se aplica Haversine exacto en PHP solo sobre
     * ese subconjunto ya reducido, nunca sobre las ~11.500 filas completas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function near(
        float $lat,
        float $lon,
        float $radiusKm,
        string $sort,
        ?string $fuel,
        bool $open24h,
        bool $openNow,
        int $staleStationDays,
        int $offset,
        int $limit
    ): array {
        $bbox = self::boundingBox($lat, $lon, $radiusKm);
        $rows = $this->fetchInBoundingBox($bbox, $fuel, $open24h, $staleStationDays);

        $candidates = [];
        foreach ($rows as $row) {
            $distanceKm = self::haversineKm($lat, $lon, (float)$row['lat'], (float)$row['lon']);
            if ($distanceKm > $radiusKm) {
                continue;
            }
            if ($openNow && $this->isOpenNow($row['horario_raw']) !== true) {
                continue;
            }
            $candidates[] = ['row' => $row, 'distanceKm' => $distanceKm];
        }

        return $this->sortPaginateAndBuild($candidates, $sort, $fuel, $offset, $limit);
    }

    /**
     * Igual que near() pero por texto libre (municipio, dirección o rótulo)
     * en vez de radio. lat/lon son opcionales: si se pasan, cada resultado
     * lleva distancia y sort=distance funciona; si no, solo sort=price.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(
        string $query,
        ?float $lat,
        ?float $lon,
        string $sort,
        ?string $fuel,
        bool $open24h,
        bool $openNow,
        int $staleStationDays,
        int $offset,
        int $limit
    ): array {
        $normalized = Search::normalize($query);
        $like = '%' . $normalized . '%';
        $rawQuery = trim($query);
        $cpLike = $rawQuery . '%';

        $where = 'WHERE (municipio_normalizado LIKE :q1 OR direccion_normalizada LIKE :q2 OR rotulo_normalizado LIKE :q3 OR cp LIKE :q4)';
        $params = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $cpLike];
        $where .= self::staleClause($staleStationDays);
        if ($open24h) {
            $where .= ' AND is_24h = 1';
        }

        $stmt = $this->pdo->prepare("
            SELECT ideess, rotulo, direccion, municipio, cp, lat, lon, horario_raw, is_24h
            FROM stations
            $where
            LIMIT 500
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $candidates = [];
        foreach ($rows as $row) {
            $distanceKm = null;
            if ($lat !== null && $lon !== null) {
                $distanceKm = self::haversineKm($lat, $lon, (float)$row['lat'], (float)$row['lon']);
            }
            if ($openNow && $this->isOpenNow($row['horario_raw']) !== true) {
                continue;
            }
            $candidates[] = [
                'row' => $row,
                'distanceKm' => $distanceKm,
                'relevance' => $this->relevanceScore($row, $normalized, $rawQuery),
            ];
        }

        return $this->sortPaginateAndBuild($candidates, $sort, $fuel, $offset, $limit);
    }

    /**
     * Si la búsqueda coincide con el nombre de un municipio real (no solo
     * con una dirección o marca de paso), el controller la re-geocodifica
     * y ancla las distancias a ESE punto en vez de a la ubicación del
     * usuario: "busco Amorebieta" tiene que dar distancias a Amorebieta,
     * no a donde esté el usuario en ese momento.
     */
    public function looksLikePlaceQuery(string $query): bool
    {
        $normalized = Search::normalize($query);
        if ($normalized === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM stations WHERE municipio_normalizado LIKE ? LIMIT 1');
        $stmt->execute([$normalized . '%']);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * A qué se debe la coincidencia de una fila con la búsqueda, para
     * priorizar "esto ES el municipio/CP que buscas" sobre "esto lo
     * menciona de pasada" (p.ej. una carretera llamada "Bilbao" en Miranda
     * de Ebro no debería salir antes que las estaciones de Bilbao). Menor
     * número = más relevante.
     */
    private function relevanceScore(array $row, string $normalizedQuery, string $rawQuery): int
    {
        if ($normalizedQuery === '') {
            return 5;
        }
        $municipio = Search::normalize($row['municipio']);
        if ($municipio === $normalizedQuery || (string)$row['cp'] === $rawQuery) {
            return 0;
        }
        if (str_starts_with($municipio, $normalizedQuery)) {
            return 1;
        }
        if ($rawQuery !== '' && str_starts_with((string)$row['cp'], $rawQuery)) {
            return 2;
        }
        if (str_contains($municipio, $normalizedQuery)) {
            return 3;
        }
        if (str_contains(Search::normalize($row['rotulo']), $normalizedQuery)) {
            return 4;
        }
        return 5;
    }

    /**
     * Estaciones dentro de un rectángulo de coordenadas (la vista actual del
     * mapa), sin el filtro circular de Haversine porque aquí el área ya es
     * rectangular. Devuelve solo los campos necesarios para pintar
     * marcador/cluster/capa de calor, sin límite de resultados: si el
     * rectángulo pedido es España entera, se devuelven las ~11.500 y el
     * clustering del lado del cliente decide cómo agruparlas visualmente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function withinBounds(float $north, float $south, float $east, float $west, ?string $fuel, bool $open24h): array
    {
        $where = 'WHERE lat BETWEEN :south AND :north AND lon BETWEEN :west AND :east';
        $params = ['south' => $south, 'north' => $north, 'west' => $west, 'east' => $east];
        if ($open24h) {
            $where .= ' AND is_24h = 1';
        }

        $stmt = $this->pdo->prepare("
            SELECT ideess, rotulo, lat, lon, is_24h
            FROM stations
            $where
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $priceStmt = null;
        if ($fuel !== null) {
            $priceStmt = $this->pdo->prepare('SELECT precio FROM current_prices WHERE ideess = ? AND carburante = ?');
        }

        $results = [];
        foreach ($rows as $row) {
            $precio = null;
            if ($priceStmt !== null) {
                $priceStmt->execute([$row['ideess'], $fuel]);
                $value = $priceStmt->fetchColumn();
                if ($value !== false) {
                    $precio = (float)$value;
                }
            }
            $results[] = [
                'ideess' => $row['ideess'],
                'rotulo' => $row['rotulo'],
                'lat' => (float)$row['lat'],
                'lon' => (float)$row['lon'],
                'is24h' => (bool)$row['is_24h'],
                'precio' => $precio,
            ];
        }
        return $results;
    }

    /** Ficha completa de una estación por id, o null si no existe. */
    public function find(string $ideess): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stations WHERE ideess = ?');
        $stmt->execute([$ideess]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $pricesStmt = $this->pdo->prepare('SELECT carburante, precio, fecha FROM current_prices WHERE ideess = ?');
        $pricesStmt->execute([$ideess]);
        $fuels = [];
        foreach ($pricesStmt->fetchAll() as $p) {
            $fuels[$p['carburante']] = [
                'precio' => (float)$p['precio'],
                'tendencia' => $this->trendFor($ideess, $p['carburante'], (float)$p['precio']),
            ];
        }

        return [
            'ideess' => $row['ideess'],
            'rotulo' => $row['rotulo'],
            'direccion' => $row['direccion'],
            'localidad' => $row['localidad'],
            'municipio' => $row['municipio'],
            'municipioId' => $row['municipio_id'],
            'provincia' => $row['provincia'],
            'cp' => $row['cp'],
            'lat' => (float)$row['lat'],
            'lon' => (float)$row['lon'],
            'is24h' => (bool)$row['is_24h'],
            'horario' => OpeningHours::describe($row['horario_raw'], new \DateTime('now', new \DateTimeZone('Europe/Madrid'))),
            'combustibles' => $fuels,
            'lastSeenDate' => $row['last_seen_date'],
        ];
    }

    /**
     * Tendencia del precio de hoy de un carburante en una estación,
     * comparando contra la fecha más reciente anterior a hoy que tenga dato
     * en price_history. null si no hay ninguna fecha anterior (estación o
     * carburante recién dado de alta).
     */
    public function trendFor(string $ideess, string $carburante, float $todayPrice): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT precio FROM price_history
            WHERE ideess = ? AND carburante = ? AND fecha < (SELECT MAX(fecha) FROM price_history WHERE ideess = ? AND carburante = ?)
            ORDER BY fecha DESC LIMIT 1
        ');
        $stmt->execute([$ideess, $carburante, $ideess, $carburante]);
        $previous = $stmt->fetchColumn();
        if ($previous === false) {
            return null;
        }
        $previous = (float)$previous;
        if (abs($todayPrice - $previous) < 0.0005) {
            return 'same';
        }
        if ($todayPrice > $previous) {
            return 'up';
        }
        return 'down';
    }

    /**
     * Precio medio del carburante en las estaciones activas del mismo
     * municipio que $ideess, y el número de estaciones que entran en esa
     * media (excluyendo la propia estación, para comparar "contra las
     * demás", no contra sí misma incluida).
     */
    public function zoneComparison(string $ideess, string $fuel): ?array
    {
        $stmt = $this->pdo->prepare('SELECT municipio_id FROM stations WHERE ideess = ?');
        $stmt->execute([$ideess]);
        $municipioId = $stmt->fetchColumn();
        if ($municipioId === false) {
            return null;
        }

        $ownStmt = $this->pdo->prepare('SELECT precio FROM current_prices WHERE ideess = ? AND carburante = ?');
        $ownStmt->execute([$ideess, $fuel]);
        $ownPrice = $ownStmt->fetchColumn();
        if ($ownPrice === false) {
            return null;
        }

        $avgStmt = $this->pdo->prepare('
            SELECT AVG(cp.precio) AS media, COUNT(*) AS n
            FROM current_prices cp
            JOIN stations s ON s.ideess = cp.ideess
            WHERE s.municipio_id = ? AND cp.carburante = ? AND cp.ideess != ?
        ');
        $avgStmt->execute([$municipioId, $fuel, $ideess]);
        $avgRow = $avgStmt->fetch();
        if ($avgRow === false || $avgRow['media'] === null) {
            return null;
        }

        return [
            'precioPropio' => (float)$ownPrice,
            'mediaZona' => round((float)$avgRow['media'], 4),
            'estacionesEnMedia' => (int)$avgRow['n'],
        ];
    }

    /** @return array{north:float, south:float, east:float, west:float} */
    private static function boundingBox(float $lat, float $lon, float $radiusKm): array
    {
        $deltaLat = $radiusKm / 111.0;
        $cosLat = cos(deg2rad($lat));
        if (abs($cosLat) < 0.01) {
            $cosLat = 0.01;
        }
        $deltaLon = $radiusKm / (111.0 * $cosLat);
        return [
            'north' => $lat + $deltaLat,
            'south' => $lat - $deltaLat,
            'east' => $lon + $deltaLon,
            'west' => $lon - $deltaLon,
        ];
    }

    /** Distancia en km entre dos puntos, fórmula Haversine estándar. */
    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }

    private function fetchInBoundingBox(array $bbox, ?string $fuel, bool $open24h, int $staleStationDays): array
    {
        $where = 'WHERE lat BETWEEN :south AND :north AND lon BETWEEN :west AND :east';
        $params = ['south' => $bbox['south'], 'north' => $bbox['north'], 'west' => $bbox['west'], 'east' => $bbox['east']];
        $where .= self::staleClause($staleStationDays);
        if ($open24h) {
            $where .= ' AND is_24h = 1';
        }

        $stmt = $this->pdo->prepare("
            SELECT ideess, rotulo, direccion, municipio, lat, lon, horario_raw, is_24h
            FROM stations
            $where
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Excluye estaciones cuyo último snapshot confirmado es demasiado
     * antiguo (probablemente cerradas), sin borrarlas de la tabla: su ficha
     * sigue siendo accesible por id directo, solo desaparecen de los
     * listados de búsqueda.
     */
    private static function staleClause(int $staleStationDays): string
    {
        return " AND last_seen_date >= date('now', '-$staleStationDays days')";
    }

    private function buildListItem(array $row, ?float $distanceKm, ?string $primaryFuel = null): array
    {
        $pricesStmt = $this->pdo->prepare('SELECT carburante, precio FROM current_prices WHERE ideess = ?');
        $pricesStmt->execute([$row['ideess']]);
        $allPrices = [];
        foreach ($pricesStmt->fetchAll() as $p) {
            $allPrices[$p['carburante']] = (float)$p['precio'];
        }

        $distanciaKmRounded = null;
        if ($distanceKm !== null) {
            $distanciaKmRounded = round($distanceKm, 2);
        }

        $item = [
            'ideess' => $row['ideess'],
            'rotulo' => $row['rotulo'],
            'direccion' => $row['direccion'],
            'municipio' => $row['municipio'],
            'lat' => (float)$row['lat'],
            'lon' => (float)$row['lon'],
            'is24h' => (bool)$row['is_24h'],
            'horarioRaw' => $row['horario_raw'],
            'distanciaKm' => $distanciaKmRounded,
            'precios' => [],
            'tendencias' => [],
        ];

        // Los dos carburantes casi universales (95%+/98% de cobertura real)
        // se muestran siempre que existan, más el carburante filtrado si es
        // distinto de esos dos, para no repetir el mismo dato dos veces.
        $showFuels = ['gasoleo_a', 'gasolina_95_e5'];
        if ($primaryFuel !== null && !in_array($primaryFuel, $showFuels, true)) {
            $showFuels[] = $primaryFuel;
        }

        foreach ($showFuels as $slug) {
            if (!isset($allPrices[$slug])) {
                continue;
            }
            $item['precios'][$slug] = $allPrices[$slug];
            $item['tendencias'][$slug] = $this->trendFor($row['ideess'], $slug, $allPrices[$slug]);
        }

        return $item;
    }

    /** Candidatos de near()/bbox no llevan 'relevance' (todos empatan a 0, no afecta su orden). */
    private function relevanceOf(array $candidate): int
    {
        return $candidate['relevance'] ?? 0;
    }

    private function isOpenNow(string $horarioRaw): ?bool
    {
        return OpeningHours::isOpenAt($horarioRaw, new \DateTime('now', new \DateTimeZone('Europe/Madrid')));
    }

    /**
     * Ordena los candidatos (fila cruda + distancia, sin precios ni
     * tendencias todavía), pagina, y solo entonces construye el item
     * completo (con sus dos consultas extra de precio/tendencia) para la
     * página pedida. Así el coste de las N+1 consultas de buildListItem()
     * es proporcional al tamaño de página, no al total de coincidencias.
     *
     * @param array<int, array{row: array<string, mixed>, distanceKm: ?float}> $candidates
     */
    private function sortPaginateAndBuild(array $candidates, string $sort, ?string $fuel, int $offset, int $limit): array
    {
        if ($sort === 'distance') {
            usort($candidates, function ($a, $b) {
                $relevanceCmp = $this->relevanceOf($a) <=> $this->relevanceOf($b);
                if ($relevanceCmp !== 0) {
                    return $relevanceCmp;
                }
                if ($a['distanceKm'] === null && $b['distanceKm'] === null) {
                    return 0;
                }
                if ($a['distanceKm'] === null) {
                    return 1;
                }
                if ($b['distanceKm'] === null) {
                    return -1;
                }
                return $a['distanceKm'] <=> $b['distanceKm'];
            });
        } else {
            $sortFuel = $fuel;
            if ($sortFuel === null) {
                $sortFuel = 'gasoleo_a';
            }
            $ideessList = array_map(fn($c) => $c['row']['ideess'], $candidates);
            $priceMap = $this->batchSortPrices($ideessList, $sortFuel);
            usort($candidates, function ($a, $b) use ($priceMap) {
                $relevanceCmp = $this->relevanceOf($a) <=> $this->relevanceOf($b);
                if ($relevanceCmp !== 0) {
                    return $relevanceCmp;
                }
                $priceA = $priceMap[$a['row']['ideess']];
                $priceB = $priceMap[$b['row']['ideess']];
                if ($priceA === null && $priceB === null) {
                    return 0;
                }
                if ($priceA === null) {
                    return 1;
                }
                if ($priceB === null) {
                    return -1;
                }
                return $priceA <=> $priceB;
            });
        }

        $total = count($candidates);
        $page = array_slice($candidates, $offset, $limit);

        $items = [];
        foreach ($page as $candidate) {
            $items[] = $this->buildListItem($candidate['row'], $candidate['distanceKm'], $fuel);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Precio de $sortFuel (con el mismo fallback a gasolina_95_e5 que usa la
     * lista visible) para cada ideess, en una sola consulta por lote de
     * hasta 400 estaciones en vez de una consulta por estación.
     *
     * @param array<int, string> $ideessList
     * @return array<string, ?float>
     */
    private function batchSortPrices(array $ideessList, string $sortFuel): array
    {
        $result = array_fill_keys($ideessList, null);
        if (empty($ideessList)) {
            return $result;
        }

        $fuels = [$sortFuel];
        if ($sortFuel !== 'gasolina_95_e5') {
            $fuels[] = 'gasolina_95_e5';
        }
        $fuelPlaceholders = implode(',', array_fill(0, count($fuels), '?'));

        foreach (array_chunk($ideessList, 400) as $chunk) {
            $idPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("
                SELECT ideess, carburante, precio FROM current_prices
                WHERE ideess IN ($idPlaceholders) AND carburante IN ($fuelPlaceholders)
            ");
            $stmt->execute([...$chunk, ...$fuels]);

            $byStation = [];
            foreach ($stmt->fetchAll() as $row) {
                $byStation[$row['ideess']][$row['carburante']] = (float)$row['precio'];
            }
            foreach ($chunk as $ideess) {
                $prices = [];
                if (isset($byStation[$ideess])) {
                    $prices = $byStation[$ideess];
                }
                if (isset($prices[$sortFuel])) {
                    $result[$ideess] = $prices[$sortFuel];
                } elseif (isset($prices['gasolina_95_e5'])) {
                    $result[$ideess] = $prices['gasolina_95_e5'];
                }
            }
        }

        return $result;
    }
}
