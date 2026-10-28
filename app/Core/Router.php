<?php
declare(strict_types=1);

namespace App\Core;

/** Routeur minimal : méthode + motif avec paramètres {nom}. */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $path): mixed
    {
        $path = '/' . trim($path, '/');
        $allowed = false;
        foreach ($this->routes as [$m, $regex, $handler]) {
            if (preg_match($regex, $path, $match)) {
                if ($m !== $method && !($m === 'GET' && $method === 'HEAD')) {
                    $allowed = true;
                    continue;
                }
                $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
                if (is_array($handler)) {
                    [$class, $fn] = $handler;
                    return (new $class())->$fn(...$params);
                }
                return $handler(...$params);
            }
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
