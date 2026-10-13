<?php

namespace ST_system\Tests\Unit\Captcha;

use ST_system\Captcha\Drivers\Image\Jigsaw;
use ST_system\Tests\TestCase;

/** Контуры кусочков пазла: стыки совпадают у соседей, форма не выходит за поле, края поля прямые. */
final class JigsawTest extends TestCase {

    private const CW = 73;
    private const CH = 73;

    private static function near(array $a, array $b, float $eps = 1e-9): bool {
        return abs($a[0] - $b[0]) < $eps && abs($a[1] - $b[1]) < $eps;
    }

    /** Точки контура кусочка на заданной стороне его клетки (по x или y в координатах клетки). */
    private static function side(array $outline, string $axis, float $value, float $bleed): array {
        $i = $axis === 'x' ? 0 : 1;

        return array_values(array_filter($outline, static fn(array $p): bool => abs($p[$i] - $value) < 1e-9));
    }

    public function testNeighboursShareTheSameCurve(): void {
        $b   = Jigsaw::bleed(self::CW, self::CH);
        $all = Jigsaw::outlines(3, 3, self::CW, self::CH, $b);

        // Перевод в общие координаты поля: кусочек (r, c) сдвинут на (c·cw, r·ch).
        $global = static function (int $piece, array $points): array {
            $r = intdiv($piece, 3);
            $c = $piece % 3;
            return array_map(static fn(array $p): string => sprintf('%.6f,%.6f', $p[0] + $c * self::CW, $p[1] + $r * self::CH), $points);
        };

        for ($r = 0; $r < 3; $r++)
            for ($c = 0; $c < 3; $c++) {
                $piece = $r * 3 + $c;
                $mine  = array_flip($global($piece, $all[$piece]));

                if ($c < 2) {
                    $right  = $global($piece + 1, $all[$piece + 1]);
                    $shared = count(array_filter($right, static fn(string $p): bool => isset($mine[$p])));
                    $this->assertGreaterThan(30, $shared, "стык ($r,$c)–($r,".($c + 1).') — одна кривая');
                }

                if ($r < 2) {
                    $below  = $global($piece + 3, $all[$piece + 3]);
                    $shared = count(array_filter($below, static fn(string $p): bool => isset($mine[$p])));
                    $this->assertGreaterThan(30, $shared, "стык ($r,$c)–(".($r + 1).",$c) — одна кривая");
                }
            }
    }

    public function testShapeStaysInsideBleed(): void {
        $b = Jigsaw::bleed(self::CW, self::CH);

        for ($i = 0; $i < 20; $i++)
            foreach (Jigsaw::outlines(4, 4, self::CW, self::CH, $b) as $outline)
                foreach ($outline as [$x, $y]) {
                    $this->assertGreaterThanOrEqual(0, $x);
                    $this->assertGreaterThanOrEqual(0, $y);
                    $this->assertLessThanOrEqual(self::CW + 2 * $b, $x);
                    $this->assertLessThanOrEqual(self::CH + 2 * $b, $y);
                }
    }

    public function testBorderEdgesAreStraight(): void {
        $b   = Jigsaw::bleed(self::CW, self::CH);
        $all = Jigsaw::outlines(3, 3, self::CW, self::CH, $b);

        // Угол (0,0): верх и лево — по линии клетки, без язычков.
        $corner = $all[0];
        $top    = array_filter($corner, static fn(array $p): bool => $p[1] < $b - 1e-9);
        $left   = array_filter($corner, static fn(array $p): bool => $p[0] < $b - 1e-9);
        $this->assertCount(0, $top, 'над верхним краем поля ничего нет');
        $this->assertCount(0, $left, 'левее левого края поля ничего нет');

        // Середина (1,1): язычки или выемки есть со всех сторон.
        $middle = $all[4];
        $this->assertNotEmpty(array_filter($middle, static fn(array $p): bool => abs($p[1] - $b) > 5 && $p[0] > $b + 20 && $p[0] < $b + 53 && $p[1] < $b + 30));
    }

    public function testEveryJointIsUnique(): void {
        $b      = Jigsaw::bleed(self::CW, self::CH);
        $shapes = [];

        foreach (Jigsaw::outlines(4, 4, self::CW, self::CH, $b) as $outline)
            $shapes[] = md5(json_encode(array_map(static fn(array $p): array => [round($p[0], 2), round($p[1], 2)], $outline)));

        $this->assertCount(16, array_unique($shapes), 'кусочки не повторяют друг друга');
    }
}
