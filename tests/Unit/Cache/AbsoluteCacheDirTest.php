<?php

namespace ST_system\Tests\Unit\Cache;

use ST_system\Access;
use ST_system\Cache\CacheManager;
use ST_system\HTTP\WebClient;
use ST_system\Storage\File;
use ST_system\View;
use ST_system\Tests\TestCase;

/**
 * Абсолютный CacheManager default.dir: каталоги кеша классов строятся от него и остаются
 * абсолютными. Конфиг классов неизменяем в пределах процесса, поэтому - отдельный процесс.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AbsoluteCacheDirTest extends TestCase {

    public function testClassCacheDirsStayAbsolute(): void {
        $base = ST_TESTS_ROOT.'/abs-cache';
        CacheManager::setConfig(['default' => ['dir' => $base]]);

        $this->assertSame($base.'/File', File::config('cache.dir'));
        $this->assertSame($base.'/WebClient', WebClient::config('cache.dir'));

        $this->assertSame($base.'/View', View::config('cache.dir'));
        $this->assertSame($base.'/Access', Access::config('firewall.dir'));
    }

    public function testFileCacheLandsUnderAbsoluteDir(): void {
        $base = ST_TESTS_ROOT.'/abs-cache';
        CacheManager::setConfig(['default' => ['dir' => $base]]);

        CacheManager::set('key', ['ok' => true]);

        $this->assertSame(['ok' => true], CacheManager::get('key'));
        $this->assertNotEmpty(glob($base.'/*'));
    }
}
