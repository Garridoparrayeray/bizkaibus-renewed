<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * Legacy GetLineasHorarios endpoint: the only *current* (2026) schedule
 * source, but free text ("Sabados y festivos: de 10:35 a 12:35 cada hora"),
 * not per-stop times. Surfaced as a supplementary read-only reference next
 * to the NeTEx-derived structured schedule, never parsed into passing times.
 *
 * Its own line codes are zero-padded (e.g. "A0651") unlike ours ("A651"), so
 * matching strips non-digits and compares the bare line number.
 */
class ScheduleTextClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array<int, array<string,string>> raw field=>text blocks for the given line id, empty if unavailable */
    public function fetchForLine(int $lineId): array
    {
        $all = $this->fetchAll();
        return $all[$lineId] ?? [];
    }

    /** @return array<int, array<int, array<string,string>>> keyed by bare numeric line id */
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
