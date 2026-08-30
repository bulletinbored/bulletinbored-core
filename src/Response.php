<?php

/**
 * Response.php — HTTP response value object.
 *
 * Handlers return a Response instead of calling echo/die/exit.
 * The Router is the only one that calls send(), which sets the
 * HTTP status, headers, and body.
 *
 * Usage:
 *   return Response::html($html, 200);
 *   return Response::json(['ok' => true]);
 *   return Response::redirect('/login');
 *   return Response::error(403, 'Forbidden');
 */

namespace Bulletin;

class Response
{
    private int $status;
    private string $body;
    private array $headers;
    private bool $isJson;

    public function __construct(int $status, string $body, array $headers = [], bool $isJson = false)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
        $this->isJson = $isJson;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE), [], true);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location: ' . $url]);
    }

    public static function error(int $status, string $message = ''): self
    {
        return new self($status, $message);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            if ($this->isJson) {
                header('Content-Type: application/json; charset=utf-8');
            }
            foreach ($this->headers as $h) {
                header($h);
            }
        }
        echo $this->body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isJson(): bool
    {
        return $this->isJson;
    }
}
