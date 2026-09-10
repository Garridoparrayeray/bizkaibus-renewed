<?php

namespace Core;

class Request
{
    public string $sMethod;
    public string $sPath;

    public array $aQuery;

    private array|null $aJsonBody = null;
    private bool $bJsonBodyParsed = false;

    public function __construct()
    {
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $this->sMethod = $_SERVER['REQUEST_METHOD'];
        } else {
            $this->sMethod = 'GET';
        }

        if (isset($_GET['path'])) {
            $sPath = $_GET['path'];

            unset($_GET['path']);
        } else {

            if (isset($_SERVER['REQUEST_URI'])) {
                $sUri = $_SERVER['REQUEST_URI'];
            } else {
                $sUri = '/';
            }
            $sPath = parse_url($sUri, PHP_URL_PATH);
            if (!$sPath) {
                $sPath = '/';
            }
            $sPath = preg_replace('#^/api#', '', $sPath);
        }
        $this->sPath = '/' . ltrim($sPath, '/');

        $this->aQuery = $_GET;
    }

    public function query(string $sKey, string|null $sDefault = null): string|null
    {
        if (isset($this->aQuery[$sKey])) {
            return $this->aQuery[$sKey];
        }
        return $sDefault;
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
        if (!$this->bJsonBodyParsed) {
            $sRaw = file_get_contents('php://input');
            $aDecoded = null;
            if ($sRaw) {
                $aDecoded = json_decode($sRaw, true);
            }
            if (is_array($aDecoded)) {
                $this->aJsonBody = $aDecoded;
            } else {
                $this->aJsonBody = [];
            }
            $this->bJsonBodyParsed = true;
        }
        return $this->aJsonBody;
    }

    public function cookie(string $sName): string|null
    {
        if (isset($_COOKIE[$sName])) {
            return $_COOKIE[$sName];
        }
        return null;
    }
}
