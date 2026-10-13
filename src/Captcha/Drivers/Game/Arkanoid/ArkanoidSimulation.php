<?php

namespace ST_system\Captcha\Drivers\Game\Arkanoid;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Мир высотой 280 единиц, ширина — params.w; число кирпичей в ряду — под
 * ширину. Код ввода 0 — x центра ракетки, к которому она едет; любой ввод на старте жизни
 * запускает мяч. Реакция — мяч отбит ракеткой; broken — разбитые за партию кирпичи (счёт на экране).
 */
final class ArkanoidSimulation extends Simulation {

    private const H      = 280;
    private const FP     = 256;
    private const PY     = self::H - 24;
    private const PW     = 48;
    private const R      = 4;
    private const ROWS   = 4;
    private const BW     = 26;
    private const BH     = 10;
    private const BGAP   = 2;
    private const BY0    = 22;
    private const PSPEED = 8;
    private const V      = 1536;
    private const LAUNCH = [-1024, -768, 768, 1024];

    private int $w        = 498;
    private int $cols     = 0;
    private int $x0       = 0;
    private int $px       = 0;
    private int $target   = 0;
    private int $broken   = 0;
    private int $bx       = 0;
    private int $by       = 0;
    private int $vx       = 0;
    private int $vy       = 0;
    private array $bricks = [];

    protected function setup(): void {
        $this->w      = (int)($this->params['w'] ?? 0) ?: 498;
        $this->cols   = max(4, intdiv($this->w - 16 + self::BGAP, self::BW + self::BGAP));
        $this->x0     = intdiv($this->w - $this->cols * (self::BW + self::BGAP) + self::BGAP, 2);
        $this->px     = intdiv($this->w, 2);
        $this->target = $this->px;
        $this->fill();
    }

    private function fill(): void {
        $count        = 0;
        $this->bricks = [];

        for ($i = 0; $i < self::ROWS * $this->cols; $i++) {
            $on = $this->rng->int(10) < 7 ? 1 : 0;
            $this->bricks[] = $on;
            $count += $on;
        }

        if ($count === 0) $this->bricks[0] = 1;
    }

    protected function restart(): void {
        $this->vx = 0;
        $this->vy = 0;
        $this->bx = $this->px * self::FP;
        $this->by = (self::PY - self::R) * self::FP;
    }

    protected function start(): void {
        $this->vx = self::LAUNCH[$this->rng->int(4)];
        $this->vy = -self::V;
        $this->bx = $this->px * self::FP;
        $this->by = (self::PY - self::R) * self::FP;
    }

    protected function handle(int $code, int $value): void {
        if ($code !== 0) return;

        $target = max(intdiv(self::PW, 2), min($this->w - intdiv(self::PW, 2), $value));

        // Осмысленно — пока мяч летит вниз, вести ракетку к нему.
        if ($this->vy > 0 && $target !== $this->target) {
            $bx = intdiv($this->bx, self::FP);
            $this->intent(abs($target - $bx) < abs($this->target - $bx));
        }

        $this->target = $target;
    }

    protected function update(): void {
        $d = $this->target - $this->px;
        $this->px += $d > self::PSPEED ? self::PSPEED : ($d < -self::PSPEED ? -self::PSPEED : $d);

        $ox = $this->bx;
        $oy = $this->by;
        $r  = self::R * self::FP;

        $this->bx += $this->vx;
        $this->by += $this->vy;

        if ($this->bx < $r) {
            $this->bx = $r;
            $this->vx = abs($this->vx);
        } elseif ($this->bx > ($this->w - self::R) * self::FP) {
            $this->bx = ($this->w - self::R) * self::FP;
            $this->vx = -abs($this->vx);
        }

        if ($this->by < $r) {
            $this->by = $r;
            $this->vy = abs($this->vy);
        }

        for ($i = 0; $i < self::ROWS * $this->cols; $i++) {
            if (!$this->bricks[$i]) continue;

            $x0 = ($this->x0 + ($i % $this->cols) * (self::BW + self::BGAP)) * self::FP;
            $y0 = (self::BY0 + intdiv($i, $this->cols) * (self::BH + self::BGAP)) * self::FP;
            $x1 = $x0 + self::BW * self::FP;
            $y1 = $y0 + self::BH * self::FP;

            if ($this->bx + $r > $x0 && $this->bx - $r < $x1 && $this->by + $r > $y0 && $this->by - $r < $y1) {
                $this->bricks[$i] = 0;
                $this->broken++;

                if ($ox + $r > $x0 && $ox - $r < $x1) $this->vy = -$this->vy;
                else                                  $this->vx = -$this->vx;

                if (!in_array(1, $this->bricks, true)) $this->fill();
                break;
            }
        }

        $top  = self::PY * self::FP;
        $half = intdiv(self::PW, 2);

        if ($this->vy > 0 && $oy + $r <= $top && $this->by + $r >= $top
            && $this->bx >= ($this->px - $half - self::R) * self::FP && $this->bx <= ($this->px + $half + self::R) * self::FP) {
            $this->by = $top - $r;
            $this->vy = -self::V;
            $this->vx = intdiv(($this->bx - $this->px * self::FP) * 5, $half + self::R);
            if ($this->vx > -192 && $this->vx < 192) $this->vx = $this->vx < 0 ? -192 : 192;
            $this->score();
        }

        if ($this->by > self::H * self::FP) $this->lose();
    }

    protected function dump(): array {
        return array_merge([$this->w, $this->broken, $this->px, $this->target, $this->bx, $this->by, $this->vx, $this->vy], $this->bricks);
    }
}
