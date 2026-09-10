<?php

namespace Core;

class Request
{
    public string $method;
    public string $path;

    public array $query;

    private array|null $jsonBody = null;
    private bool $jsonBodyParsed = false;

    public function __construct()
    {
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $this->method = $_SERVER['REQUEST_METHOD'];
        } else {
            $this->method = 'GET';
        }

        if (isset($_GET['path'])) {
            $path = $_GET['path'];

            unset($_GET['path']);
        } else {

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

    public function query(string $key, string|null $default = null): string|null
    {
        if (isset($this->query[$key])) {
            return $this->query[$key];
        }
        return $default;
    }

    public function queryInt(string $key, int|null $default = null): int|null
    {
        if (!isset($this->query[$key]) || $this->query[$key] === '') {
            return $default;
        }
        return (int)$this->query[$key];
    }

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

    public function cookie(string $name): string|null
    {
        if (isset($_COOKIE[$name])) {
            return $_COOKIE[$name];
        }
        return null;
    }
}
