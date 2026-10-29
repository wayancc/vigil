<?php

declare(strict_types=1);

namespace Vigil\Http;

/**
 * Tiny router: add('GET', '/monitors/{id}', $handler). Params are passed to the handler by name.
 */
final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable}> */
    private array $routes = [];

    public function add(string $methods, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        foreach (explode('|', $methods) as $method) {
            $this->routes[] = [$method, $regex, $handler];
        }
    }

    public function dispatch(Request $request): ?Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = false;

        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $allowed = true;
            if ($routeMethod !== $method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return $handler($request, ...$params);
        }

        return $allowed ? Response::text('Method not allowed', 405) : null;
    }
}
