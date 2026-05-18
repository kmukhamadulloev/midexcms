<?php

declare(strict_types=1);

namespace MidexCMS\Core;

use Closure;
use RuntimeException;

final class Router
{
    /**
     * @var array<int, array{methods: array<int, string>, path: string, handler: mixed}>
     */
    private array $routes = [];

    public function get(string $path, mixed $handler): void
    {
        $this->match(['GET'], $path, $handler);
    }

    public function post(string $path, mixed $handler): void
    {
        $this->match(['POST'], $path, $handler);
    }

    public function match(array $methods, string $path, mixed $handler): void
    {
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if (!in_array($request->method(), $route['methods'], true)) {
                continue;
            }

            $parameters = $this->matchPath($route['path'], $request->path());

            if ($parameters === null) {
                continue;
            }

            $result = $this->invoke($route['handler'], $request, $parameters);

            if (!$result instanceof Response) {
                throw new RuntimeException('Route handlers must return a Response instance.');
            }

            return $result;
        }

        return Response::html('<h1>404 Not Found</h1>', 404);
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPath(string $routePath, string $requestPath): ?array
    {
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/', static function (array $matches): string {
            $name = $matches[1];
            $type = $matches[2] ?? null;

            if ($type === 'any') {
                return '(?P<' . $name . '>.*)';
            }

            return '(?P<' . $name . '>[^/]+)';
        }, $routePath);

        if ($pattern === null) {
            return null;
        }

        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $requestPath, $matches)) {
            return null;
        }

        $parameters = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function invoke(mixed $handler, Request $request, array $parameters): Response
    {
        if ($handler instanceof Closure) {
            return $handler($request, $parameters);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : new $class();

            return $instance->{$method}($request, $parameters);
        }

        throw new RuntimeException('Unsupported route handler provided.');
    }
}
