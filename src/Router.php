<?php

/**
 * Router.php — middleware-enabled request router.
 *
 * Features:
 *   - Pre/post middleware pipeline
 *   - Separate route groups for API and views
 *   - Named parameters with type constraints
 *   - Immutable route registration (clone-on-add)
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
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
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

        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['pattern'], $matchPath, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            foreach ($route['middleware'] as $mwName) {
                if (!isset($this->middleware[$mwName])) {
                    continue;
                }
                $result = ($this->middleware[$mwName])($params);
                if ($result !== null) {
                    $this->sendResponse($result['status'], $result['body'], $result['headers'] ?? []);
                    return;
                }
            }

            foreach ($params as $k => $v) {
                $_GET[$k] = $v;
                $_REQUEST[$k] = $v;
            }

            $result = ($route['handler'])($params);

            if (is_array($result) && isset($result['status'])) {
                $this->sendResponse($result['status'], $result['body'] ?? '');
            }

            return;
        }

        $this->sendResponse(404, 'Page not found');
    }

    private function sendResponse(int $status, string $body, array $headers = []): void
    {
        http_response_code($status);
        foreach ($headers as $h) {
            header($h);
        }
        echo $body;
    }

    public static function resolve(): array
    {
        if (isset($_GET['action'])) {
            return $_GET;
        }

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

        $map = [
            '#^thread/([0-9]+)(?:-[^/]+)?$#'            => fn($m) => ['action' => 'thread', 'id' => $m[1]],
            '#^category/([0-9]+)(?:-[^/]+)?$#'          => fn($m) => ['action' => 'category', 'id' => $m[1]],
            '#^u/([^/]+)$#'                              => fn($m) => ['action' => 'profile', 'user' => urldecode($m[1])],
            '#^edit-post/([0-9]+)$#'                    => fn($m) => ['action' => 'edit_post', 'id' => (int)$m[1]],
            '#^delete-post/([0-9]+)$#'                  => fn($m) => ['action' => 'delete_post', 'id' => (int)$m[1]],
            '#^edit-thread/([0-9]+)$#'                  => fn($m) => ['action' => 'edit_thread', 'id' => (int)$m[1]],
            '#^delete-thread/([0-9]+)$#'                => fn($m) => ['action' => 'delete_thread', 'id' => (int)$m[1]],
            '#^download/([0-9]+)$#'                     => fn($m) => ['action' => 'download', 'id' => (int)$m[1]],
            '#^admin$#'                                  => fn()   => ['action' => 'admin'],
            '#^admin/moderation$#'                       => fn()   => ['action' => 'admin_moderation'],
            '#^admin/categories$#'                       => fn()   => ['action' => 'admin_categories'],
            '#^admin/users$#'                            => fn()   => ['action' => 'admin_users'],
            '#^admin/users/([0-9]+)/edit$#'             => fn($m) => ['action' => 'admin_user_edit', 'id' => (int)$m[1]],
            '#^admin/create-user$#'                      => fn()   => ['action' => 'admin_create_user'],
            '#^admin/settings$#'                         => fn()   => ['action' => 'admin_settings'],
            '#^admin/langs$#'                           => fn()   => ['action' => 'admin_langs'],
            '#^admin/catalog$#'                         => fn()   => ['action' => 'admin_catalog'],
            '#^admin/plugins$#'                         => fn()   => ['action' => 'admin_plugins'],
            '#^admin/themes$#'                          => fn()   => ['action' => 'admin_themes'],
            '#^admin/diagnostics$#'                     => fn()   => ['action' => 'admin_diagnostics'],
            '#^admin/updates$#'                         => fn()   => ['action' => 'admin_updates'],
            '#^admin/roles$#'                           => fn()   => ['action' => 'admin_roles'],
            '#^admin/roles-action$#'                    => fn()   => ['action' => 'admin_roles_action'],
            '#^admin/moderate$#'                        => fn()   => ['action' => 'moderate'],
            '#^admin/delete-category$#'                 => fn()   => ['action' => 'delete_category'],
            '#^admin/update-category-order$#'            => fn()   => ['action' => 'update_category_order'],
            '#^admin/delete-user$#'                     => fn()   => ['action' => 'delete_user'],
            '#^admin/ban-user$#'                        => fn()   => ['action' => 'ban_user'],
            '#^admin/unban-user$#'                      => fn()   => ['action' => 'unban_user'],
            '#^edit-profile$#'                          => fn()   => ['action' => 'edit_profile'],
            '#^remove-avatar$#'                         => fn()   => ['action' => 'remove_avatar'],
            '#^forgot-password$#'                       => fn()   => ['action' => 'forgot_password'],
            '#^reset-password$#'                        => fn()   => ['action' => 'reset_password'],
            '#^verify-email$#'                          => fn()   => ['action' => 'verify_email'],
            '#^new-thread$#'                            => fn()   => ['action' => 'new_thread'],
            '#^messages$#'                              => fn()   => ['action' => 'messages'],
            '#^notifications$#'                        => fn()   => ['action' => 'notifications'],
        ];

        foreach ($map as $pattern => $apply) {
            if (preg_match($pattern, $reqPath, $m)) {
                $_GET = array_merge($_GET, $apply($m));
                return $_GET;
            }
        }

        if ($reqPath === '' || $reqPath === 'index.php') {
            $_GET['action'] = 'home';
        } elseif (preg_match('#^[^/]+$#', $reqPath, $m)) {
            $_GET['action'] = $m[0];
        }

        return $_GET;
    }
}
