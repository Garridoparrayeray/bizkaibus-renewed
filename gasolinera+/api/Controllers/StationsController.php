<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Models\Station;

class StationsController
{
    private const VALID_FUELS = [
        'gasoleo_a', 'gasolina_95_e5', 'gasoleo_premium',
        'gasolina_98_e5', 'adblue', 'glp',
    ];

    /**
     * Gasolineras dentro de un radio circular alrededor de (lat, lon),
     * ordenadas por precio o distancia, ruta /stations/near.
     */
    public function near(Request $request): void
    {
        $lat = $request->query('lat');
        $lon = $request->query('lon');
        if ($lat === null || $lon === null) {
            Response::error('lat y lon son obligatorios', 422);
            return;
        }

        $config = Config::current();
        $radius = $request->queryInt('radius', 5);
        if ($radius === null || $radius <= 0) {
            $radius = 5;
        }
        if ($radius > $config['nearby_max_radius_km']) {
            $radius = $config['nearby_max_radius_km'];
        }

        $fuel = $this->normalizeFuel($request->query('fuel'));
        $sort = $this->normalizeSort($request->query('sort'));
        [$open24h, $openNow] = $this->parseOpenParam($request->query('open'));

        $model = new Station(Database::connection());
        $results = $model->near(
            (float)$lat,
            (float)$lon,
            (float)$radius,
            $sort,
            $fuel,
            $open24h,
            $openNow,
            (int)$config['stale_station_days']
        );

        Response::json(['stations' => $results, 'attribution' => $config['attribution']]);
    }

    /** Buscador de texto (municipio, dirección o marca), alternativa al radio, ruta /stations/search. */
    public function search(Request $request): void
    {
        $q = trim((string)$request->query('q', ''));
        if (mb_strlen($q) < 2) {
            Response::json(['stations' => []]);
            return;
        }

        $config = Config::current();
        $lat = $request->query('lat');
        $lon = $request->query('lon');
        $latFloat = null;
        if ($lat !== null) {
            $latFloat = (float)$lat;
        }
        $lonFloat = null;
        if ($lon !== null) {
            $lonFloat = (float)$lon;
        }

        $fuel = $this->normalizeFuel($request->query('fuel'));
        $sort = $this->normalizeSort($request->query('sort'));
        if ($latFloat === null && $sort === 'distance') {
            $sort = 'price';
        }
        [$open24h, $openNow] = $this->parseOpenParam($request->query('open'));

        $model = new Station(Database::connection());
        $results = $model->search(
            $q,
            $latFloat,
            $lonFloat,
            $sort,
            $fuel,
            $open24h,
            $openNow,
            (int)$config['stale_station_days']
        );

        Response::json(['stations' => $results, 'attribution' => $config['attribution']]);
    }

    /**
     * Gasolineras dentro de un rectángulo de coordenadas (la vista actual
     * del mapa), sin límite de resultados, ruta /stations/bbox.
     */
    public function bbox(Request $request): void
    {
        $north = $request->query('north');
        $south = $request->query('south');
        $east = $request->query('east');
        $west = $request->query('west');
        if ($north === null || $south === null || $east === null || $west === null) {
            Response::error('north, south, east y west son obligatorios', 422);
            return;
        }

        $fuel = $this->normalizeFuel($request->query('fuel'));
        [$open24h, ] = $this->parseOpenParam($request->query('open'));

        $model = new Station(Database::connection());
        $results = $model->withinBounds((float)$north, (float)$south, (float)$east, (float)$west, $fuel, $open24h);

        Response::json(['stations' => $results]);
    }

    /** Ficha completa de una estación, ruta /stations/{ideess}. */
    public function show(Request $request, array $params): void
    {
        $model = new Station(Database::connection());
        $station = $model->find($params['ideess']);
        if ($station === null) {
            Response::error('Estación no encontrada', 404);
            return;
        }
        $station['attribution'] = Config::current()['attribution'];
        Response::json($station);
    }

    /** Serie temporal de precio de un carburante en una estación, para la gráfica de evolución, ruta /stations/{ideess}/history. */
    public function history(Request $request, array $params): void
    {
        $fuel = $request->query('fuel', 'gasoleo_a');
        $days = $request->queryInt('days', 7);
        if ($days === null || $days <= 0) {
            $days = 7;
        }
        if ($days > 90) {
            $days = 90;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT fecha, precio FROM price_history
            WHERE ideess = ? AND carburante = ? AND fecha >= date(\'now\', ?)
            ORDER BY fecha ASC
        ');
        $stmt->execute([$params['ideess'], $fuel, "-$days days"]);
        $rows = $stmt->fetchAll();

        $series = array_map(fn($r) => ['fecha' => $r['fecha'], 'precio' => (float)$r['precio']], $rows);
        Response::json(['ideess' => $params['ideess'], 'carburante' => $fuel, 'serie' => $series]);
    }

    /**
     * Precio medio nacional de un carburante día a día (para una gráfica
     * global, no de una estación concreta), ruta /stats/national. Misma
     * fuente que la gráfica de una estación (`price_history`), solo que
     * agregada por fecha sobre todas las estaciones en vez de filtrada por
     * `ideess`. Incluye el titular de hoy (media + número de estaciones que
     * la componen) para no tener que hacer una segunda petición solo para
     * ese dato.
     */
    public function nationalStats(Request $request): void
    {
        $fuel = $this->normalizeFuel($request->query('fuel'));
        if ($fuel === null) {
            $fuel = 'gasoleo_a';
        }
        $days = $request->queryInt('days', 14);
        if ($days === null || $days <= 0) {
            $days = 14;
        }
        if ($days > 90) {
            $days = 90;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT fecha, ROUND(AVG(precio), 4) AS media, COUNT(*) AS estaciones
            FROM price_history
            WHERE carburante = ? AND fecha >= date(\'now\', ?)
            GROUP BY fecha
            ORDER BY fecha ASC
        ');
        $stmt->execute([$fuel, "-$days days"]);
        $rows = $stmt->fetchAll();

        $series = array_map(fn($r) => [
            'fecha' => $r['fecha'],
            'media' => (float)$r['media'],
            'estaciones' => (int)$r['estaciones'],
        ], $rows);

        $today = null;
        if (!empty($series)) {
            $today = end($series);
        }

        Response::json(['carburante' => $fuel, 'hoy' => $today, 'serie' => $series]);
    }

    public function zoneComparison(Request $request, array $params): void
    {
        $fuel = $request->query('fuel', 'gasoleo_a');
        $model = new Station(Database::connection());
        $comparison = $model->zoneComparison($params['ideess'], $fuel);
        if ($comparison === null) {
            Response::error('Sin datos suficientes para comparar', 404);
            return;
        }
        Response::json($comparison);
    }

    private function normalizeFuel(?string $fuel): ?string
    {
        if ($fuel === null || $fuel === '') {
            return null;
        }
        if (!in_array($fuel, self::VALID_FUELS, true)) {
            return null;
        }
        return $fuel;
    }

    private function normalizeSort(?string $sort): string
    {
        if ($sort === 'distance') {
            return 'distance';
        }
        return 'price';
    }

    /** @return array{0:bool,1:bool} [open24h, openNow] */
    private function parseOpenParam(?string $open): array
    {
        if ($open === '24h') {
            return [true, false];
        }
        if ($open === 'now') {
            return [false, true];
        }
        return [false, false];
    }
}
