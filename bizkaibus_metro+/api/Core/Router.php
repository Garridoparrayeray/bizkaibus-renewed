<?php

namespace Core;

class Router
{

    private array $aRoutes = [];

    public function get(string $sPattern, callable $Handler): void
    {
        $this->add('GET', $sPattern, $Handler);
    }

    public function post(string $sPattern, callable $Handler): void
    {
        $this->add('POST', $sPattern, $Handler);
    }

    public function delete(string $sPattern, callable $Handler): void
    {
        $this->add('DELETE', $sPattern, $Handler);
    }

    private function add(string $sMethod, string $sPattern, callable $Handler): void
    {
        $aParamNames = [];
        $sRegex = preg_replace_callback('#\{(\w+)\}#', function ($aM) use (&$aParamNames) {
            $aParamNames[] = $aM[1];
            return '([^/]+)';
        }, $sPattern);

        $this->aRoutes[] = [
            'method' => $sMethod,
            'pattern' => $sPattern,
            'regex' => '#^' . $sRegex . '$#',
            'params' => $aParamNames,
            'handler' => $Handler,
        ];
    }

    public function dispatch(Request $Req): void
    {
        $bMatchedPath = false;
        foreach ($this->aRoutes as $aRoute) {
            if (!preg_match($aRoute['regex'], $Req->sPath, $aMatches)) {
                continue;
            }
            $bMatchedPath = true;
            if ($aRoute['method'] !== $Req->sMethod) {
                continue;
            }
            array_shift($aMatches);
            $aParams = array_combine($aRoute['params'], $aMatches);
            try {
                ($aRoute['handler'])($Req, $aParams);
            } catch (\Throwable $Ex) {
                Response::error('Internal error: ' . $Ex->getMessage(), 500);
            }
            return;
        }

        if ($bMatchedPath) {
            Response::error('Method not allowed', 405);
        } else {
            Response::error('Not found', 404);
        }
    }
}
