<?php

namespace ST_system\Tests\Unit\API;

use ST_system\API\IntegrationDriver;
use ST_system\Tests\Support\StubServer;
use ST_system\Tests\TestCase;

final class IntegrationDriverTest extends TestCase {

    protected function setUp(): void {
        if (!extension_loaded('curl'))
            $this->markTestSkipped('ext-curl не установлен');

        // Заглушка работает по http://, а WebClient запрещает его при verify=true.
        EchoApi::setConfig(['endpoint' => StubServer::url().'/api/', 'verify' => false, 'cache' => ['use' => false]]);
    }

    public function testGetWithQueryParams(): void {
        $response = EchoApi::create()->call('search', ['q' => 'кофе', 'empty' => null]);

        $this->assertSame('GET', $response['method']);
        $this->assertSame('/api/search', $response['path']);
        $this->assertSame(['q' => 'кофе'], $response['query']);
    }

    public function testPathPlaceholdersAndJsonBody(): void {
        $response = EchoApi::create()->call('items/{id}', ['id' => '/42/', 'name' => 'Чашка']);

        $this->assertSame('POST', $response['method']);
        $this->assertSame('/api/items/42', $response['path']);
        $this->assertSame(['name' => 'Чашка'], json_decode($response['body'], true));
        $this->assertSame('application/json', $response['headers']['content-type']);
        $this->assertSame('test', $response['headers']['x-client']);
    }

    public function testConcreteUrlResolvesTemplateMethod(): void {
        $response = EchoApi::create()->call('items/7', ['name' => 'x']);

        $this->assertSame('/api/items/7', $response['path']);
    }

    public function testMissingPathParameterIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Не передан обязательный параметр id!');

        EchoApi::create()->call('items/{id}', ['name' => 'x']);
    }

    public function testParamRulesValidate(): void {
        $this->expectException(\InvalidArgumentException::class);

        EchoApi::create()->call('search', ['q' => '']);
    }

    public function testClosureMethodSkipsHttp(): void {
        $this->assertSame(['local' => 3], EchoApi::create()->call('local', ['n' => 3]));
    }

    public function testUnknownMethodThrows(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Метод 'nope' не зарегистрирован");

        EchoApi::create()->call('nope');
    }

    public function testDuplicateRegistrationThrows(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("уже зарегистрирован");

        DuplicateApi::create();
    }

    public function testNonJsonResponseThrowsWithoutDecoder(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ошибка при декодировании ответа');

        EchoApi::create()->call('text');
    }

    public function testEventsCanReplaceDecodingAndObserveCalls(): void {
        $api = EchoApi::create();
        $log = [];

        $api->on('decode_response', function ($method, $params, &$raw) { $raw['response'] = strtoupper($raw['response']); });
        $api->on('call', function ($method, $params) use (&$log) { $log[] = $method; });

        $this->assertSame('PLAIN TEXT', $api->call('text'));
        $this->assertSame(['text'], $log);
    }

    public function testCallManyKeepsOrder(): void {
        $results = EchoApi::create()->callMany([
            ['search', ['q' => 'a']],
            ['method' => 'local', 'params' => ['n' => 1]],
            'text-json',
        ]);

        $this->assertSame(['q' => 'a'], $results[0]['query']);
        $this->assertSame(['local' => 1], $results[1]);
        $this->assertSame('/api/text-json', $results[2]['path']);
    }

    public function testCachedCallsAreServedFromCache(): void {
        EchoApi::setConfig(['cache' => ['use' => true, 'dir' => $this->tmpDir('api-cache')]]);
        $api   = EchoApi::create('cache-key');
        $calls = 0;
        $api->on('before_curl_init', function () use (&$calls) { $calls++; });

        $first  = $api->call('cached', ['q' => 'x']);
        $second = EchoApi::create('cache-key')->call('cached', ['q' => 'x']);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function testBuildUrlCollapsesSlashes(): void {
        [$url] = self::callPrivate(EchoApi::create(), 'build_url', '/items//7/', 'https://example.com/v1/');

        $this->assertSame('https://example.com/v1/items/7/', $url);
    }
}

final class EchoApi extends IntegrationDriver {

    protected function __init(): void {
        $this->registerMethodsMap([
            'search'     => ['params' => ['q' => 'required|string', 'empty' => 'sometimes']],
            'items/{id}' => [
                'method'       => 'post',
                'content_type' => 'application/json',
                'headers'      => ['X-Client' => 'test'],
                'params'       => ['name' => 'required|string'],
            ],
            'text'       => [],
            'text-json'  => [],
            'cached'     => ['cache_ttl' => 60],
            'local'      => fn(array $params) => ['local' => $params['n']],
        ]);
    }
}

final class DuplicateApi extends IntegrationDriver {

    protected function __init(): void {
        $this->registerMethod('users/{id}', []);
        $this->registerMethod('users/{user}', []);
    }
}
