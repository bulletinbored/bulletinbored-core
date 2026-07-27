<?php
// Simple Router for MVC

class Router {
    private $routes = [];
    private $params = [];

    public function addRoute($method, $pattern, $callback) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'callback' => $callback
        ];
    }

    public function dispatch() {
        $url = $_SERVER['REQUEST_URI'] ?? '/';
        $url = parse_url($url, PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        foreach ($this->routes as $route) {
            $pattern = $this->convertPattern($route['pattern']);
            if ($route['method'] === $method && preg_match($pattern, $url, $matches)) {
                $params = array_slice($matches, 1);
                if (is_array($route['callback'])) {
                    call_user_func($route['callback'], ...$params);
                } else {
                    call_user_func($route['callback']);
                }
                return;
            }
        }

        // 404 Not Found
        http_response_code(404);
        $view = new View('errors/404');
        $view->render();
    }

    private function convertPattern($pattern) {
        $pattern = preg_replace('/\//', '\/', $pattern);
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^\/]+)', $pattern);
        return '/^' . $pattern . '$/';
    }
}