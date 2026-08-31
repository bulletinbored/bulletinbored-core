<?php

/**
 * Router.php — middleware-enabled request router.
 *
 * Features:
 *   - Pre/post middleware pipeline
 *   - Separate route groups for API and views
 *   - Named parameters with type constraints
 *   - Automatic JSON response for API routes
 *   - Content-Type negotiation (JSON vs HTML)
 *
 * Usage:
 *   $router = new Router();
 *   $router->middleware('auth')->group(function($router) {
 *       $router->get('/thread/{id:\d+}', 'thread_handler');
 *   });
 *   $router->api()->post('/api/threads', 'api_create_thread');
 *   $router->dispatch();
 */

namespace Bulletin;

class Router
{
    /** @var array<int,array{method:string, pattern:string, handler:callable, middleware:string[], group:string}> */
    private array $routes = [];

    /** @var array<string,callable> */
    private array $middleware = [];

    /** @var string[] */
    private array $currentMiddleware = [];

    /** @var callable|null */
    private $currentGroup = null;

    /** @var array{status:int, body:string}|null */
    private ?array $response = null;

    private string $paramsColumn = 'action';

    public function __construct()
    {
        $this->middleware['guest'] = function (): ?array {
            if (is_logged_in()) {
                return ['status' => 302, 'body' => '', 'headers' => ['Location: ' . \url('home')]];
            }
            return null;
        };

        $this->middleware['auth'] = function (): ?array {
            if (!is_logged_in()) {
                return ['status' => 302, 'body' => '', 'headers' => ['Location: ' . \url('login')]];
            }
            return null;
        };

        $this->middleware['admin'] = function (): ?array {
            if (!is_logged_in()) {
                return ['status' => 302, 'body' => '', 'headers' => ['Location: ' . \url('login')]];
            }
            $authz = $GLOBALS['authz'] ?? null;
            $userId = $_SESSION['user_id'] ?? 0;
            if ($authz === null || !$authz->can($userId, 'admin.access')) {
                return ['status' => 403, 'body' => 'Forbidden'];
            }
            return null;
        };

        $this->middleware['csrf'] = function (): ?array {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!csrf_validate_request()) {
                    http_response_code(403);
                    return ['status' => 403, 'body' => 'CSRF token invalid'];
                }
            }
            return null;
        };
    }

    public function middleware(string ...$names): self
    {
        $this->currentMiddleware = $names;
        return $this;
    }

    public function group(callable $callback): self
    {
        $savedMiddleware = $this->currentMiddleware;
        $savedGroup = $this->currentGroup;
        $savedParamsColumn = $this->paramsColumn;
        $callback($this);
        $this->currentMiddleware = $savedMiddleware;
        $this->currentGroup = $savedGroup;
        $this->paramsColumn = $savedParamsColumn;
        return $this;
    }

    public function api(): self
    {
        $this->currentGroup = fn() => null;
        $this->paramsColumn = 'api';
        return $this;
    }

    public function view(): self
    {
        $this->currentGroup = fn() => null;
        $this->paramsColumn = 'action';
        return $this;
    }

    public function get(string $pattern, callable $handler): self
    {
        return $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->addRoute('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): self
    {
        return $this->addRoute('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->addRoute('DELETE', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): self
    {
        return $this->addRoute('PATCH', $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): self
    {
        return $this->addRoute('*', $pattern, $handler);
    }

    private function addRoute(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $this->compilePattern($pattern),
            'handler' => $handler,
            'middleware' => $this->currentMiddleware,
            'group' => $this->paramsColumn,
        ];
        return $this;
    }

    private function compilePattern(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}#',
            function ($m) {
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        );
        return '#^' . $regex . '$#';
    }

    public function registerMiddleware(string $name, callable $fn): self
    {
        $this->middleware[$name] = $fn;
        return $this;
    }

    /**
     * Register the dynamic "can:permission" middleware handler.
     * Checks if the current user has the required permission.
     */
    public function registerCanMiddleware(\AuthZ $authz): self
    {
        $this->middleware['can'] = function (array $params) use ($authz): ?array {
            $permission = $params['_can_permission'] ?? '';
            $userId = $_SESSION['user_id'] ?? 0;
            $ownerId = $params['_can_owner_id'] ?? null;

            if (!is_logged_in()) {
                return ['status' => 302, 'body' => '', 'headers' => ['Location: ' . \url('login')]];
            }

            $allowed = $ownerId !== null
                ? $authz->canOnOwned($userId, $permission, (int)$ownerId)
                : $authz->can($userId, $permission);

            if (!$allowed) {
                return ['status' => 403, 'body' => 'Forbidden'];
            }

            return null;
        };
        return $this;
    }

    public function dispatch(): void
    {
        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $reqPath = ltrim($reqPath, '/');
        $base = trim(\base_url(), '/');
        if ($base !== '') {
            if ($reqPath === $base) {
                $reqPath = '';
            } elseif (str_starts_with($reqPath, $base . '/')) {
                $reqPath = substr($reqPath, strlen($base) + 1);
            }
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $reqPath = $reqPath === '' ? '/' : $reqPath;
        $matchPath = '/' . ltrim($reqPath, '/');
        $wantsJson = $this->wantsJson();

        try {
            $this->matchRoute($matchPath, $method, $wantsJson);
        } catch (HttpException $e) {
            $this->sendResponse(
                $e->getStatusCode(),
                $wantsJson ? json_encode(['error' => $e->getMessage()]) : $e->getMessage(),
                [],
                $wantsJson
            );
        }
    }

    private function matchRoute(string $matchPath, string $method, bool $wantsJson): void
    {
        $matches = [];
        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['pattern'], $matchPath, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            foreach ($route['middleware'] as $mwName) {
                $handler = null;
                if (isset($this->middleware[$mwName])) {
                    $handler = $this->middleware[$mwName];
                } elseif (str_starts_with($mwName, 'can:') && isset($this->middleware['can'])) {
                    $permission = substr($mwName, 4);
                    $params['_can_permission'] = $permission;
                    $handler = $this->middleware['can'];
                }
                if ($handler === null) {
                    continue;
                }
                $result = ($handler)($params);
                if ($result !== null) {
                    $this->sendResponse($result['status'], $result['body'], $result['headers'] ?? [], $wantsJson && !isset($result['headers']));
                    return;
                }
            }

            foreach ($params as $k => $v) {
                $_GET[$k] = $v;
                $_REQUEST[$k] = $v;
            }

            $result = ($route['handler'])($params);

            if ($result instanceof \Bulletin\Response) {
                $result->send();
            } elseif (is_array($result) && isset($result['status'])) {
                $body = $result['body'] ?? '';
                if ($wantsJson && !is_string($body)) {
                    $body = json_encode($body, JSON_UNESCAPED_UNICODE);
                }
                $this->sendResponse($result['status'], $body, $result['headers'] ?? [], $wantsJson && !isset($result['headers']));
            } elseif (is_array($result) || is_object($result)) {
                $this->sendResponse(200, json_encode($result, JSON_UNESCAPED_UNICODE), [], true);
            }

            return;
        }

        $this->sendResponse(404, $wantsJson ? json_encode(['error' => 'Not found']) : 'Page not found', [], $wantsJson);
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            return true;
        }
        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (str_starts_with(ltrim($reqPath, '/'), 'api/')) {
            return true;
        }
        return false;
    }

    private function sendResponse(int $status, string $body, array $headers = [], bool $isJson = false): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
            }
            foreach ($headers as $h) {
                header($h);
            }
        }
        echo $body;
    }
}
