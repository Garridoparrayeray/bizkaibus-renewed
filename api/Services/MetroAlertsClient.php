<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * Avisos/incidencias de Metro Bilbao (endpoint JSON propio del CMS, no
 * SIRI-SX como Bizkaibus, que no publica ese formato). El station_id que
 * trae cada aviso pertenece al sistema interno del CMS, no al stop_id del
 * GTFS público, y no hay forma fiable de cruzarlos, así que los avisos se
 * muestran como lista global de la red, no filtrados por estación. El propio
 * título del aviso ya suele nombrar la estación en texto libre (p.ej.
 * "Ascensor exterior de Areeta fuera de servicio").
 */
class MetroAlertsClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Avisos activos ahora mismo, cacheados según metro_alerts.cache_ttl_seconds.
     * Si el CMS de Metro Bilbao falla o no responde, devuelve lista vacía en
     * vez de propagar el error: un aviso caído no debe tumbar el resto de la app.
     *
     * @return array<int, array{summary:string, description:string, startTime:?string, endTime:?string}>
     */
    public function fetchAlerts(): array
    {
        $cfg = $this->config['metro_alerts'];
        return Cache::remember('metro_alerts', $cfg['cache_ttl_seconds'], function () use ($cfg) {
            try {
                $body = Http::get($cfg['url'], $cfg['http_timeout_seconds']);
            } catch (\Throwable $e) {
                return [];
            }
            return self::parse($body);
        });
    }

    /** Filtra el JSON crudo del CMS a los avisos publicados y aún no finalizados. */
    private static function parse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            return [];
        }

        $alerts = [];
        foreach ($decoded['data'] as $row) {
            $isPublished = false;
            if (isset($row['is_published'])) {
                $isPublished = (string)$row['is_published'] === '1';
            }
            if (!$isPublished) {
                continue;
            }
            if (!empty($row['finished_at'])) {
                continue;
            }

            $summary = '';
            if (isset($row['title_es'])) {
                $summary = (string)$row['title_es'];
            }

            $startTime = null;
            if (!empty($row['publish_start_date'])) {
                $startTime = (string)$row['publish_start_date'];
            }
            $endTime = null;
            if (!empty($row['publish_end_date'])) {
                $endTime = (string)$row['publish_end_date'];
            }

            $alerts[] = [
                'summary' => $summary,
                'description' => '',
                'startTime' => $startTime,
                'endTime' => $endTime,
            ];
        }
        return $alerts;
    }
}
