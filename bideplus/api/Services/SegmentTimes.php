<?php

namespace Services;

class SegmentTimes
{
    private const FILE_PATH = __DIR__ . '/../../data/segment-times.json';

    /*
     * Largo de un tramo (m) para repartir la proporcion tiempo real / horario de los tramos sin tiempo aprendido: en ciudad
     * un bus tarda lo que dice el horario o mas, y en autopista la mitad, porque el horario va interpolado a velocidad
     * constante entre paradas.
     */
    public const LENGTH_CUTS_METERS = [300, 600, 1000, 1500, 3000];

    private array $aSegments;
    private array $aLengthRatios;

    public function __construct(array $aData = [])
    {
        $this->aSegments = $aData['segments'] ?? [];
        $this->aLengthRatios = $aData['lengthRatios'] ?? [];
    }

    public static function lengthBucket(float $dMeters): int
    {
        foreach (self::LENGTH_CUTS_METERS as $iIndex => $iCut) {
            if ($dMeters < $iCut) {
                return $iIndex;
            }
        }
        return \count(self::LENGTH_CUTS_METERS);
    }

    /*
     * Tiempo estimado de un tramo sin tiempo aprendido a partir de su horario y su largo, o null si no hay proporciones
     * calibradas o no se conoce el largo (entonces se usa el factor k de ritmo).
     */
    public function fallbackSeconds(float $dScheduledSeconds, float|null $dMeters, string $sBand): float|null
    {
        if (empty($this->aLengthRatios) || $dMeters === null || $dMeters <= 0) {
            return null;
        }
        $aBucket = $this->aLengthRatios[(string)self::lengthBucket($dMeters)] ?? null;
        if ($aBucket === null) {
            return null;
        }
        $dRatio = $aBucket[$sBand] ?? $aBucket['all'] ?? null;
        if ($dRatio === null) {
            return null;
        }
        return max(0.3, min(1.5, (float)$dRatio)) * $dScheduledSeconds;
    }

    public function lengthRatios(): array
    {
        return $this->aLengthRatios;
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

    public static function key(string $sFromStopId, string $sToStopId): string
    {
        return $sFromStopId . '>' . $sToStopId;
    }

    public function seconds(string $sFromStopId, string $sToStopId, string $sBand): float|null
    {
        $aSegment = $this->aSegments[self::key($sFromStopId, $sToStopId)] ?? null;
        if ($aSegment === null) {
            return null;
        }
        if (isset($aSegment[$sBand])) {
            return (float)$aSegment[$sBand];
        }
        if (isset($aSegment['all'])) {
            return (float)$aSegment['all'];
        }
        return null;
    }

    public function isEmpty(): bool
    {
        return empty($this->aSegments);
    }

    public static function defaultPath(): string
    {
        return self::FILE_PATH;
    }
}
