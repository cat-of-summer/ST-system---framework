<?php

namespace ST_system\Captcha\Drivers\Game\Invaders;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Корабль внизу, противники волнами сверху, изредка бомбы. Коды ввода:
 * 0 — куда вести корабль, 1 — выстрел. Реакция — сбитый противник.
 */
final class InvadersSimulation extends Simulation {

    private const H      = 200;
    private const SHIP_Y = self::H - 20;
    private const SW     = 22;
    private const SH     = 12;
    private const EW     = 14;
    private const EH     = 10;
    private const SX     = 22;
    private const SY     = 18;
    private const TOP    = 18;
    private const PSPEED = 5;
    private const SHOT   = 9;
    private const BOMB   = 4;
    private const STEP   = 4;
    private const DROP   = 8;
    private const BOMBS  = 3;
    private const CALM   = 60;
    private const NEAR   = 60;

    private int $w        = 356;
    private int $px       = 0;
    private int $target   = 0;
    private int $pbx      = 0;
    private int $pby      = -1;
    private array $bombs  = [];
    private int $wave     = 0;
    private int $clock    = 0;
    private int $cols     = 0;
    private int $rows     = 0;
    private array $alive  = [];
    private int $fx       = 0;
    private int $fy       = 0;
    private int $fdir     = 1;

    protected function setup(): void {
        $this->w = (int)($this->params['w'] ?? 0) ?: 356;
    }

    protected function restart(): void {
        $this->px     = intdiv($this->w, 2);
        $this->target = $this->px;
        $this->pbx    = 0;
        $this->pby    = -1;
        $this->bombs  = [];
        $this->wave   = 0;
        $this->clock  = 0;
        $this->formation();
    }

    private function formation(): void {
        $most        = max(3, intdiv($this->w - 40, self::SX));
        $this->cols  = min($most, 4 + $this->wave);
        $this->rows  = min(4, 2 + intdiv($this->wave, 2));
        $this->alive = array_fill(0, $this->cols * $this->rows, 1);
        $this->fx    = intdiv($this->w - ($this->cols - 1) * self::SX - self::EW, 2);
        $this->fy    = self::TOP;
        $this->fdir  = 1;
    }

    private function period(): int {
        $left = array_sum($this->alive);
        $all  = count($this->alive);

        return max(3, 16 - $this->wave * 2 - intdiv(($all - $left) * 8, $all));
    }

    private function column(int $c): int {
        for ($r = $this->rows - 1; $r >= 0; $r--) if ($this->alive[$r * $this->cols + $c]) return $r;

        return -1;
    }

    private function nearest(): int {
        $best = -1;
        $dist = PHP_INT_MAX;

        for ($c = 0; $c < $this->cols; $c++) {
            if ($this->column($c) < 0) continue;
            $cx = $this->fx + $c * self::SX + intdiv(self::EW, 2);
            $d  = abs($cx - $this->px);
            if ($d < $dist) { $dist = $d; $best = $cx; }
        }

        return $best;
    }

    private function aimed(): bool {
        for ($c = 0; $c < $this->cols; $c++) {
            if ($this->column($c) < 0) continue;
            $x0 = $this->fx + $c * self::SX;
            if ($this->px + 2 > $x0 && $this->px - 2 < $x0 + self::EW) return true;
        }

        return false;
    }

    private function threatened(): bool {
        foreach ($this->bombs as $b)
            if (abs($b[0] - $this->px) < 16 && self::SHIP_Y - $b[1] < self::NEAR) return true;

        return false;
    }

    protected function handle(int $code, int $value): void {
        if ($code === 0) {
            $target = max(intdiv(self::SW, 2), min($this->w - intdiv(self::SW, 2), $value));

            if ($target !== $this->target && !$this->threatened()) {
                $nx = $this->nearest();
                if ($nx >= 0) {
                    $before = abs($this->target - $nx);
                    $after  = abs($target - $nx);
                    if ($before !== $after) $this->intent($after < $before);
                }
            }

            $this->target = $target;
        } elseif ($code === 1 && $this->pby < 0) {
            $this->intent($this->aimed());
            $this->pbx = $this->px;
            $this->pby = self::SHIP_Y - 2;
        }
    }

    protected function update(): void {
        $this->clock++;

        $d = $this->target - $this->px;
        $this->px += $d > self::PSPEED ? self::PSPEED : ($d < -self::PSPEED ? -self::PSPEED : $d);

        if ($this->pby >= 0) {
            $this->pby -= self::SHOT;

            $n = count($this->alive);
            for ($i = 0; $i < $n; $i++) {
                if (!$this->alive[$i]) continue;
                $x0 = $this->fx + ($i % $this->cols) * self::SX;
                $y0 = $this->fy + intdiv($i, $this->cols) * self::SY;
                if ($this->pbx >= $x0 && $this->pbx < $x0 + self::EW && $this->pby < $y0 + self::EH && $this->pby + self::SHOT > $y0) {
                    $this->alive[$i] = 0;
                    $this->pby = -1;
                    $this->score();
                    break;
                }
            }

            if ($this->pby < 0) $this->pby = -1;
        }

        if ($this->clock % $this->period() === 0) $this->march();

        $kept = [];
        foreach ($this->bombs as $b) {
            $b[1] += self::BOMB;
            if ($b[1] > self::H) continue;
            if ($b[0] >= $this->px - intdiv(self::SW, 2) && $b[0] < $this->px + intdiv(self::SW, 2)
                && $b[1] >= self::SHIP_Y && $b[1] < self::SHIP_Y + self::SH) {
                $this->bombs = [];
                $this->lose();
                return;
            }
            $kept[] = $b;
        }
        $this->bombs = $kept;

        if ($this->clock > self::CALM && count($this->bombs) < self::BOMBS && $this->rng->int(max(10, 24 - $this->wave * 3)) === 0)
            $this->bomb();

        $n = count($this->alive);
        for ($i = 0; $i < $n; $i++)
            if ($this->alive[$i] && $this->fy + intdiv($i, $this->cols) * self::SY + self::EH >= self::SHIP_Y) { $this->lose(); return; }

        if (!in_array(1, $this->alive, true)) {
            $this->wave++;
            $this->formation();
        }
    }

    private function march(): void {
        $lo = $this->cols;
        $hi = -1;
        for ($c = 0; $c < $this->cols; $c++)
            if ($this->column($c) >= 0) { if ($c < $lo) $lo = $c; if ($c > $hi) $hi = $c; }
        if ($hi < 0) return;

        $left  = $this->fx + $lo * self::SX;
        $right = $this->fx + $hi * self::SX + self::EW;

        if (($this->fdir > 0 && $right + self::STEP > $this->w - 4) || ($this->fdir < 0 && $left - self::STEP < 4)) {
            $this->fy  += self::DROP;
            $this->fdir = -$this->fdir;
        } else {
            $this->fx += $this->fdir * self::STEP;
        }
    }

    private function bomb(): void {
        $count = 0;
        for ($c = 0; $c < $this->cols; $c++) if ($this->column($c) >= 0) $count++;
        if (!$count) return;

        $k = $this->rng->int($count);
        for ($c = 0; $c < $this->cols; $c++) {
            $r = $this->column($c);
            if ($r < 0) continue;
            if ($k-- === 0) {
                $this->bombs[] = [$this->fx + $c * self::SX + intdiv(self::EW, 2), $this->fy + $r * self::SY + self::EH];
                return;
            }
        }
    }

    protected function dump(): array {
        $out = [$this->w, $this->px, $this->target, $this->pbx, $this->pby, $this->wave, $this->cols, $this->rows,
            $this->fx, $this->fy, $this->fdir, $this->clock, count($this->bombs)];
        foreach ($this->bombs as $b) array_push($out, $b[0], $b[1]);

        return array_merge($out, $this->alive);
    }
}
