<?php

namespace ST_system\Tests\Unit\Traits;

use ST_system\Tests\TestCase;
use ST_system\Traits\Events\HasEvents;
use ST_system\Traits\Events\HasStaticEvents;

final class EventsTest extends TestCase {

    private function emitter(): object {
        return new class {
            use HasEvents;

            protected static function getReservedEvents(): array {
                return ['internal'];
            }

            public function fireInternal(&...$params) {
                return $this->fire('internal', ...$params);
            }
        };
    }

    public function testListenersRunInOrderAndCanMutateArguments(): void {
        $emitter = $this->emitter();
        $emitter->on('save', function (&$value, $suffix) { $value .= '1'.$suffix; });
        $emitter->on('save', function (&$value) { $value .= '2'; });

        $value  = 'v';
        $suffix = '!';
        $this->assertNull($emitter->trigger('save', $value, $suffix));

        $this->assertSame('v1!2', $value);
    }

    public function testTriggerWithoutListenersReturnsFalse(): void {
        $this->assertFalse($this->emitter()->trigger('nobody'));
    }

    public function testListenersArePerInstance(): void {
        $a = $this->emitter();
        $b = $this->emitter();
        $a->on('e', fn() => null);

        $this->assertNull($a->trigger('e'));
        $this->assertFalse($b->trigger('e'));
    }

    public function testReservedEventsCannotBeTriggeredExternally(): void {
        $emitter = $this->emitter();
        $called = false;
        $emitter->on('internal', function () use (&$called) { $called = true; });

        $emitter->fireInternal();
        $this->assertTrue($called);

        $this->expectException(\LogicException::class);
        $emitter->trigger('internal');
    }

    public function testStaticEvents(): void {
        $class = get_class(new class {
            use HasStaticEvents;
        });

        $log = [];
        $class::on('boot', function (&$log, $name) { $log[] = $name; });

        $name = 'a';
        $this->assertNull($class::trigger('boot', $log, $name));
        $this->assertSame(['a'], $log);
        $this->assertFalse($class::trigger('other'));
    }
}
