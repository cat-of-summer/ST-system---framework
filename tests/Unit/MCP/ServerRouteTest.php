<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\HTTP\Request;
use ST_system\HTTP\Route;
use ST_system\MCP\Result;
use ST_system\MCP\Server;
use ST_system\Tests\Support\ResponseProbe;
use ST_system\Tests\TestCase;

/** Server::route() — MCP-сервер как маршрут Route. */
final class ServerRouteTest extends TestCase {

    private static int $built = 0;

    protected function setUp(): void {
        self::setStatic(Route::class, 'API_POINT', '');
        self::setStatic(Route::class, 'routes', []);
        self::setStatic(Route::class, 'stack', []);
        self::$built = 0;
    }

    private static function register(): void {
        Route::point('api');
        Route::get('ping', function () { return 'pong'; });

        Route::prefix('mcp')->group(function () {
            Route::middleware('RequireToken');

            Server::route('', ['name' => 'app'], function () {
                self::$built++;
                Server::tool('hello', function () { return Result::ok('привет'); });
            });
        });
    }

    public function testRegistersRouteInheritingPrefixAndMiddleware(): void {
        self::register();

        [, $mcp] = Route::routes();

        $this->assertSame('/api/mcp/', $mcp->pattern);
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $mcp->methods);
        $this->assertSame(['RequireToken'], $mcp->middlewares);
    }

    public function testToolsAreDeclaredLazilyOnRequest(): void {
        self::register();

        $this->assertSame(0, self::$built, 'инструменты не собираются, пока нет запроса к маршруту');

        [, $mcp] = Route::routes();

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $response = ($mcp->controller)(Request::fetch());

        $this->assertSame(1, self::$built);
        $this->assertSame(404, ResponseProbe::status($response), 'DELETE без сессии — 404');
    }
}
