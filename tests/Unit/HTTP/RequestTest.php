<?php

namespace ST_system\Tests\Unit\HTTP;

use ST_system\HTTP\Request;
use ST_system\Tests\TestCase;

final class RequestTest extends TestCase {

    private array $server;

    protected function setUp(): void {
        $this->server = $_SERVER;

        $_SERVER = array_merge($_SERVER, [
            'REQUEST_URI'     => '/catalog/items?page=2',
            'REQUEST_METHOD'  => 'POST',
            'HTTP_HOST'       => 'example.com',
            'SERVER_PORT'     => '443',
            'HTTPS'           => 'on',
            'HTTP_X_API_KEY'  => 'secret',
            'HTTP_ACCEPT'     => 'application/json',
        ]);
        $_GET    = ['page' => '2', 'q' => ' term '];
        $_POST   = ['name' => 'Ann', 'age' => '30'];
        $_COOKIE = ['sid' => 'abc'];
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;
        $_GET = $_POST = $_COOKIE = [];

        parent::tearDown();
    }

    public function testUrlParts(): void {
        $request = Request::fetch();

        $this->assertSame('/catalog/items', $request->uri());
        $this->assertSame('https', $request->scheme());
        $this->assertSame('https://example.com', $request->origin());
        $this->assertSame('https://example.com/catalog/items', $request->url());
        $this->assertSame('443', $request->port());
        $this->assertSame('POST', $request->method());
    }

    public function testMethodOverrideFromPost(): void {
        $_POST['_method'] = 'DELETE';

        $this->assertSame('DELETE', Request::fetch()->method());
    }

    public function testInputSources(): void {
        $request = Request::fetch(['id' => '5']);

        $this->assertSame('2', $request->get('page'));
        $this->assertSame('Ann', $request->post('name'));
        $this->assertSame('abc', $request->cookie('sid'));
        $this->assertSame(['id' => '5'], $request->query());
        $this->assertSame('5', $request->data('id'));
        $this->assertSame(['page', 'q', 'name', 'age', 'id'], array_keys($request->data()));
        $this->assertNull($request->get('missing'));
    }

    public function testHeaders(): void {
        $headers = Request::fetch()->headers();

        $this->assertSame('secret', $headers['X-Api-Key']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('example.com', $headers['Host']);
    }

    public function testStaticAccessUsesLastFetchedInstance(): void {
        Request::fetch(['id' => '9']);

        $this->assertSame('9', Request::query('id'));
        $this->assertSame('/catalog/items', Request::uri());
    }

    public function testValidateCoercesAndWritesBack(): void {
        $request = Request::fetch();

        $errors = $request->validate(['age' => 'required|int', 'q' => 'string|trim', 'name' => 'required|string']);

        $this->assertSame([], $errors);
        $this->assertSame(30, $request->post('age'));
        $this->assertSame('term', $request->get('q'));
        $this->assertNull($request->get('page'));
    }

    public function testCheckReportsErrorsWithoutChangingData(): void {
        $request = Request::fetch();

        $this->assertSame(['email.This field is required'], $request->check(['email' => 'required|email']));
        $this->assertSame('30', $request->post('age'));
    }

    public function testThrowableValidation(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('email.This field is required');

        Request::fetch()->throwable()->validate(['email' => 'required|email']);
    }

    public function testSchemaInSubclassValidatesOnFetch(): void {
        $request = TypedRequest::fetch();

        $this->assertSame(30, $request->post('age'));
        $this->assertTrue($request->initialized);
    }

    public function testPrivateHelpersAreNotCallable(): void {
        $this->expectException(\Exception::class);

        Request::fetch()->_nothing();
    }
}

final class TypedRequest extends Request {

    public bool $initialized = false;

    protected function __schema(): array {
        return ['age' => 'required|int'];
    }

    protected function __init(): void {
        $this->initialized = true;
    }
}
