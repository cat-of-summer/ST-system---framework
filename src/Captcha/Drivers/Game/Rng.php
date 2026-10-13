<?php

namespace ST_system\Captcha\Drivers\Game;

/**
 * xorshift32: тот же генератор, что engine/src/rng.js. Без умножения — в JS оно теряет
 * точность за 2^53, а сдвиги и XOR в обоих языках дают одинаковые 32 бита.
 */
final class Rng {

    public int $s;

    public function __construct(int $seed) {
        $this->s = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    public function next(): int {
        $s = $this->s;
        $s = ($s ^ ($s << 13)) & 0xFFFFFFFF;
        $s =  $s ^ ($s >> 17);
        $s = ($s ^ ($s << 5))  & 0xFFFFFFFF;

        return $this->s = $s;
    }

    /** Целое 0..n-1. */
    public function int(int $n): int {
        return $this->next() % $n;
    }
}
