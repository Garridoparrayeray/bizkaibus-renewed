<?php

namespace Services;

use Core\Cache;
use Core\Http;

class ScheduleTextClient
{
    private array $aConfig;

    public function __construct(array $aConfig)
    {
        $this->aConfig = $aConfig;
    }

    public function fetchForLine(int $iLineId): array
    {
        $aAll = $this->fetchAll();
        if (isset($aAll[$iLineId])) {
            return $aAll[$iLineId];
        }
        return [];
    }

    private function fetchAll(): array
    {
        $aCfg = $this->aConfig['schedule_text'];
        return Cache::remember('schedule_text_all', $aCfg['cache_ttl_seconds'], function () use ($aCfg) {
            try {
                $sXmlString = Http::get($aCfg['url'], $aCfg['http_timeout_seconds']);
            } catch (\Throwable $Ex) {
                return [];
            }
            return self::parse($sXmlString);
        });
    }

    private static function parse(string $sXmlString): array
    {
        $Xml = @simplexml_load_string($sXmlString);
        if ($Xml === false || !isset($Xml->{'LINEA-LINEA'})) {
            return [];
        }

        $aByLine = [];
        foreach ($Xml->{'LINEA-LINEA'} as $Linea) {
            $CodeField = null;
            if (isset($Linea->{'KODEA-CODIGO'})) {
                $CodeField = $Linea->{'KODEA-CODIGO'};
            }
            if ($CodeField === null || !preg_match('/(\d+)/', (string)$CodeField, $aM)) {
                continue;
            }
            $iLineId = (int)ltrim($aM[1], '0');

            $aHorarios = [];
            if (isset($Linea->{'ORDUTEGIA-HORARIO'})) {
                $aHorarios = $Linea->{'ORDUTEGIA-HORARIO'};
            }

            $aBlocks = [];
            foreach ($aHorarios as $Horario) {
                $aBlock = [];
                foreach ($Horario->children() as $Child) {
                    $aBlock[$Child->getName()] = trim((string)$Child);
                }
                if (!empty($aBlock)) {
                    $aBlocks[] = $aBlock;
                }
            }
            $aByLine[$iLineId] = $aBlocks;
        }
        return $aByLine;
    }
}
