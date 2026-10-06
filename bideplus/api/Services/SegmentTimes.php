<?php

namespace Services;

class SegmentTimes
{
    private const FILE_PATH = __DIR__ . '/../../data/segment-times.json';

    private array $aSegments;

    public function __construct(array $aData = [])
    {
        $this->aSegments = $aData['segments'] ?? [];
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
