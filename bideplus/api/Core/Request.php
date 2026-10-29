<?php

namespace Core;

class Request
{
    public string $sMethod;
    public string $sPath;
    public array $aQuery;
    private array|null $aJsonBody = null;

    public function __construct()
    {
        $this->sMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (isset($_GET['path'])) {
            $sPath = $_GET['path'];
            unset($_GET['path']);
        } else {
            // Sin parse_url: confunde «/lines/tolosaldea:4/…» con un host y un puerto.
            $sPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?#') ?: '/';
            $sPath = preg_replace('#^/api#', '', $sPath);
        }
        $this->sPath = '/' . ltrim($sPath, '/');
        $this->aQuery = $_GET;
    }

    public function query(string $sKey, string|null $sDefault = null): string|null
    {
        return $this->aQuery[$sKey] ?? $sDefault;
    }

    public function queryInt(string $sKey, int|null $iDefault = null): int|null
    {
        if (!isset($this->aQuery[$sKey]) || $this->aQuery[$sKey] === '') {
            return $iDefault;
        }
        return (int)$this->aQuery[$sKey];
    }

    public function json(): array
    {
        if ($this->aJsonBody === null) {
            $aDecoded = json_decode((string)file_get_contents('php://input'), true);
            $this->aJsonBody = is_array($aDecoded) ? $aDecoded : [];
        }
        return $this->aJsonBody;
    }

    public function header(string $sName): string|null
    {
        $sValue = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $sName))] ?? null;
        return $sValue === null ? null : (string)$sValue;
    }

    public function cookie(string $sName): string|null
    {
        return $_COOKIE[$sName] ?? null;
    }
}
