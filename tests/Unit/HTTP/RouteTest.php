<?php

namespace ST_system\Tests\Unit\HTTP;

use ST_system\HTTP\Route;
use ST_system\Tests\TestCase;

final class RouteTest extends TestCase {

    protected function setUp(): void {
        self::setStatic(Route::class, 'API_POINT', '');
        self::setStatic(Route::class, 'routes', []);
        self::setStatic(Route::class, 'stack', []);
    }

    private static function patterns(): array {
        return array_map(fn($r) => [$r->pattern, $r->methods], Route::routes());
    }

    public function testRegistrationWithPointPrefixAndGroups(): void {
        Route::point('api');
        Route::get('ping', fn() => 'pong');

        Route::prefix('v1')->group(function () {
            Route::post('/users/', fn() => null);
            Route::prefix('admin')->group(function () {
                Route::any('stats', fn() => null);
            });
            Route::match(['PUT', 'PATCH'], 'users/{id}', fn() => null);
        });

        Route::delete('after-group', fn() => null);

        $this->assertSame([
            ['/api/ping/', ['GET']],
            ['/api/v1/users/', ['POST']],
            ['/api/v1/admin/stats/', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']],
            ['/api/v1/users/{id}/', ['PUT', 'PATCH']],
            ['/api/after-group/', ['DELETE']],
        ], self::patterns());
    }

    public function testMiddlewaresAndRequestAreInheritedInsideGroup(): void {
        Route::point('api');

        Route::prefix('secure')->group(function () {
            Route::middleware('Auth')->request('MyRequest');
            Route::get('me', fn() => null);
        });
        Route::get('public', fn() => null);

        [$me, $public] = Route::routes();
        $this->assertSame(['Auth'], $me->middlewares);
        $this->assertSame('MyRequest', $me->request);
        $this->assertSame([], $public->middlewares);
        $this->assertNull($public->request);
    }

    public function testDuplicateRouteThrows(): void {
        Route::point('api');
        Route::get('users', fn() => null);
        Route::post('users', fn() => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate route: /api/users/');

        Route::any('users', fn() => null);
    }

    public function testRouteWithoutPointThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API_POINT is not set');

        Route::get('x', fn() => null);
    }

    public function testUnknownVerbThrows(): void {
        Route::point('api');

        $this->expectException(\Error::class);
        Route::fetch('x', fn() => null);
    }

    /**
     * Прогоняет handleRequest() в отдельном PHP: он всегда завершается через Response::send() + exit.
     *
     * @return array{0:int,1:array} [HTTP-статус, декодированный JSON]
     */
    private function dispatch(string $method, string $uri, string $routes): array {
        $script = '<?php
            require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';
            $_SERVER["REQUEST_METHOD"] = '.var_export($method, true).';
            $_SERVER["REQUEST_URI"]    = '.var_export($uri, true).';
            $_SERVER["DOCUMENT_ROOT"]  = '.var_export(ST_TESTS_ROOT, true).';
            register_shutdown_function(function () { echo "\n", http_response_code(); });
            use ST_system\HTTP\Route;
            use ST_system\HTTP\Request;
            '.$routes.'
            Route::handleRequest();';

        $file = $this->writeFile($this->tmpDir('route').'/index.php', $script);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output);

        $status = (int)array_pop($output);

        return [$status, json_decode(implode("\n", $output), true)];
    }

    public function testDispatchesWithPlaceholders(): void {
        [$status, $body] = $this->dispatch('GET', '/api/users/42/files/a/b.txt?x=1', '
            Route::point("api");
            Route::get("users/{id:\d+}/files/{...path}", fn(Request $r) => [$r->query("id"), $r->query("path")]);
        ');

        $this->assertSame(200, $status);
        $this->assertSame(['42', 'a/b.txt'], $body);
    }

    public function testOptionalPlaceholder(): void {
        $routes = 'Route::point("api"); Route::get("posts/{page?}", fn(Request $r) => ["page" => $r->query("page")]);';

        $this->assertSame([200, ['page' => '3']], $this->dispatch('GET', '/api/posts/3', $routes));
        $this->assertSame([200, ['page' => null]], $this->dispatch('GET', '/api/posts', $routes));
    }

    public function testNotFound(): void {
        [$status] = $this->dispatch('GET', '/api/nowhere', 'Route::point("api"); Route::get("users", fn() => []);');

        $this->assertSame(404, $status);
    }

    public function testControllerExceptionBecomesJsonError(): void {
        [$status, $body] = $this->dispatch('GET', '/api/fail', '
            Route::point("api");
            Route::get("fail", function () { throw new \RuntimeException("Сломалось", 422); });
        ');

        $this->assertSame(422, $status);
        $this->assertSame(['message' => 'Сломалось'], $body);
    }

    public function testMatchPicksRouteByMethod(): void {
        Route::point('api');
        Route::get('blocks/{id}', fn() => 'show');
        Route::delete('blocks/{id}', fn() => 'delete');

        [$route, $params, $allowed] = self::callPrivate(Route::class, 'match', '/api/blocks/7', 'DELETE');
        $this->assertSame(['DELETE'], $route->methods);
        $this->assertSame(['id' => '7'], $params);
        $this->assertSame([], $allowed);

        [$route] = self::callPrivate(Route::class, 'match', '/api/blocks/7', 'head');
        $this->assertSame(['GET'], $route->methods);

        [$route, $params, $allowed] = self::callPrivate(Route::class, 'match', '/api/blocks/7', 'PUT');
        $this->assertNull($route);
        $this->assertSame([], $params);
        $this->assertSame(['GET', 'HEAD', 'DELETE'], $allowed);

        $this->assertSame([null, [], []], self::callPrivate(Route::class, 'match', '/api/nowhere', 'GET'));
    }

    public function testDispatchByMethodAnd405(): void {
        $routes = '
            Route::point("api");
            Route::get("blocks/{id}", fn(Request $r) => ["show" => $r->query("id")]);
            Route::delete("blocks/{id}", fn(Request $r) => ["deleted" => $r->query("id")]);
        ';

        $this->assertSame([200, ['deleted' => '7']], $this->dispatch('DELETE', '/api/blocks/7', $routes));
        $this->assertSame([200, ['show' => '7']], $this->dispatch('GET', '/api/blocks/7', $routes));
        $this->assertSame(405, $this->dispatch('PUT', '/api/blocks/7', $routes)[0]);
    }

    public function testValidationErrorIs422WithFieldErrors(): void {
        [$status, $body] = $this->dispatch('POST', '/api/users', '
            Route::point("api");
            Route::post("users", function (Request $r) {
                $r->throwable()->validate(["email" => "required|email", "age" => "required|int"]);
                return "ok";
            });
        ');

        $this->assertSame(422, $status);
        $this->assertSame(['email', 'age'], array_keys($body['errors']));
        $this->assertSame(['This field is required'], $body['errors']['email']);
        $this->assertStringContainsString('email.This field is required', $body['message']);
    }

    public function testMiddlewareCanRejectRequest(): void {
        [$status, $body] = $this->dispatch('GET', '/api/admin', '
            Route::point("api");
            Route::middleware([[function (Request $r, $role) { throw new \Exception("Нужна роль {$role}", 401); }, "admin"]]);
            Route::get("admin", fn() => "secret");
        ');

        $this->assertSame(401, $status);
        $this->assertSame(['message' => 'Нужна роль admin'], $body);
    }
}
