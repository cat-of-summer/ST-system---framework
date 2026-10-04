<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\HTTP\Response;
use ST_system\MCP\Context;
use ST_system\MCP\Dispatcher;
use ST_system\MCP\Result;
use ST_system\MCP\Server;
use ST_system\MCP\State\PendingStore;
use ST_system\MCP\State\SessionStore;
use ST_system\MCP\Transport\HttpTransport;
use ST_system\Tests\Support\MemoryChannel;
use ST_system\Tests\Support\ResponseProbe;
use ST_system\Tests\TestCase;

final class HttpTransportTest extends TestCase {

    private MemoryChannel $channel;

    protected function setUp(): void {
        $this->channel = new MemoryChannel();
    }

    private static function dispatcher(): Dispatcher {
        return Server::create(['name' => 'test'], function () {
            Server::tool('quick', function () { return Result::ok('быстро'); });

            Server::tool('slow', function (array $args, Context $ctx) {
                return Result::ok($ctx->stream !== null ? 'в потоке' : 'без потока', ['elicitation' => $ctx->elicitor->supported()]);
            })->streams();
        });
    }

    private function transport(): HttpTransport {
        $channel = $this->channel;

        return new HttpTransport(self::dispatcher(), function () use ($channel) { return $channel; });
    }

    private function post(array $message, array $headers = []): Response {
        return $this->transport()->handle('POST', json_encode($message), $headers);
    }

    private function initialize(array $capabilities = ['elicitation' => []], string $version = '2025-06-18'): string {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => $version,
            'capabilities'    => $capabilities,
            'clientInfo'      => ['name' => 'test', 'version' => '0'],
        ]]);

        return (string)ResponseProbe::header($response, 'Mcp-Session-Id');
    }

    public function testInitializeOpensSession(): void {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => ['elicitation' => new \stdClass()],
            'clientInfo'      => ['name' => 'claude'],
        ]]);

        $session = (string)ResponseProbe::header($response, 'Mcp-Session-Id');

        $this->assertSame(200, ResponseProbe::status($response));
        $this->assertTrue(SessionStore::valid($session));
        $this->assertSame('2025-06-18', ResponseProbe::json($response)['result']['protocolVersion']);

        $stored = SessionStore::get($session);
        $this->assertTrue($stored['elicitation']);
        $this->assertSame(['name' => 'claude'], $stored['client']);
    }

    /** Клиент 2025-11-25 может объявить только url-режим — тогда формы ему не шлём. */
    public function testElicitationCapabilityModes(): void {
        $this->assertTrue(SessionStore::supportsForms([]));
        $this->assertTrue(SessionStore::supportsForms(['form' => []]));
        $this->assertTrue(SessionStore::supportsForms(['form' => [], 'url' => []]));
        $this->assertFalse(SessionStore::supportsForms(['url' => []]));
        $this->assertFalse(SessionStore::supportsForms(null));
    }

    public function testRequestWithoutSessionIsRejected(): void {
        $this->assertSame(400, ResponseProbe::status($this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])));

        $unknown = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['mcp-session-id' => str_repeat('0', 32)]);
        $this->assertSame(404, ResponseProbe::status($unknown), 'неизвестная сессия — 404, клиент переподключится');
        $this->assertSame(-32001, ResponseProbe::json($unknown)['error']['code']);
    }

    public function testToolsListAndCallAsJson(): void {
        $session = $this->initialize();

        $list = $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['mcp-session-id' => $session]);
        $this->assertSame(['quick', 'slow'], array_column(ResponseProbe::json($list)['result']['tools'], 'name'));
        $this->assertSame($session, ResponseProbe::header($list, 'Mcp-Session-Id'));

        $call = $this->post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'quick']], ['mcp-session-id' => $session]);
        $this->assertSame('быстро', ResponseProbe::json($call)['result']['content'][0]['text']);
    }

    public function testStreamingToolAnswersWithSse(): void {
        $session  = $this->initialize();
        $response = $this->post(
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'slow']],
            ['mcp-session-id' => $session, 'accept' => 'application/json, text/event-stream']
        );

        $this->assertSame('text/event-stream', ResponseProbe::header($response, 'Content-Type'));
        $this->assertSame('no', ResponseProbe::header($response, 'X-Accel-Buffering'));

        $stream = ResponseProbe::stream($response);
        $this->assertNotNull($stream);
        $stream();

        $this->assertCount(1, $this->channel->sent);
        $this->assertSame(4, $this->channel->sent[0]['id']);
        $this->assertSame(['elicitation' => true], $this->channel->sent[0]['result']['structuredContent']);
        $this->assertStringStartsWith('в потоке', $this->channel->sent[0]['result']['content'][0]['text']);
    }

    /** Клиент без text/event-stream в Accept получает JSON и без elicitation. */
    public function testStreamingToolFallsBackToJson(): void {
        $session  = $this->initialize();
        $response = $this->post(
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'slow']],
            ['mcp-session-id' => $session, 'accept' => 'application/json']
        );

        $this->assertNull(ResponseProbe::stream($response));
        $this->assertSame(['elicitation' => false], ResponseProbe::json($response)['result']['structuredContent']);
    }

    public function testNotificationsAndClientResponsesAreAccepted(): void {
        $session = $this->initialize();

        $notification = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['mcp-session-id' => $session]);
        $this->assertSame(202, ResponseProbe::status($notification));

        PendingStore::expect($session, 'elicit-1');
        $answer = $this->post(
            ['jsonrpc' => '2.0', 'id' => 'elicit-1', 'result' => ['action' => 'accept', 'content' => ['confirmed' => true]]],
            ['mcp-session-id' => $session]
        );

        $this->assertSame(202, ResponseProbe::status($answer));
        $this->assertSame('accept', PendingStore::take($session, 'elicit-1')['result']['action']);
    }

    /** Ответ на вопрос, которого сервер не задавал, отбрасывается. */
    public function testUnexpectedAnswerIsDropped(): void {
        $session = $this->initialize();

        $this->post(['jsonrpc' => '2.0', 'id' => 'forged', 'result' => ['action' => 'accept']], ['mcp-session-id' => $session]);

        $this->assertNull(PendingStore::take($session, 'forged'));
    }

    public function testMalformedRequests(): void {
        $transport = $this->transport();

        $this->assertSame(-32700, ResponseProbe::json($transport->handle('POST', '{not json', []))['error']['code']);
        $this->assertSame(-32600, ResponseProbe::json($transport->handle('POST', '[{"jsonrpc":"2.0"}]', []))['error']['code']);
        $this->assertSame(-32600, ResponseProbe::json($transport->handle('POST', '{"id":1,"method":"ping"}', []))['error']['code']);

        $get = $transport->handle('GET', '', []);
        $this->assertSame(405, ResponseProbe::status($get));
        $this->assertSame('POST, DELETE', ResponseProbe::header($get, 'Allow'));
    }

    public function testDeleteClosesSession(): void {
        $session = $this->initialize();

        $this->assertSame(204, ResponseProbe::status($this->transport()->handle('DELETE', '', ['mcp-session-id' => $session])));
        $this->assertNull(SessionStore::get($session));
        $this->assertSame(404, ResponseProbe::status($this->transport()->handle('DELETE', '', ['mcp-session-id' => $session])));
    }
}
