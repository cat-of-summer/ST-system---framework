<?php

namespace ST_system\Captcha\Drivers\Image;

/**
 * Контуры кусочков настоящего пазла. Каждая линия разреза — цепочка стыков по схеме классических
 * генераторов (Draradech): на стык три кубические кривые с пятью случайными смещениями — голова
 * язычка сдвинута вдоль стыка, шейка и голова разной ширины и с перекосом, своя высота, волнистые
 * участки до и после. Направление (язычок или выемка) случайно, а соседние стыки одной линии
 * гладко продолжают друг друга. Поэтому каждая пара кусочков сочетается единственным образом.
 *
 * Обе стороны стыка берут одну и ту же кривую, так что кусочки входят друг в друга без зазора.
 */
final class Jigsaw {

    /** Высота язычка (доля стороны) и разброс смещений — как у классических генераторов. */
    private const TAB    = [0.085, 0.105];
    private const JITTER = 0.04;
    private const STEPS  = 12;

    /** Насколько язычок может выйти за сторону клетки — под это поле «на вылет» в спрайте. */
    public const REACH = 0.36;

    /**
     * Контуры кусочков rows × cols (клетка cw × ch) в координатах ячейки спрайта: клетка кусочка
     * начинается в (bleed, bleed). Ключ — номер кусочка (построчно), значение — многоугольник
     * [[x, y], …] по часовой стрелке; кривые разбиты на отрезки.
     *
     * @return array<int, array<int, array{0:float,1:float}>>
     */
    public static function outlines(int $rows, int $cols, float $cw, float $ch, float $bleed): array {
        $size = min($cw, $ch);

        // h[r][c] — стык между рядами r−1 и r в столбце c (r = 1..rows−1), в долях: (u, v).
        // v[c][r] — стык между столбцами c−1 и c в ряду r (c = 1..cols−1).
        $h = [];
        for ($r = 1; $r < $rows; $r++) $h[$r] = self::line($cols);

        $v = [];
        for ($c = 1; $c < $cols; $c++) $v[$c] = self::line($rows);

        $out = [];

        for ($r = 0; $r < $rows; $r++)
            for ($c = 0; $c < $cols; $c++) {
                $points = [[$bleed, $bleed]];

                // Верх: слева направо по линии r.
                foreach ($r > 0 ? $h[$r][$c] : [[1.0, 0.0]] as [$u, $w])
                    $points[] = [$bleed + $u * $cw, $bleed + $w * $size];

                // Право: сверху вниз по линии c + 1.
                foreach ($c < $cols - 1 ? $v[$c + 1][$r] : [[1.0, 0.0]] as [$u, $w])
                    $points[] = [$bleed + $cw + $w * $size, $bleed + $u * $ch];

                // Низ: справа налево по линии r + 1.
                foreach (self::reversed($r < $rows - 1 ? $h[$r + 1][$c] : [[1.0, 0.0]]) as [$u, $w])
                    $points[] = [$bleed + $u * $cw, $bleed + $ch + $w * $size];

                // Лево: снизу вверх по линии c.
                foreach (self::reversed($c > 0 ? $v[$c][$r] : [[1.0, 0.0]]) as [$u, $w])
                    $points[] = [$bleed + $w * $size, $bleed + $u * $ch];

                array_pop($points);
                $out[$r * $cols + $c] = $points;
            }

        return $out;
    }

    /** Поле «на вылет» под язычки для клетки cw × ch. */
    public static function bleed(float $cw, float $ch): int {
        return (int)ceil(min($cw, $ch) * self::REACH);
    }

    /**
     * Одна линия разреза из $count стыков. Каждый стык — точки (u, v) от (0, 0) до (1, 0):
     * u — вдоль стыка, v — поперёк, в долях стороны клетки.
     */
    private static function line(int $count): array {
        $edges = [];
        $e     = self::uniform(-self::JITTER, self::JITTER);
        $flip  = random_int(0, 1) === 1;

        for ($i = 0; $i < $count; $i++) {
            $was  = $flip;
            $flip = random_int(0, 1) === 1;
            $a    = $flip === $was ? -$e : $e;
            $b    = self::uniform(-self::JITTER, self::JITTER);
            $c    = self::uniform(-self::JITTER, self::JITTER);
            $d    = self::uniform(-self::JITTER, self::JITTER);
            $e    = self::uniform(-self::JITTER, self::JITTER);
            $t    = self::uniform(self::TAB[0], self::TAB[1]);
            $s    = $flip ? -1.0 : 1.0;

            $p = [
                [0.0,                     0.0],
                [0.2,                     $a],
                [0.5 + $b + $d,           -$t + $c],
                [0.5 - $t + $b,           $t + $c],
                [0.5 - 2 * $t + $b - $d,  3 * $t + $c],
                [0.5 + 2 * $t + $b - $d,  3 * $t + $c],
                [0.5 + $t + $b,           $t + $c],
                [0.5 + $b + $d,           -$t + $c],
                [0.8,                     $e],
                [1.0,                     0.0],
            ];

            foreach ($p as &$point) $point[1] *= $s;
            unset($point);

            $edges[] = array_merge(
                self::bezier($p[0], $p[1], $p[2], $p[3]),
                self::bezier($p[3], $p[4], $p[5], $p[6]),
                self::bezier($p[6], $p[7], $p[8], $p[9])
            );
        }

        return $edges;
    }

    /** Точки кубической кривой без начальной. */
    private static function bezier(array $p0, array $p1, array $p2, array $p3): array {
        $out = [];

        for ($i = 1; $i <= self::STEPS; $i++) {
            $t  = $i / self::STEPS;
            $mt = 1 - $t;
            $k0 = $mt * $mt * $mt;
            $k1 = 3 * $mt * $mt * $t;
            $k2 = 3 * $mt * $t * $t;
            $k3 = $t * $t * $t;

            $out[] = [
                $k0 * $p0[0] + $k1 * $p1[0] + $k2 * $p2[0] + $k3 * $p3[0],
                $k0 * $p0[1] + $k1 * $p1[1] + $k2 * $p2[1] + $k3 * $p3[1],
            ];
        }

        return $out;
    }

    /** Стык в обратную сторону: точки от (1, 0) к (0, 0) без начальной. */
    private static function reversed(array $edge): array {
        $points = array_merge([[0.0, 0.0]], $edge);
        array_pop($points);

        return array_map(static fn(array $p): array => [$p[0], $p[1]], array_reverse($points));
    }

    private static function uniform(float $min, float $max): float {
        return $min + ($max - $min) * random_int(0, 1000000) / 1000000;
    }
}
