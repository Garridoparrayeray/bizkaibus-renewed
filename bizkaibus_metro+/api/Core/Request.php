<?php

namespace Core;

class Request
{
    public string $method;
    public string $path;
    /** @var array<string,string> */
    public array $query;
    /** @var array<string,mixed>|null */
    private ?array $jsonBody = null;
    private bool $jsonBodyParsed = false;

    public function __construct()
    {
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $this->method = $_SERVER['REQUEST_METHOD'];
        } else {
            $this->method = 'GET';
        }

        // Este método es robusto para diferentes entornos (Apache, Vercel, local).
        // Primero, busca un parámetro 'path' que el servidor web nos pasa (vía .htaccess).
        if (isset($_GET['path'])) {
            $path = $_GET['path'];
            // El parámetro 'path' es solo para el enrutamiento, lo eliminamos para
            // que no interfiera con los parámetros reales de la consulta (ej. ?q=...).
            unset($_GET['path']);
        } else {
            // Si no hay parámetro 'path', usamos el método para el servidor de desarrollo local.
            if (isset($_SERVER['REQUEST_URI'])) {
                $uri = $_SERVER['REQUEST_URI'];
            } else {
                $uri = '/';
            }
            $path = parse_url($uri, PHP_URL_PATH);
            if (!$path) {
                $path = '/';
            }
            $path = preg_replace('#^/api#', '', $path);
        }
        $this->path = '/' . ltrim($path, '/');

        $this->query = $_GET;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        if (isset($this->query[$key])) {
            return $this->query[$key];
        }
        return $default;
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        if (!isset($this->query[$key]) || $this->query[$key] === '') {
            return $default;
        }
        return (int)$this->query[$key];
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if (!$this->jsonBodyParsed) {
            $raw = file_get_contents('php://input');
            $decoded = null;
            if ($raw) {
                $decoded = json_decode($raw, true);
            }
            if (is_array($decoded)) {
                $this->jsonBody = $decoded;
            } else {
                $this->jsonBody = [];
            }
            $this->jsonBodyParsed = true;
        }
        return $this->jsonBody;
    }

    public function cookie(string $name): ?string
    {
        if (isset($_COOKIE[$name])) {
            return $_COOKIE[$name];
        }
        return null;
    }
}
