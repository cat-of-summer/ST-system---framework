/**
 * xorshift32 — тот же генератор, что Game/Rng.php. Без умножения: за 2^53 JS теряет точность,
 * а сдвиги и XOR в обоих языках дают одинаковые 32 бита.
 */
export class Rng {

    constructor(seed) {
        this.s = (seed >>> 0) || 0x9E3779B9;
    }

    next() {
        let s = this.s;
        s = (s ^ (s << 13)) >>> 0;
        s = (s ^ (s >>> 17)) >>> 0;
        s = (s ^ (s << 5)) >>> 0;
        return this.s = s;
    }

    /** Целое 0..n-1. */
    int(n) {
        return this.next() % n;
    }
}

/** Деление с усечением к нулю — как intdiv в PHP. */
export function idiv(a, b) {
    return Math.trunc(a / b);
}
