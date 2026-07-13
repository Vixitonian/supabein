<?php

declare(strict_types=1);

namespace SupaBein;

class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    public function patch(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }

    private function addRoute(string $method, string $pattern, callable $handler, array $middleware): void
    {
        $this->routes[] = compact('method', 'pattern', 'handler', 'middleware');
    }

    public function dispatch(array $request): void
    {
        $method = $request['method'];
        $uri    = '/' . trim($request['uri'], '/');

        $methodMatched = false;

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $uri);
            if ($params === null) {
                continue;
            }

            if ($route['method'] !== $method) {
                $methodMatched = true;
                continue;
            }

            $request['params'] = $params;
            $request['route_pattern'] = $route['pattern'];

            // Build middleware pipeline
            $handler = $route['handler'];
            $pipeline = function (array $req) use ($handler): void {
                $handler($req);
            };

            foreach (array_reverse($route['middleware']) as $mw) {
                $next     = $pipeline;
                $pipeline = function (array $req) use ($mw, $next): void {
                    $mw($req, $next);
                };
            }

            $pipeline($request);
            return;
        }

        if ($methodMatched) {
            abort(405);
        }
        abort(404);
    }

    private function match(string $pattern, string $uri): ?array
    {
        $regex  = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', fn($m) => '(?P<' . $m[1] . '>[^/]+)', $pattern);
        $regex  = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $m)) {
            return null;
        }

        $params = [];
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }
        return $params;
    }
}
