<?php

namespace ST_system\Tests\Unit\Captcha\Game;

use ST_system\Captcha\Drivers\Game\IntMath;
use ST_system\Tests\TestCase;

/** IntMath — зеркало assets/engine/src/imath.js; значения те же, что в engine.test.js. */
final class IntMathTest extends TestCase {

    public function testSinFromTable(): void {
        $this->assertSame([0, 1024, 0, -1024, 1024, -1024], [
            IntMath::sin(0), IntMath::sin(64), IntMath::sin(128), IntMath::sin(192), IntMath::sin(256 + 64), IntMath::sin(-64),
        ]);
        $this->assertSame(1024, IntMath::cos(0));
        $this->assertSame(724, IntMath::sin(32));
    }

    public function testSqrt(): void {
        foreach ([0, 1, 2, 15, 16, 17, 99, 100, 123456789, 1000000000000] as $n)
            $this->assertSame((int)floor(sqrt($n)), IntMath::sqrt($n), "sqrt {$n}");
    }

    public function testAtan2(): void {
        for ($a = 0; $a < 256; $a++)
            $this->assertSame($a, IntMath::atan2(IntMath::sin($a) * 100, IntMath::cos($a) * 100), "угол {$a}");

        $this->assertSame([0, 64, 128, 192, 0], [
            IntMath::atan2(0, 5), IntMath::atan2(5, 0), IntMath::atan2(0, -5), IntMath::atan2(-5, 0), IntMath::atan2(0, 0),
        ]);
    }

    public function testAngleDifference(): void {
        $this->assertSame(11, IntMath::adiff(250, 5));
        $this->assertSame(-11, IntMath::adiff(5, 250));
    }
}
