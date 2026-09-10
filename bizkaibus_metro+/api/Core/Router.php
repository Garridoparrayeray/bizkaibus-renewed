<?php

namespace Core;

class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:string[], handler:callable}> */
    private array $routes = [];

    /** Registra una ruta GET, ej: $router->get('/stops/{id}', [...]). */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** Registra una ruta POST. */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** Registra una ruta DELETE. */
    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Compila un patrón tipo '/stops/{id}/departures' a una regex con grupos
     * de captura, guardando el nombre de cada {parámetro} en orden para
     * poder reconstruir un array asociativo tras el match en dispatch().
     */
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

    /**
     * Encuentra la ruta cuyo patrón case con request->path y method, y
     * delega en su handler, capturando cualquier excepción del handler como
     * 500 en vez de dejar pasar un fatal sin respuesta JSON. Distingue "no
     * existe ninguna ruta con este path" (404) de "existe pero con otro
     * method" (405), comprobando el regex por separado del method.
     */
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
