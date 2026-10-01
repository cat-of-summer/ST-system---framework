<?php

namespace ST_system\Tests\Unit\Cache;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Database\SqliteAdapter;
use ST_system\Cache\Drivers\DatabaseCacheDriver;

/** Контракт на SQLite: единственная БД, которую можно поднять без сервера. */
final class DatabaseCacheDriverTest extends CacheDriverContractTest {

    private string $database;

    protected function setUp(): void {
        if (!SqliteAdapter::isAvailable())
            $this->markTestSkipped('pdo_sqlite не установлен');

        // Пул соединений держит одну :memory:-базу на процесс; disconnect() в tearDown даёт
        // каждому тесту чистую. Файл на диске здесь не нужен и на fsync заметно медленнее.
        $this->database = ':memory:';
    }

    protected function tearDown(): void {
        DatabaseCacheDriver::disconnect();

        parent::tearDown();
    }

    protected function makeDriver($key, array $config = []): CacheDriver {
        return new DatabaseCacheDriver($key, $config + [
            'engine'   => 'sqlite',
            'database' => $this->database,
            'ttl'      => 60,
        ]);
    }

    public function testInjectedConnection(): void {
        $adapter = SqliteAdapter::connect(['engine' => 'sqlite', 'database' => ':memory:']);
        $driver  = new DatabaseCacheDriver('injected', ['connection' => $adapter, 'ttl' => 60]);

        $driver->set('in memory');

        $this->assertSame('in memory', (new DatabaseCacheDriver('injected', ['connection' => $adapter]))->get());
    }

    public function testUnavailableWithoutConnectionSettings(): void {
        $this->assertFalse((new DatabaseCacheDriver('nothing', ['engine' => null]))->isAvailable());
        $this->assertFalse((new DatabaseCacheDriver('nothing', ['engine' => 'mysql', 'database' => 'x']))->isAvailable());
    }

    public function testDisconnectReopensPooledConnection(): void {
        $this->database = $this->tmpDir('sqlite').'/cache.sqlite';

        $driver = $this->makeDriver('reconnect');
        $driver->set('kept', 60);

        DatabaseCacheDriver::disconnect();

        $this->assertSame('kept', $this->makeDriver('reconnect')->get());
        $this->assertTrue($driver->isAvailable());
    }

    /** @dataProvider globCases */
    public function testGlobToLike(string $glob, string $like): void {
        $this->assertSame($like, self::callPrivate(SqliteAdapter::class, 'globToLike', $glob));
    }

    public function globCases(): array {
        return [
            ['cache:*', 'cache:%'],
            ['a?c', 'a_c'],
            ['100%_x*', '100\%\_x%'],
        ];
    }
}
