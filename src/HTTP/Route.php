<?php

namespace ST_system\HTTP;

use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\Access;
use ST_system\Config;

final class Route {
    private static $API_POINT = '';
    private static $routes = [];
    private static $stack = [];

    private $prefix = '';
    private $middlewares = [];
    private $request;

    private function __construct(string $prefix = '', array $middlewares = []) {
        if ($prefix != '')
            $this->prefix = $prefix;
        elseif (self::$API_POINT != '')
            $this->prefix = self::$API_POINT;
        else
            throw new \RuntimeException("Configuration error: API_POINT is not set");

        $this->middlewares = $middlewares;
    }

    public static function entrypoint(?callable $process = null): void {
        ob_start();

        try {
            if ($process) $process();
        } finally {
            while (ob_get_level() > 1) ob_end_clean();
        }

        $query_params = [];
        $route = null;

        foreach (self::routes() as $r) {
            $query_params = [];

            $pattern = $r->pattern;

            if ($r->strict_mode)
                $pattern = preg_quote($pattern, '#');

            $regexp = str_replace('//', '/', "#^".preg_replace_callback($r->strict_mode ? '#\\\\\{(\w+)\\\\\}#' : '#\\{(\w+)\\}#', function ($matches) use (&$query_params) {
                $query_params[] = $matches[1];
                return "(?P<{$matches[1]}>[^/]+)";
            }, $pattern).'/?$#');

            if (preg_match($regexp, Request::uri(), $matches)) {
                $route = $r;
                $query_params = array_intersect_key($matches, array_flip($query_params));
                break;
            }
        }

        try {
            if (!$route)
                Access::throw(404);

            try {
                $request_class = $route->request;

                $ref = is_array($route->controller)
                    ? new \ReflectionMethod($route->controller[0], $route->controller[1])
                    : new \ReflectionFunction($route->controller);

                $params = $ref->getParameters();

                if (empty($params))
                    throw new \RuntimeException("no params");
                    
                $type = $params[0]->getType();

                if (!$type)
                    throw new \RuntimeException('no type info');
                
                if (
                    (class_exists('ReflectionUnionType') && $type instanceof \ReflectionUnionType) ||
                    (class_exists('ReflectionIntersectionType') && $type instanceof \ReflectionIntersectionType)
                ) throw new \RuntimeException('union/intersection not allowed');
                        
                if (class_exists('ReflectionNamedType') && (!($type instanceof \ReflectionNamedType) || $type->isBuiltin()))
                    throw new \RuntimeException('not a named class type');
                
                $candidate = $type->getName();

                if (!is_subclass_of($candidate, Request::class))
                    throw new \RuntimeException('type is not request descendant');
                
                $request_class = $candidate;
            } catch (\Throwable $e) {
                $request_class = $request_class ?: Request::class;
            }

            $request = $request_class::fetch($query_params);
        
            foreach ($route->middlewares as $entry) {
                if (is_array($entry)) {
                    $mw   = array_shift($entry);
                    $args = $entry;
                } else {
                    $mw   = $entry;
                    $args = [];
                }

                $target = is_string($mw) ? [$mw, 'handle'] : $mw;

                call_user_func_array($target, array_merge([$request], $args));
            }
            
        } catch (\Throwable $th) {
            ob_clean();
            Response::json(['message' => Config::env('DEBUG_MODE')
                ? sprintf(
                    "Ошибка: %s\nФайл: %s\nСтрока: %d\n%s",
                    $th->getMessage(),
                    $th->getFile(),
                    $th->getLine(),
                    $th->getTraceAsString()
                )
                : $th->getMessage()
            ])->status(!$th->getCode() ? 403 : $th->getCode())->send();
        }

        try {
            $response = call_user_func_array(
                is_array($route->controller)
                    ? [new $route->controller[0], $route->controller[1]]
                    : $route->controller, 
                [$request]
            );

            if (!$response instanceof Response)
                $response = Response::json($response);

        } catch (\Throwable $th) {
            $response = Response::json(['message' => Config::env('DEBUG_MODE')
                ? sprintf(
                    "Ошибка: %s\nФайл: %s\nСтрока: %d\n%s",
                    $th->getMessage(),
                    $th->getFile(),
                    $th->getLine(),
                    $th->getTraceAsString()
                )
                : $th->getMessage()
            ])->status(!$th->getCode() ? 500 : $th->getCode());
        } finally {
            ob_clean();
            $response->send();
        }
    }

    public static function point(string $point): self {
        $point = '/'.trim($point, '/').'/';

        self::$API_POINT = $point;

        $new = new self();

        self::$stack = [$new];

        return $new;
    }

    private static function current(): self {
        return end(self::$stack) ?: (self::$stack[] = new self());
    }

    public static function prefix(string $uri): self {
        $parent = self::current();
        $new = new self('/'.trim($parent->prefix, '/').'/'.trim($uri, '/').'/', $parent->middlewares);

        self::$stack[] = $new;

        return $new;
    }

    public function group(callable $c): void {
        call_user_func($c);

        array_pop(self::$stack);
    }

    public static function middleware($mids): self {
        $parent = self::current();
        $parent->middlewares = array_merge($parent->middlewares, is_array($mids) ? $mids : [$mids]);

        return $parent;
    }

    public static function request(string $classname): self {
        $parent = self::current();
        $parent->request = $classname;

        return $parent;
    }

    private static function add_route(array $methods, string $uri, $controller, array $PARAMS = []): void {
        $base = self::current();
        $strict_mode = isset($PARAMS['strict_mode']) ? (bool)$PARAMS['strict_mode'] : true;

        $full = $strict_mode
            ? str_replace('//', '/', '/'.trim($base->prefix, '/').'/'.trim($uri, '/').'/')
            : str_replace('//', '/', trim($base->prefix, '/').'/'.trim($uri, '/'));
            
        foreach (self::$routes as $uri => $r)
            if ($uri === $full && array_intersect($r->methods, $methods))
                throw new \RuntimeException("Duplicate route: {$full}");

        self::$routes[] = (object)[
            'pattern'     => $full,
            'methods'     => $methods,
            'controller'  => $controller,
            'middlewares' => $base->middlewares,
            'request'     => $base->request,
            'strict_mode' => $strict_mode,
        ];
    }

    
    public static function __callStatic($name, $arguments) {
        $methods = [];

        switch ($name) {
            case 'get':
            case 'post':
            case 'put':
            case 'patch':
            case 'delete':
            case 'options':
                $methods = [strtoupper($name)];
                break;
            case 'any':
                $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
                break;
            case 'match':
                $methods = array_shift($arguments);
                break;
            default:
                throw new \Error("Call to undefined method " . __CLASS__ . "::{$name}()");
        }

        return self::add_route($methods, ...$arguments);
    }

    public function __call($name, $arguments) {
        return self::__callStatic($name, $arguments);
    }

    public static function routes(): array {
        return self::$routes;
    }
}
