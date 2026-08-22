<?php

namespace Core;

class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:string[], handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $paramNames,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $matchedPath = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $matchedPath = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            array_shift($matches);
            $params = array_combine($route['params'], $matches);
            try {
                ($route['handler'])($request, $params);
            } catch (\Throwable $e) {
                Response::error('Internal error: ' . $e->getMessage(), 500);
            }
            return;
        }

        if ($matchedPath) {
            Response::error('Method not allowed', 405);
        } else {
            Response::error('Not found', 404);
        }
    }
}
