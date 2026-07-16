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
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Requests arrive as /api/... (Vercel rewrite) or /... (local php -S router).
        $path = preg_replace('#^/api#', '', $path);
        $this->path = '/' . ltrim($path, '/');

        $this->query = $_GET;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        $value = $this->query[$key] ?? null;
        return $value === null || $value === '' ? $default : (int)$value;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if (!$this->jsonBodyParsed) {
            $raw = file_get_contents('php://input');
            $decoded = $raw ? json_decode($raw, true) : null;
            $this->jsonBody = is_array($decoded) ? $decoded : [];
            $this->jsonBodyParsed = true;
        }
        return $this->jsonBody;
    }

    public function cookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }
}
