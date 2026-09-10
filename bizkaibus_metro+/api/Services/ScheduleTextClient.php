<?php

namespace Services;

use Core\Cache;
use Core\Http;

class ScheduleTextClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function fetchForLine(int $lineId): array
    {
        $all = $this->fetchAll();
        if (isset($all[$lineId])) {
            return $all[$lineId];
        }
        return [];
    }

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
            $codeField = null;
            if (isset($linea->{'KODEA-CODIGO'})) {
                $codeField = $linea->{'KODEA-CODIGO'};
            }
            if ($codeField === null || !preg_match('/(\d+)/', (string)$codeField, $m)) {
                continue;
            }
            $lineId = (int)ltrim($m[1], '0');

            $horarios = [];
            if (isset($linea->{'ORDUTEGIA-HORARIO'})) {
                $horarios = $linea->{'ORDUTEGIA-HORARIO'};
            }

            $blocks = [];
            foreach ($horarios as $horario) {
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
