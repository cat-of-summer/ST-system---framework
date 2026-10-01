<?php

namespace ST_system\Tests\Unit\API;

use ST_system\API\Router;
use ST_system\Tests\TestCase;

final class RouterTest extends TestCase {

    private array $server;

    protected function setUp(): void {
        $this->server = $_SERVER;
        self::setStatic(Router::class, 'URL_parsers_list', []);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/users/7/posts/9?x=1';
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;

        parent::tearDown();
    }

    public function testCollectsMatchingRulesWithPlaceholders(): void {
        $router = new Router(['url_rules' => [
            ['/users/{id}', 'user'],
            ['/users/{id}/posts/{post_id}', 'post'],
            ['/orders/{id}', 'order'],
        ]]);

        $result = $router->apply('page', 'extra');

        $this->assertSame(['user', 'post'], $result['PARSER_PARAMS']);
        $this->assertSame([['id' => '7'], ['id' => '7', 'post_id' => '9']], $result['URL_PARAMS']);
        $this->assertSame(['page', 'extra'], $result['PAGE_PARAMS']);
    }

    public function testStrictModeAnchorsToPoint(): void {
        $router = new Router([
            'strict_mode' => true,
            'point'       => '/',
            'url_rules'   => [['users/{id}', 'partial'], ['users/{id}/posts/{post_id}', 'full']],
        ]);

        $this->assertSame(['full'], $router->apply()['PARSER_PARAMS']);
    }

    public function testHighestPriorityGroupWins(): void {
        $router = new Router(['url_rules' => [
            ['/users/{id}', 'plain'],
            ['/users/{id}/posts/{post_id}', 'p9', 9],
            ['/posts/{post_id}', 'p5', 5],
            ['/users/{id}', 'p9-too', 9],
        ]]);

        $this->assertSame(['p9', 'p9-too'], $router->apply()['PARSER_PARAMS']);
    }

    public function testApplyOnceKeepsFirstMatchOfGroup(): void {
        $router = new Router([
            'apply_once' => true,
            'url_rules'  => [['/users/{id}', 'first'], ['/posts/{id}', 'second']],
        ]);

        $this->assertSame(['first'], $router->apply()['PARSER_PARAMS']);
    }

    public function testNoMatchReturnsEmptyResult(): void {
        $router = new Router(['url_rules' => [['/nothing', 'x']]]);

        $this->assertSame(['PARSER_PARAMS' => [], 'URL_PARAMS' => [], 'PAGE_PARAMS' => []], $router->apply());
    }

    public function testMethodsAreFilteredAndUppercased(): void {
        $router = new Router(['methods' => ['post', 'DELETE', 'TRACE']]);

        $this->assertSame(['POST', 'DELETE'], $router->methods);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Метод GET не доступен');
        $router->apply();
    }

    public function testCustomRulesHandlerAndRegistry(): void {
        new Router([
            'key'           => 'main',
            'url_rules'     => [['/users/{id}', 'user']],
            'rules_handler' => fn($parsers, $urls, $page) => [$parsers[0], $urls[0]['id'], $page],
        ]);

        $this->assertSame(['user', '7', ['p']], Router::apply_parser('main', 'p'));
        $this->assertNull(Router::apply_parser('missing'));
    }

    public function testUnknownPropertyThrows(): void {
        $this->expectException(\Exception::class);

        (new Router())->nothing;
    }
}
