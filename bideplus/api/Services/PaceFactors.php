<?php

namespace Services;

class PaceFactors
{
    public const MIN_FACTOR = 0.6;
    public const MAX_FACTOR = 1.2;

    private const FILE_PATH = __DIR__ . '/../../data/pace-factors.json';

    private const BANDS = [
        [0, 6, 'madrugada'],
        [6, 9, 'punta_manana'],
        [9, 13, 'manana'],
        [13, 16, 'mediodia'],
        [16, 20, 'punta_tarde'],
        [20, 24, 'noche'],
    ];

    private array $aGlobal;
    private array $aLines;

    public function __construct(array $aData = [])
    {
        $this->aGlobal = $aData['global'] ?? [];
        $this->aLines = $aData['lines'] ?? [];
    }

    public static function fromFile(string|null $sPath = null): self
    {
        if ($sPath === null) {
            $sPath = self::FILE_PATH;
        }
        if (!is_file($sPath)) {
            return new self();
        }
        $aData = json_decode((string)file_get_contents($sPath), true);
        if (!\is_array($aData)) {
            return new self();
        }
        return new self($aData);
    }

    public static function bandFor(string $sDate, int $iSeconds): string
    {
        $iWeekday = (int)(new \DateTime($sDate))->format('N');
        $sDayType = 'laborable';
        if ($iWeekday >= 6) {
            $sDayType = 'fin_semana';
        }
        $iHour = intdiv($iSeconds, 3600) % 24;
        foreach (self::BANDS as [$iFrom, $iTo, $sName]) {
            if ($iHour >= $iFrom && $iHour < $iTo) {
                return $sDayType . '.' . $sName;
            }
        }
        return $sDayType . '.noche';
    }

    public function factor(string|int|null $sLineId, string $sDate, int $iSeconds): float
    {
        $sBand = self::bandFor($sDate, $iSeconds);
        $dFactor = 1.0;
        if ($sLineId !== null && isset($this->aLines[(string)$sLineId][$sBand])) {
            $dFactor = (float)$this->aLines[(string)$sLineId][$sBand];
        } elseif (isset($this->aGlobal[$sBand])) {
            $dFactor = (float)$this->aGlobal[$sBand];
        }
        return max(self::MIN_FACTOR, min(self::MAX_FACTOR, $dFactor));
    }

    public static function defaultPath(): string
    {
        return self::FILE_PATH;
    }
}
