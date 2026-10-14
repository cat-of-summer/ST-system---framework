<?php

namespace ST_system\Captcha\Drivers\Game\FruitNinja;

use ST_system\Captcha\Drivers\Game\Simulation;

/** Fruit Ninja. Зеркало src/sim.js — правила и коды ввода описаны там. */
final class FruitNinjaSimulation extends Simulation {

    public const H      = 150;
    public const FP     = 256;
    public const G      = 45;
    public const PACK   = 160;
    public const MAXSEG = 120;
    public const MINSEG = 3;
    public const LIVES  = 3;
    public const STILL  = 3;
    public const KINDS  = 4;
    public const COLORS = 6;

    public int $w = 267;
    public int $ids = 0;
    public array $shapes = [];
    public int $misses = 0;
    public int $next = 15;
    public int $clock = 0;
    public int $down = 0;
    public int $bx = 0;
    public int $by = 0;
    public int $ax = 0;
    public int $ay = 0;
    public int $cuts = 0;
    public int $near = 0;
    public int $pdx = 0;
    public int $pdy = 0;
    public int $still = 0;

    protected function setup(): void {
        $this->w   = (int)($this->params['w'] ?? 0) ?: 267;
        $this->ids = 0;
    }

    protected function restart(): void {
        $this->shapes = [];
        $this->misses = 0;
        $this->next   = 15;
        $this->clock  = 0;
        $this->down   = 0;
        $this->bx     = 0;
        $this->by     = 0;
        $this->ax     = 0;
        $this->ay     = 0;
        $this->cuts   = 0;
        $this->near   = 0;
        $this->pdx    = 0;
        $this->pdy    = 0;
        $this->still  = 0;
    }

    private function wave(): void {
        $count = 1 + $this->rng->int(min(3, 1 + intdiv($this->clock, 240)));

        for ($i = 0; $i < $count; $i++) {
            $r     = 10 + $this->rng->int(4);
            $x     = intdiv($this->w * 15, 100) + $this->rng->int(intdiv($this->w * 70, 100));
            $vx    = ($x < intdiv($this->w, 2) ? 1 : -1) * $this->rng->int(200);
            $vy    = -(1220 + $this->rng->int(480));
            $kind  = $this->rng->int(self::KINDS);
            $color = $this->rng->int(self::COLORS);
            $spin  = $this->rng->int(13) - 6;

            $this->shapes[] = [$this->ids++, $x * self::FP, (self::H + $r) * self::FP, $vx, $vy, $kind, $color, $r, $spin, $this->clock];
        }

        $this->next = 28 + $this->rng->int(24) - min(10, intdiv($this->clock, 60));
    }

    public static function dist2(array $s, int $ax, int $ay, int $bx, int $by): int {
        $x0   = $ax * self::FP;
        $y0   = $ay * self::FP;
        $dx   = ($bx - $ax) * self::FP;
        $dy   = ($by - $ay) * self::FP;
        $len2 = $dx * $dx + $dy * $dy;
        $t    = ($s[1] - $x0) * $dx + ($s[2] - $y0) * $dy;
        if ($t < 0) $t = 0;
        if ($t > $len2) $t = $len2;

        $px = $x0 + ($len2 ? intdiv($dx * $t, $len2) : 0) - $s[1];
        $py = $y0 + ($len2 ? intdiv($dy * $t, $len2) : 0) - $s[2];
        return $px * $px + $py * $py;
    }

    public static function reach(array $s): int {
        return intdiv($s[7] * self::FP * 5, 4);
    }

    public static function near2(array $s, int $ax, int $ay, int $bx, int $by): int {
        $now  = self::dist2($s, $ax, $ay, $bx, $by);
        $next = self::dist2([$s[0], $s[1] + $s[3], $s[2] + $s[4] + self::G], $ax, $ay, $bx, $by);
        return min($now, $next);
    }

    private function stroke(): void {
        if ($this->near || $this->cuts) $this->intent($this->cuts > 0);
        $this->cuts = $this->near = 0;
    }

    private function forget(): void {
        $this->pdx = $this->pdy = 0;
    }

    protected function handle(int $code, int $value): void {
        if ($code === 1) {
            $this->stroke();
            $this->forget();
            $this->down = 0;
            return;
        }
        if ($code !== 0 || $value < 0) return;

        $x = min($this->w, intdiv($value, self::PACK));
        $y = min(self::H, $value % self::PACK);

        if ($this->down) {
            $dx   = $x - $this->bx;
            $dy   = $y - $this->by;
            $len2 = $dx * $dx + $dy * $dy;

            if ($len2 > self::MAXSEG * self::MAXSEG) {
                $this->stroke();
                $this->forget();
            } elseif ($len2 > 0) {
                $dot   = $this->pdx * $dx + $this->pdy * $dy;
                $plen2 = $this->pdx * $this->pdx + $this->pdy * $this->pdy;
                $along = $plen2 > 0 && $dot > 0 && 4 * $dot * $dot >= $plen2 * $len2;

                if ($len2 < self::MINSEG * self::MINSEG) {
                    $this->stroke();
                } else {
                    $this->ax = $this->bx;
                    $this->ay = $this->by;
                    $this->slice($this->bx, $this->by, $x, $y, $along);
                }

                $this->pdx = $dx;
                $this->pdy = $dy;
            }
        }

        $this->bx    = $x;
        $this->by    = $y;
        $this->down  = 1;
        $this->still = 0;
    }

    private function slice(int $ax, int $ay, int $bx, int $by, bool $cut): void {
        $keep = [];

        foreach ($this->shapes as $s) {
            $d2 = self::near2($s, $ax, $ay, $bx, $by);
            $r  = self::reach($s);

            if ($cut && $d2 <= $r * $r) {
                $this->cuts++;
                $this->score();
                continue;
            }
            if ($d2 <= 9 * $r * $r) $this->near = 1;
            $keep[] = $s;
        }

        $this->shapes = $keep;
    }

    protected function update(): void {
        $this->clock++;
        if ($this->down && ++$this->still === self::STILL) {
            $this->stroke();
            $this->forget();
        }
        if (--$this->next <= 0) $this->wave();

        $keep = [];
        foreach ($this->shapes as $s) {
            $s[4] += self::G;
            $s[1] += $s[3];
            $s[2] += $s[4];

            if ($s[4] > 0 && $s[2] > (self::H + $s[7]) * self::FP) {
                $this->misses++;
                continue;
            }
            $keep[] = $s;
        }
        $this->shapes = $keep;

        if ($this->misses >= self::LIVES) $this->lose();
    }

    protected function dump(): array {
        $out = [$this->w, $this->ids, $this->clock, $this->next, $this->misses, $this->down, $this->bx, $this->by, $this->ax, $this->ay,
            $this->cuts, $this->near, $this->pdx, $this->pdy, $this->still, count($this->shapes)];
        foreach ($this->shapes as $s) foreach ($s as $v) $out[] = $v;
        return $out;
    }
}
