import { idiv } from './rng.js';

/**
 * Целочисленная геометрия для физики в фиксированной точке. Зеркало Game/IntMath.php: углы —
 * 256 делений круга (0 — вправо, 64 — вниз, экранная ось y), синус и косинус ×1024 из таблицы,
 * а не Math.sin — последний бит у разных движков JS и PHP мог бы разойтись.
 */
export const ONE = 1024;

// Четверть волны: round(1024 · sin(i · 2π / 256)), i = 0…64.
const QUARTER = [
    0, 25, 50, 75, 100, 125, 150, 175, 200, 224, 249, 273, 297, 321, 345, 369, 392, 415, 438, 460,
    483, 505, 526, 548, 569, 590, 610, 630, 650, 669, 688, 706, 724, 742, 759, 775, 792, 807, 822,
    837, 851, 865, 878, 891, 903, 915, 926, 936, 946, 955, 964, 972, 980, 987, 993, 999, 1004, 1009,
    1013, 1016, 1019, 1021, 1023, 1024, 1024,
];

/** sin угла a (0…255, любое целое приводится по модулю) ×1024. */
export function isin(a) {
    a = ((a % 256) + 256) % 256;
    if (a <= 64)  return QUARTER[a];
    if (a <= 128) return QUARTER[128 - a];
    if (a <= 192) return -QUARTER[a - 128];
    return -QUARTER[256 - a];
}

export function icos(a) {
    return isin(a + 64);
}

/** ⌊√n⌋ для n ≥ 0 — Ньютоном в целых. */
export function isqrt(n) {
    if (n <= 0) return 0;

    let x = n;
    let y = idiv(x + 1, 2);
    while (y < x) {
        x = y;
        y = idiv(x + idiv(n, x), 2);
    }
    return x;
}

/** Длина вектора (x, y), округлённая вниз. */
export function ilen(x, y) {
    return isqrt(x * x + y * y);
}

/**
 * Угол вектора (x, y) в делениях круга 0…255. В пределах октанта — двоичный поиск по таблице:
 * ближайший a, у которого tan(a) ≈ |y| / |x|.
 */
export function iatan2(y, x) {
    if (x === 0 && y === 0) return 0;

    const ax = Math.abs(x), ay = Math.abs(y);
    const swap = ay > ax;
    const p = swap ? ax : ay, q = swap ? ay : ax;

    // Наибольший a в 0…32, у которого p · cos(a) ≥ q · sin(a), и соседний — какой ближе.
    let lo = 0, hi = 32;
    while (lo < hi) {
        const mid = idiv(lo + hi + 1, 2);
        if (p * QUARTER[64 - mid] >= q * QUARTER[mid]) lo = mid;
        else hi = mid - 1;
    }
    if (lo < 32 && Math.abs(p * QUARTER[64 - lo] - q * QUARTER[lo]) > Math.abs(q * QUARTER[lo + 1] - p * QUARTER[63 - lo])) lo++;

    let a = swap ? 64 - lo : lo;
    if (x < 0) a = 128 - a;
    if (y < 0) a = 256 - a;

    return a & 255;
}

/** Разница углов b − a, приведённая к −128…127. */
export function adiff(a, b) {
    return ((b - a) % 256 + 256 + 128) % 256 - 128;
}
