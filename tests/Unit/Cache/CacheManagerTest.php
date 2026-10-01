<?php

namespace ST_system\Tests\Unit\Cache;

use ST_system\Cache\CacheManager;
use ST_system\Cache\Drivers\FileSystemCacheDriver;
use ST_system\Tests\TestCase;

final class CacheManagerTest extends TestCase {

    private function key(string $name): string {
        return $name.'-'.bin2hex(random_bytes(4));
    }

    public function testDefaultsComeFromConfig(): void {
        $this->assertSame('~/cache/', CacheManager::config('default.dir'));
        $this->assertSame(FileSystemCacheDriver::class, CacheManager::config('drivers.default'));
    }

    public function testMakeUsesDefaultDriver(): void {
        $cache = CacheManager::make('k', ['dir' => $this->tmpDir()]);

        $this->assertInstanceOf(CacheManager::class, $cache);
        $this->assertTrue($cache->isAvailable());
        $this->assertSame('k', $cache->raw_key);
        $this->assertTrue(isset($cache->dir));
    }

    public function testDriverByAliasAndFallbackToDefault(): void {
        $dir = $this->tmpDir();

        $this->assertStringEndsWith('/data', CacheManager::make('k', ['driver' => 'filesystem', 'dir' => $dir])->file);

        // Database-драйвер без настроек соединения недоступен — менеджер падает обратно на filesystem.
        $cache = CacheManager::make('k', ['driver' => 'database', 'dir' => $dir]);
        $this->assertStringStartsWith($dir, $cache->dir);
    }

    public function testUnknownDriverClassThrows(): void {
        $this->expectException(\InvalidArgumentException::class);

        CacheManager::make('k', ['driver' => \stdClass::class]);
    }

    public function testStaticShortcutsFromDocs(): void {
        $key = $this->key('static');

        CacheManager::set($key, ['a' => 1], 'file', 600);

        $this->assertSame(['a' => 1], CacheManager::get($key, 'file'));
        $this->assertNull(CacheManager::get($key));

        $meta = CacheManager::getMeta($key, 'file');
        $this->assertGreaterThan(time() + 590, $meta['expires_in']);

        CacheManager::setMeta($key, ['a' => 1]);
        $this->assertSame(1, CacheManager::getMeta($key)['a']);
    }

    public function testStaticRememberUsesGivenFileAndTtl(): void {
        $key   = $this->key('remember');
        $calls = 0;
        $cb    = function () use (&$calls) { return 'computed-'.(++$calls); };

        $this->assertSame('computed-1', CacheManager::remember($key, $cb, 'file', 600));
        $this->assertSame('computed-1', CacheManager::remember($key, $cb, 'file', 600));
        $this->assertSame(1, $calls);

        $this->assertSame('computed-1', CacheManager::get($key, 'file'));
        $this->assertGreaterThan(time() + 590, CacheManager::getMeta($key, 'file')['expires_in']);
    }

    public function testRememberWithStampRecomputesOnChange(): void {
        $cache = CacheManager::make($this->key('stamp'), ['dir' => $this->tmpDir()]);
        $calls = 0;
        $cb    = function () use (&$calls) { return ++$calls; };

        $this->assertSame(1, $cache->remember($cb, 60, 'f', 'v1'));
        $this->assertSame(1, $cache->remember($cb, 60, 'f', 'v1'));
        $this->assertSame(2, $cache->remember($cb, 60, 'f', 'v2'));
        $this->assertSame('v2', $cache->getMeta('f')['stamp']);
    }

    public function testInstanceMakeSpawnsSibling(): void {
        $cache   = CacheManager::make($this->key('parent'), ['dir' => $this->tmpDir(), 'ttl' => 60]);
        $sibling = $cache->make($this->key('child'), ['ttl' => 5]);

        $this->assertSame(5, $sibling->ttl);
        $this->assertSame($cache->base_dir, $sibling->base_dir);
        $this->assertNotSame($cache->dir, $sibling->dir);
    }

    public function testUnknownStaticMethodThrows(): void {
        $this->expectException(\BadMethodCallException::class);

        CacheManager::nothing('k');
    }
}
