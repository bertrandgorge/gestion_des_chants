<?php

declare(strict_types=1);

namespace App;

/**
 * Routeur minimal : patterns avec paramètres {nom}.
 *
 * Exemple : $r->get('/app/feuilles/{id}', [FeuilleController::class, 'edit']);
 * Le handler reçoit un tableau associatif des paramètres capturés.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,params:array<int,string>,handler:callable|array}> */
    private array $routes = [];

    /** @var callable|null */
    private $fallback = null;

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** Route générique GET+POST. */
    public function any(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{([a-z_]+)\}#i', static function ($m) use (&$params) {
            $params[] = $m[1];

            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            array_shift($matches);
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = urldecode($matches[$i]);
            }

            $this->invoke($route['handler'], $args);

            return;
        }

        if ($this->fallback !== null) {
            ($this->fallback)($method, $path);

            return;
        }

        http_response_code(404);
        echo view('errors/404');
    }

    private function invoke(callable|array $handler, array $args): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : new $class();
            $instance->$method($args);

            return;
        }

        $handler($args);
    }
}
