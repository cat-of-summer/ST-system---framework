<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\Tests\Support\StubServer;
use ST_system\Tests\TestCase;

/**
 * MCP по настоящему HTTP: Route + Server::route под php -S (tests/Support/mcp-app.php).
 * Встречный POST с ответом человека приходит, пока открыт SSE-поток вызова, поэтому
 * сервер поднимается с несколькими воркерами (PHP_CLI_SERVER_WORKERS, только не Windows).
 */
final class McpHttpTest extends TestCase {

    private static string $url = '';

    protected function setUp(): void {
        if (!function_exists('curl_init'))
            $this->markTestSkipped('Нужно расширение curl.');

        if (DIRECTORY_SEPARATOR === '\\')
            $this->markTestSkipped('php -S с несколькими воркерами работает только не на Windows.');

        if (self::$url === '') {
            $root = ST_TESTS_ROOT.'/mcp-app';
            @mkdir($root, 0777, true);

            self::$url = StubServer::app(dirname(__DIR__, 2).'/Support/mcp-app.php', [
                'ST_MCP_ROOT'           => $root,
                'PHP_CLI_SERVER_WORKERS' => '4',
            ]);
        }
    }

    /**
     * @param ?callable(array $event): void $onEvent для SSE: каждое событие message
     * @return array{status:int,headers:array<string,string>,body:string,events:array}
     */
    private static function request(string $method, $body = null, array $headers = [], ?callable $onEvent = null): array {
        $ch = curl_init(self::$url.'/mcp');
        $responseHeaders = [];
        $raw    = '';
        $buffer = '';
        $events = [];

        $defaults = ['Content-Type: application/json'];
        if (!preg_grep('/^Authorization:/i', $headers)) $defaults[] = 'Authorization: Bearer secret';

        $headers = array_merge($defaults, $headers);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$raw, &$buffer, &$events, $onEvent) {
                $raw    .= $chunk;
                $buffer .= $chunk;

                while (($end = strpos($buffer, "\n\n")) !== false) {
                    $event  = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 2);

                    if (preg_match('/^data: (.*)$/m', $event, $m)) {
                        $events[] = $data = json_decode($m[1], true);
                        if ($onEvent !== null) $onEvent($data);
                    }
                }

                return strlen($chunk);
            },
        ]);

        if ($body !== null)
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw, 'events' => $events];
    }

    private static function initialize(array $capabilities = ['elicitation' => []]): string {
        $response = self::request('POST', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => $capabilities ?: new \stdClass(),
            'clientInfo'      => ['name' => 'e2e-client', 'version' => '1'],
        ]]);

        return $response['headers']['mcp-session-id'];
    }

    private static function call(string $session, string $tool, array $arguments, ?callable $onEvent = null, string $accept = 'application/json, text/event-stream'): array {
        return self::request(
            'POST',
            ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]],
            ["Mcp-Session-Id: {$session}", "Accept: {$accept}", 'X-Trace: t-1'],
            $onEvent
        );
    }

    /** Ответ человека — отдельный POST, пока поток вызова ещё открыт. */
    private static function answerer(string $session, array $result): callable {
        return static function (array $event) use ($session, $result): void {
            if (($event['method'] ?? '') !== 'elicitation/create') return;

            self::request('POST', ['jsonrpc' => '2.0', 'id' => $event['id'], 'result' => $result], ["Mcp-Session-Id: {$session}"]);
        };
    }

    public function testRouteMiddlewareGuardsServer(): void {
        $response = self::request('POST', '{}', ['Authorization: Bearer wrong']);

        $this->assertSame(401, $response['status']);
        $this->assertSame(['message' => 'Нужен токен.'], json_decode($response['body'], true));
    }

    public function testHandshakeListAndJsonCall(): void {
        $response = self::request('POST', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass()]]);
        $body     = json_decode($response['body'], true);
        $session  = $response['headers']['mcp-session-id'];

        $this->assertSame(200, $response['status']);
        $this->assertSame('e2e', $body['result']['serverInfo']['name']);
        $this->assertSame('Тестовый сервер.', $body['result']['instructions']);

        $this->assertSame(202, self::request('POST', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ["Mcp-Session-Id: {$session}"])['status']);

        $list = json_decode(self::request('POST', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ["Mcp-Session-Id: {$session}"])['body'], true);
        $this->assertSame(['echo', 'remove'], array_column($list['result']['tools'], 'name'));

        $echo = self::call(self::initialize(), 'echo', ['text' => 'привет']);
        $this->assertSame('application/json', explode(';', $echo['headers']['content-type'])[0]);
        $this->assertSame(
            ['text' => 'привет', 'client' => 'e2e-client', 'trace' => 't-1'],
            json_decode($echo['body'], true)['result']['structuredContent']
        );
    }

    public function testConfirmationAcceptedOverSse(): void {
        $session  = self::initialize();
        $response = self::call($session, 'remove', ['id' => 'db'], self::answerer($session, ['action' => 'accept', 'content' => ['confirmed' => true]]));

        $this->assertSame('text/event-stream', explode(';', $response['headers']['content-type'])[0]);
        $this->assertCount(2, $response['events']);
        $this->assertSame('elicitation/create', $response['events'][0]['method']);
        $this->assertSame('Удалить db?', $response['events'][0]['params']['message']);

        $result = $response['events'][1];
        $this->assertSame(10, $result['id']);
        $this->assertFalse($result['result']['isError']);
        $this->assertSame('Удалено: db.', $result['result']['content'][0]['text']);
    }

    public function testConfirmationDeclinedOverSse(): void {
        $session  = self::initialize();
        $response = self::call($session, 'remove', ['id' => 'db', 'confirm' => true], self::answerer($session, ['action' => 'decline']));

        $result = end($response['events']);
        $this->assertTrue($result['result']['isError']);
        $this->assertStringStartsWith('Человек отказался', $result['result']['content'][0]['text']);
    }

    /** Клиент без elicitation: вопрос не задаётся, нужен confirm: true. */
    public function testConfirmationWithoutElicitation(): void {
        $session = self::initialize([]);

        $refused = self::call($session, 'remove', ['id' => 'db']);
        $this->assertCount(1, $refused['events']);
        $this->assertStringContainsString('confirm: true', $refused['events'][0]['result']['content'][0]['text']);

        $done = self::call($session, 'remove', ['id' => 'db', 'confirm' => true], null, 'application/json');
        $this->assertSame('Удалено: db.', json_decode($done['body'], true)['result']['content'][0]['text']);
    }

    public function testGetAndDelete(): void {
        $this->assertSame(405, self::request('GET')['status']);

        $session = self::initialize();
        $this->assertSame(204, self::request('DELETE', null, ["Mcp-Session-Id: {$session}"])['status']);
        $this->assertSame(404, self::request('POST', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ["Mcp-Session-Id: {$session}"])['status']);
    }
}
