<?php

namespace ST_system\Captcha\Drivers\Game;

/**
 * Целочисленная геометрия для физики в фиксированной точке. Зеркало assets/engine/src/imath.js:
 * углы — 256 делений круга (0 — вправо, 64 — вниз, экранная ось y), синус и косинус ×1024 из
 * таблицы, а не sin() — последний бит у PHP и движков JS мог бы разойтись.
 */
final class IntMath {

    public const ONE = 1024;

    /** Четверть волны: round(1024 · sin(i · 2π / 256)), i = 0…64. */
    private const QUARTER = [
        0, 25, 50, 75, 100, 125, 150, 175, 200, 224, 249, 273, 297, 321, 345, 369, 392, 415, 438, 460,
        483, 505, 526, 548, 569, 590, 610, 630, 650, 669, 688, 706, 724, 742, 759, 775, 792, 807, 822,
        837, 851, 865, 878, 891, 903, 915, 926, 936, 946, 955, 964, 972, 980, 987, 993, 999, 1004, 1009,
        1013, 1016, 1019, 1021, 1023, 1024, 1024,
    ];

    /** sin угла a (0…255, любое целое приводится по модулю) ×1024. */
    public static function sin(int $a): int {
        $a = (($a % 256) + 256) % 256;
        if ($a <= 64)  return self::QUARTER[$a];
        if ($a <= 128) return self::QUARTER[128 - $a];
        if ($a <= 192) return -self::QUARTER[$a - 128];
        return -self::QUARTER[256 - $a];
    }

    public static function cos(int $a): int {
        return self::sin($a + 64);
    }

    /** ⌊√n⌋ для n ≥ 0 — Ньютоном в целых. */
    public static function sqrt(int $n): int {
        if ($n <= 0) return 0;

        $x = $n;
        $y = intdiv($x + 1, 2);
        while ($y < $x) {
            $x = $y;
            $y = intdiv($x + intdiv($n, $x), 2);
        }
        return $x;
    }

    /** Длина вектора (x, y), округлённая вниз. */
    public static function len(int $x, int $y): int {
        return self::sqrt($x * $x + $y * $y);
    }

    /**
     * Угол вектора (x, y) в делениях круга 0…255. В пределах октанта — двоичный поиск по таблице:
     * ближайший a, у которого tan(a) ≈ |y| / |x|.
     */
    public static function atan2(int $y, int $x): int {
        if ($x === 0 && $y === 0) return 0;

        $ax   = abs($x);
        $ay   = abs($y);
        $swap = $ay > $ax;
        $p    = $swap ? $ax : $ay;
        $q    = $swap ? $ay : $ax;
        $t    = self::QUARTER;

        $lo = 0;
        $hi = 32;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($p * $t[64 - $mid] >= $q * $t[$mid]) $lo = $mid;
            else $hi = $mid - 1;
        }
        if ($lo < 32 && abs($p * $t[64 - $lo] - $q * $t[$lo]) > abs($q * $t[$lo + 1] - $p * $t[63 - $lo])) $lo++;

        $a = $swap ? 64 - $lo : $lo;
        if ($x < 0) $a = 128 - $a;
        if ($y < 0) $a = 256 - $a;

        return $a & 255;
    }

    /** Разница углов b − a, приведённая к −128…127. */
    public static function adiff(int $a, int $b): int {
        return (($b - $a) % 256 + 256 + 128) % 256 - 128;
    }
}
