<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * Endpoint legado GetLineasHorarios: horario en texto libre ("Sabados y
 * festivos: de 10:35 a 12:35 cada hora"), no por parada. Se muestra como
 * referencia complementaria de solo lectura junto al horario estructurado
 * (derivado del GTFS), nunca se parsea a passing_times.
 *
 * Sus códigos de línea van con ceros a la izquierda (p.ej. "A0651") a
 * diferencia de los nuestros ("A651"), así que el match quita los no-dígitos
 * y compara el número de línea pelado.
 */
class ScheduleTextClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array<int, array<string,string>> bloques campo=>texto para la línea dada, vacío si no hay */
    public function fetchForLine(int $lineId): array
    {
        $all = $this->fetchAll();
        return $all[$lineId] ?? [];
    }

    /** @return array<int, array<int, array<string,string>>> indexado por id numérico de línea pelado */
    private function fetchAll(): array
    {
        $cfg = $this->config['schedule_text'];
        return Cache::remember('schedule_text_all', $cfg['cache_ttl_seconds'], function () use ($cfg) {
            try {
                $xmlString = Http::get($cfg['url'], $cfg['http_timeout_seconds']);
            } catch (\Throwable $e) {
                return [];
            }
            return self::parse($xmlString);
        });
    }

    private static function parse(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false || !isset($xml->{'LINEA-LINEA'})) {
            return [];
        }

        $byLine = [];
        foreach ($xml->{'LINEA-LINEA'} as $linea) {
            $codeField = $linea->{'KODEA-CODIGO'} ?? null;
            if ($codeField === null || !preg_match('/(\d+)/', (string)$codeField, $m)) {
                continue;
            }
            $lineId = (int)ltrim($m[1], '0');

            $blocks = [];
            foreach ($linea->{'ORDUTEGIA-HORARIO'} ?? [] as $horario) {
                $block = [];
                foreach ($horario->children() as $child) {
                    $block[$child->getName()] = trim((string)$child);
                }
                if (!empty($block)) {
                    $blocks[] = $block;
                }
            }
            $byLine[$lineId] = $blocks;
        }
        return $byLine;
    }
}
