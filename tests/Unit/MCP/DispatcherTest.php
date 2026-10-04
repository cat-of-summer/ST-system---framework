<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\MCP\Context;
use ST_system\MCP\Dispatcher;
use ST_system\MCP\Elicitation\NoElicitation;
use ST_system\MCP\Result;
use ST_system\MCP\Server;
use ST_system\MCP\Tools\Tool;
use ST_system\MCP\Tools\ToolError;
use ST_system\Tests\TestCase;

final class DispatcherTest extends TestCase {

    private static function call(Dispatcher $dispatcher, string $name, array $arguments = []): array {
        return $dispatcher->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
            new Context(new NoElicitation())
        );
    }

    private static function text(array $response): string {
        return $response['result']['content'][0]['text'];
    }

    private static function app(): Dispatcher {
        return Server::create([
            'name'         => 'test',
            'version'      => '2.0.0',
            'title'        => 'Тестовый сервер',
            'instructions' => function () { return 'Порядок работы.'; },
        ], function () {
            Server::tool(EchoTool::class);

            Server::tool('add', function (array $args) { return Result::ok('сумма', ['sum' => $args['a'] + $args['b']]); })
                ->title('Сложение')
                ->description('Складывает два числа.')
                ->input([
                    'a' => ['type' => 'integer', 'description' => 'первое', 'required' => true],
                    'b' => ['type' => 'integer', 'description' => 'второе', 'default' => 1],
                ])
                ->readOnly()
                ->idempotent();

            Server::prefix('vault_')->group(function () {
                Server::middleware(function (array $args, Context $ctx, callable $next) {
                    $ctx->set('user', 'ann');
                    return $next($args, $ctx);
                });

                Server::tool('whoami', function (array $args, Context $ctx) { return Result::ok($ctx->get('user')); });
                Server::tool('fail', function () { throw new ToolError('Блок не найден.', ['id' => 'x']); });
                Server::tool('crash', function () { throw new \RuntimeException('boom'); });
            });

            Server::tool('after_group', function () { return Result::ok('ok'); });
        });
    }

    public function testInitializeNegotiatesVersionAndSendsInfo(): void {
        $result = self::app()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], new Context(new NoElicitation()))['result'];

        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame(['name' => 'test', 'version' => '2.0.0', 'title' => 'Тестовый сервер'], $result['serverInfo']);
        $this->assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        $this->assertSame('Порядок работы.', $result['instructions']);

        $this->assertSame(Dispatcher::LATEST, self::app()->negotiate('1999-01-01'));
        $this->assertSame(Dispatcher::LATEST, self::app()->negotiate(null));
    }

    public function testEmptyInstructionsAreOmitted(): void {
        $this->assertArrayNotHasKey('instructions', Server::create()->initializeResult('2025-06-18'));
    }

    public function testPingAndUnknownMethod(): void {
        $context = new Context(new NoElicitation());

        $this->assertEquals(new \stdClass(), self::app()->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'], $context)['result']);
        $this->assertSame(-32601, self::app()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'], $context)['error']['code']);
        $this->assertSame(-32602, self::call(self::app(), 'nope')['error']['code']);
    }

    public function testToolsListWithPrefixesAndAnnotations(): void {
        $tools = self::app()->toolsList()['tools'];

        $this->assertSame(['echo', 'add', 'vault_whoami', 'vault_fail', 'vault_crash', 'after_group'], array_column($tools, 'name'));

        $add = $tools[1];
        $this->assertSame('Сложение', $add['title']);
        $this->assertSame(['title' => 'Сложение', 'readOnlyHint' => true, 'idempotentHint' => true], $add['annotations']);
        $this->assertSame(['a'], $add['inputSchema']['required']);
        $this->assertFalse($add['inputSchema']['additionalProperties']);
        $this->assertArrayNotHasKey('required', $add['inputSchema']['properties']['a']);

        // Пустые properties — объект в JSON, а не массив.
        $this->assertSame('{"type":"object","properties":{}}', json_encode($tools[2]['inputSchema']));
        $this->assertSame(['type' => 'object', 'properties' => ['text' => ['type' => 'string']]], $tools[0]['outputSchema']);
    }

    public function testCallsClassAndClosureTools(): void {
        $echo = self::call(self::app(), 'echo', ['text' => 'привет']);
        $this->assertFalse($echo['result']['isError']);
        $this->assertSame(['text' => 'привет'], $echo['result']['structuredContent']);

        $add = self::call(self::app(), 'add', ['a' => '2']);
        $this->assertSame(['sum' => 3], $add['result']['structuredContent']);
    }

    public function testMiddlewareWrapsOnlyItsGroup(): void {
        $this->assertSame('ann', self::text(self::call(self::app(), 'vault_whoami')));
        $this->assertSame('ok', self::text(self::call(self::app(), 'after_group')));
    }

    public function testErrorsBecomeToolResults(): void {
        $invalid = self::call(self::app(), 'add', ['a' => 'x']);
        $this->assertTrue($invalid['result']['isError']);
        $this->assertStringStartsWith('Неверные параметры: ', self::text($invalid));

        $refused = self::call(self::app(), 'vault_fail');
        $this->assertTrue($refused['result']['isError']);
        $this->assertSame(['id' => 'x'], $refused['result']['structuredContent']);
        $this->assertStringStartsWith('Блок не найден.', self::text($refused));

        $log = ini_set('error_log', $this->tmpDir().'/error.log');
        try {
            $crash = self::call(self::app(), 'vault_crash');
        } finally {
            ini_set('error_log', (string)$log);
        }
        $this->assertTrue($crash['result']['isError']);
        $this->assertSame('Внутренняя ошибка сервера: boom', self::text($crash));
    }

    public function testMiddlewareCanShortCircuitAndTakeArguments(): void {
        $dispatcher = Server::create([], function () {
            Server::middleware([[function (array $args, Context $ctx, callable $next, string $role) {
                return $role === 'admin' ? $next($args, $ctx) : Result::error("Нужна роль admin, а не {$role}.");
            }, 'guest']]);

            Server::tool('secret', function () { return Result::ok('секрет'); });
        });

        $this->assertSame('Нужна роль admin, а не guest.', self::text(self::call($dispatcher, 'secret')));
    }

    public function testClassMiddlewareWithStaticHandle(): void {
        $dispatcher = Server::create([], function () {
            Server::middleware(UppercaseMiddleware::class);
            Server::tool(EchoTool::class);
        });

        $this->assertSame('ПРИВЕТ', self::call($dispatcher, 'echo', ['text' => 'привет'])['result']['structuredContent']['text']);
    }

    public function testDuplicateAndInvalidNames(): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Инструмент echo уже зарегистрирован');

        Server::create([], function () {
            Server::tool(EchoTool::class);
            Server::tool('echo', function () { return Result::ok(''); });
        });
    }

    public function testInvalidName(): void {
        $this->expectException(\InvalidArgumentException::class);

        Server::create([], function () {
            Server::tool('с пробелом', function () { return Result::ok(''); });
        });
    }

    public function testDeclarationsOutsideServerAreRejected(): void {
        $this->expectException(\LogicException::class);

        Server::tool(EchoTool::class);
    }

    public function testCreateRestoresStateAfterException(): void {
        try {
            Server::create([], function () { throw new \RuntimeException('сборка упала'); });
        } catch (\RuntimeException $e) {
        }

        $this->expectException(\LogicException::class);
        Server::tool(EchoTool::class);
    }

    public function testClosureMustReturnResult(): void {
        $dispatcher = Server::create([], function () {
            Server::tool('bad', function () { return 'строка'; });
        });

        $log = ini_set('error_log', $this->tmpDir().'/error.log');
        try {
            $this->assertTrue(self::call($dispatcher, 'bad')['result']['isError']);
        } finally {
            ini_set('error_log', (string)$log);
        }
    }
}

final class EchoTool extends Tool {

    public function name(): string { return 'echo'; }

    public function description(): string { return 'Возвращает переданный текст.'; }

    public function inputSchema(): array {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'description' => 'текст']], 'required' => ['text']];
    }

    public function outputSchema(): ?array {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
    }

    public function call(array $args, Context $context): Result {
        return Result::ok('эхо', ['text' => $args['text']]);
    }
}

final class UppercaseMiddleware {

    public static function handle(array $args, Context $ctx, callable $next): Result {
        $args['text'] = mb_strtoupper($args['text']);
        return $next($args, $ctx);
    }
}
