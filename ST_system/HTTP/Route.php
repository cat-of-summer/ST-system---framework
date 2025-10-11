<?php

namespace ST_system\HTTP;

final class Route {
    private static $API_POINT = '';
    private static $routes = [];
    private static $stack = [];

    private $prefix = '';
    private $middlewares = [];

    private function __construct(string $prefix = '', array $middlewares = []) {
        if ($prefix != '')
            $this->prefix = $prefix;
        elseif (self::$API_POINT != '')
            $this->prefix = self::$API_POINT;
        else
            throw new \RuntimeException("Configuration error: API_POINT is not set");

        $this->middlewares = $middlewares;
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

    private static function add_route(array $methods, string $uri, $controller, array $PARAMS = []): void {
        $base = self::current();
        $strict_mode = isset($PARAMS['strict_mode']) ? (bool)$PARAMS['strict_mode'] : true;

        $full = $strict_mode
            ? str_replace('//', '/', '/'.trim($base->prefix, '/').'/'.trim($uri, '/').'/')
            : str_replace('//', '/', trim($base->prefix, '/').'/'.trim($uri, '/'));
            
        foreach (self::$routes as $uri => $r)
            if ($uri === $full && array_intersect($r->methods, $methods))
                throw new \RuntimeException("Duplicate route: {$full}");

        self::$routes[$full] = (object)[
            'methods'     => $methods,
            'controller'  => $controller,
            'middlewares' => $base->middlewares,
            'strict_mode' => $strict_mode,
        ];
    }

    /*
    ?? array $methods
    string $uri, 
    $controller, 
    array $PARAMS = []
    */
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