<?php
// router.php
class Router {
    private $routes = [];

    public function get($pattern, $handler) {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post($pattern, $handler) {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function delete($pattern, $handler) {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    private function addRoute($method, $pattern, $handler) {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    public function dispatch($uri, $method) {
        // Remove query string
        $uri = strtok($uri, '?');
        // Remove trailing slash, but preserve root
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            // Convert pattern to regex
            $pattern = preg_replace('/:([^\/]+)/', '([^/]+)', $route['pattern']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches); // Remove full match
                return call_user_func_array($route['handler'], $matches);
            }
        }

        return null; // No match found
    }
}
