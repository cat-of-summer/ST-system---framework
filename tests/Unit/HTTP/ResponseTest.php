<?php

namespace ST_system\Tests\Unit\HTTP;

use ST_system\HTTP\Response;
use ST_system\Tests\TestCase;

/** send() завершает процесс, поэтому состояние ответа читается через Reflection. */
final class ResponseTest extends TestCase {

    private static function state(Response $response): array {
        return [
            'status'  => self::getProperty($response, 'status'),
            'headers' => self::getProperty($response, 'headers'),
            'content' => self::getProperty($response, 'content'),
        ];
    }

    public function testJson(): void {
        $state = self::state(Response::json(['a' => 'б/в'], 201));

        $this->assertSame(201, $state['status']);
        $this->assertSame('{"a":"б/в"}', $state['content']);
        $this->assertSame('application/json; charset=UTF-8', $state['headers']['Content-Type']);
    }

    public function testJsonEncodingErrorThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON encoding error');

        Response::json(["\xB1\x31"]);
    }

    public function testTextHtmlAndRaw(): void {
        $this->assertSame('text/plain; charset=UTF-8', self::state(Response::text('x'))['headers']['Content-Type']);
        $this->assertSame('text/html; charset=UTF-8', self::state(Response::html('<p>'))['headers']['Content-Type']);
        $this->assertSame(['status' => 204, 'headers' => [], 'content' => ''], self::state(Response::raw('', 204)));
    }

    public function testHeaderNamesAreNormalized(): void {
        $headers = self::state(Response::headers(['content_type' => 'a', 'x-REQUEST id' => 'b'])->status(418))['headers'];

        $this->assertSame(['Content-Type' => 'a', 'X-Request-Id' => 'b'], $headers);
    }

    public function testRedirect(): void {
        $state = self::state(Response::redirect('/login', 301));

        $this->assertSame(301, $state['status']);
        $this->assertSame('/login', $state['headers']['Location']);
    }

    public function testFileHeaders(): void {
        $file = $this->writeFile($this->tmpDir().'/report "q".txt', 'hello');
        touch($file, 1700000000);
        clearstatcache();

        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Moscow');

        try {
            $headers = self::state(Response::download($file))['headers'];
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('text/plain', $headers['Content-Type']);
        $this->assertSame('attachment; filename="report \'q\'.txt"', $headers['Content-Disposition']);
        $this->assertSame('5', $headers['Content-Length']);
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $headers['Last-Modified']);
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $headers['Etag']);

        $this->assertStringStartsWith('inline;', self::state(Response::file($file, 'x.txt'))['headers']['Content-Disposition']);
    }

    public function testMissingFileThrows(): void {
        $this->expectException(\InvalidArgumentException::class);

        Response::file('/no/such/file');
    }

    public function testStreamDownload(): void {
        $state = self::state(Response::stream_download(fn() => null, 'a.csv', 202));

        $this->assertSame(202, $state['status']);
        $this->assertSame('application/octet-stream', $state['headers']['Content-Type']);
        $this->assertSame('attachment; filename="a.csv"', $state['headers']['Content-Disposition']);
    }
}
