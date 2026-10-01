<?php

namespace ST_system\Tests\Unit\Cache;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\FileSystemCacheDriver;

final class FileSystemCacheDriverTest extends CacheDriverContractTest {

    private string $dir;

    protected function setUp(): void {
        $this->dir = $this->tmpDir('fs-cache');
    }

    protected function makeDriver($key, array $config = []): CacheDriver {
        return new FileSystemCacheDriver($key, $config + ['dir' => $this->dir, 'ttl' => 60]);
    }

    public function testLayoutOnDisk(): void {
        $driver = $this->makeDriver('layout');
        $driver->set('x', 60, 'blob');

        $this->assertSame($this->dir, $driver->base_dir);
        $this->assertFileExists("{$driver->dir}/blob");
        $this->assertFileExists("{$driver->dir}/blob.meta");
        $this->assertSame("{$driver->dir}/data", $driver->file);
    }

    public function testDirFromDocumentRoot(): void {
        $driver = new FileSystemCacheDriver('tilde', ['dir' => '~/cache-tilde', 'ttl' => 60]);

        $this->assertSame(ST_TESTS_ROOT.'/cache-tilde', $driver->base_dir);
    }
}
