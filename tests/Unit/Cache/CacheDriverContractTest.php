<?php

namespace ST_system\Tests\Unit\Cache;

use ST_system\Cache\CacheDriver;
use ST_system\Tests\TestCase;

/**
 * Общий контракт драйверов кэша. Каждый драйвер, который можно поднять офлайн,
 * наследует этот класс и отдаёт свой экземпляр из makeDriver().
 */
abstract class CacheDriverContractTest extends TestCase {

    abstract protected function makeDriver($key, array $config = []): CacheDriver;

    /** Свежий экземпляр на тот же ключ — чтобы читать из хранилища, а не из памяти объекта. */
    private function reopen(CacheDriver $driver, array $config = []): CacheDriver {
        return $this->makeDriver($driver->raw_key, $config);
    }

    public function testIsAvailable(): void {
        $this->assertTrue($this->makeDriver('available')->isAvailable());
    }

    /** @dataProvider valueCases */
    public function testValuesRoundTripThroughStorage($value, $expected): void {
        $driver = $this->makeDriver(['type', bin2hex(random_bytes(4))]);
        $driver->set($value, 60);

        $this->assertEquals($expected, $this->reopen($driver)->get());
    }

    public function valueCases(): array {
        $object = new \stdClass();
        $object->a = 1;

        return [
            'string' => ['текст', 'текст'],
            'int'    => [42, 42],
            'float'  => [1.5, 1.5],
            'true'   => [true, true],
            'false'  => [false, false],
            'array'  => [['a' => [1, 2], 'b' => 'в'], ['a' => [1, 2], 'b' => 'в']],
            'object' => [$object, $object],
            'null'   => [null, ''],
        ];
    }

    public function testMissingKeyReturnsNull(): void {
        $driver = $this->makeDriver('missing-'.bin2hex(random_bytes(4)));

        $this->assertNull($driver->get());
        $this->assertFalse($driver->exists());
        $this->assertFalse($driver->isValid());
    }

    public function testFilesAreIndependent(): void {
        $driver = $this->makeDriver('files-'.bin2hex(random_bytes(4)));
        $driver->set('one', 60, 'a');
        $driver->set('two', 60, 'b');

        $fresh = $this->reopen($driver);
        $this->assertSame('one', $fresh->get('a'));
        $this->assertSame('two', $fresh->get('b'));
        $this->assertNull($fresh->get('c'));
    }

    public function testExpiredEntryIsNotReturned(): void {
        $driver = $this->makeDriver('expired-'.bin2hex(random_bytes(4)));
        $driver->set('stale', 60);
        $driver->setMeta(['expires_in' => time() - 1]);

        $fresh = $this->reopen($driver);
        $this->assertTrue($fresh->isExpired());
        $this->assertNull($fresh->get());
    }

    public function testTtlMinusOneNeverExpires(): void {
        $driver = $this->makeDriver('forever-'.bin2hex(random_bytes(4)));
        $driver->set('forever', -1);

        $fresh = $this->reopen($driver);
        $this->assertSame(-1, $fresh->getMeta()['expires_in']);
        $this->assertFalse($fresh->isExpired());
        $this->assertSame('forever', $fresh->get());
    }

    public function testDefaultTtlFromConfig(): void {
        $driver = $this->makeDriver('default-ttl-'.bin2hex(random_bytes(4)), ['ttl' => 120]);
        $driver->set('x');

        $expires = $this->reopen($driver)->getMeta()['expires_in'];
        $this->assertGreaterThanOrEqual(time() + 119, $expires);
        $this->assertLessThanOrEqual(time() + 120, $expires);
    }

    public function testMetaIsMergedAndStamped(): void {
        $driver = $this->makeDriver('meta-'.bin2hex(random_bytes(4)));
        $driver->set('x', 60, '', ['stamp' => 'v1']);
        $driver->setMeta(['extra' => 1]);

        $meta = $this->reopen($driver)->getMeta();
        $this->assertSame('string', $meta['type']);
        $this->assertSame('v1', $meta['stamp']);
        $this->assertSame(1, $meta['extra']);
        $this->assertArrayHasKey('modified_at', $meta);

        $driver->setMeta(['only' => true], 0, false);
        $this->assertArrayNotHasKey('stamp', $this->reopen($driver)->getMeta());
    }

    public function testPurgeRemovesOnlyOwnKey(): void {
        $a = $this->makeDriver('purge-a-'.bin2hex(random_bytes(4)));
        $b = $this->makeDriver('purge-b-'.bin2hex(random_bytes(4)));
        $a->set('a', 60);
        $b->set('b', 60);

        $a->purge();

        $this->assertNull($this->reopen($a)->get());
        $this->assertSame('b', $this->reopen($b)->get());
    }

    public function testPurgeExpiredKeepsLiveEntries(): void {
        $live = $this->makeDriver('live-'.bin2hex(random_bytes(4)));
        $dead = $this->makeDriver('dead-'.bin2hex(random_bytes(4)));
        $live->set('live', 60);
        $dead->set('dead', 60);
        $dead->setMeta(['expires_in' => time() - 10]);

        $live->purgeExpiredBase();

        $this->assertSame('live', $this->reopen($live)->get());
        $this->assertFalse($this->reopen($dead)->exists());
    }

    public function testPurgeBaseRemovesEverything(): void {
        $a = $this->makeDriver('base-a-'.bin2hex(random_bytes(4)));
        $a->set('a', 60);

        $this->makeDriver('base-b')->purgeBase();

        $this->assertNull($this->reopen($a)->get());
    }

    public function testSpawnSwitchesKeyAndOverrides(): void {
        $driver = $this->makeDriver('spawn-parent', ['ttl' => 60]);
        $child  = $driver->spawn('spawn-child-'.bin2hex(random_bytes(4)), ['file' => 'f', 'ttl' => 30]);

        $child->set('child');

        $this->assertSame(30, $child->ttl);
        $this->assertSame('child', $this->reopen($child, ['file' => 'f'])->get());
        $this->assertNull($this->reopen($driver)->get('f'));
    }
}
