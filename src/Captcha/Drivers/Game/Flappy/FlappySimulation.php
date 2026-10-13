<?php

namespace ST_system\Captcha\Drivers\Game\Flappy;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Мир высотой 300 единиц, ширина — params.w. Код ввода 0 — взмах.
 * Реакция — пройденная труба. Взмах далеко под зазором не в счёт, разбилась — неосмысленное действие.
 */
final class FlappySimulation extends Simulation {

    private const H       = 300;
    private const GROUND  = 30;
    private const FP      = 256;
    private const BX      = 60;
    private const R       = 8;
    private const PW      = 40;
    private const GAP     = 110;
    private const SPEED   = 3;
    private const SPACING = 150;
    private const GRAVITY = 205;
    private const FLAP    = -1792;
    private const MAX_VY  = 2560;
    private const FIRST   = 170;
    private const RESCUE  = 40;

    private int $w       = 533;
    private int $y       = 0;
    private int $vy      = 0;
    private int $dist    = 0;
    private array $pipes = [];

    protected function cooldown(): int {
        return 30;
    }

    protected function setup(): void {
        $this->w = (int)($this->params['w'] ?? 0) ?: 533;
    }

    private function gapY(): int {
        return 20 + $this->rng->int(self::H - self::GROUND - self::GAP - 40);
    }

    protected function restart(): void {
        $this->y     = intdiv(self::H, 2) * self::FP;
        $this->vy    = 0;
        $this->pipes = [];

        $last = -1;
        for ($x = self::BX + self::FIRST; $x < $this->w; $x += self::SPACING) {
            $this->pipes[] = [$x, $this->gapY(), 0];
            $last = $x;
        }

        $this->dist = $last < 0 ? self::SPACING : $this->w - $last;
    }

    /** Середина зазора ближайшей трубы впереди; без трубы — середина неба. */
    private function aim(): int {
        foreach ($this->pipes as $pipe)
            if ($pipe[0] + self::PW >= self::BX - self::R) return $pipe[1] + intdiv(self::GAP, 2);

        return intdiv(self::H - self::GROUND, 2);
    }

    protected function handle(int $code, int $value): void {
        if ($code !== 0) return;

        $y   = intdiv($this->y, self::FP);
        $aim = $this->aim();
        if ($y <= $aim + self::RESCUE) $this->intent($y > $aim - 10);
        $this->vy = self::FLAP;
    }

    protected function update(): void {
        $this->vy = min($this->vy + self::GRAVITY, self::MAX_VY);
        $this->y += $this->vy;

        if ($this->y < self::R * self::FP) {
            $this->y  = self::R * self::FP;
            $this->vy = 0;
        }

        $this->dist += self::SPEED;

        if ($this->dist >= self::SPACING) {
            $this->dist    = 0;
            $this->pipes[] = [$this->w, $this->gapY(), 0];
        }

        foreach ($this->pipes as &$pipe) {
            $pipe[0] -= self::SPEED;

            if (!$pipe[2] && $pipe[0] + self::PW < self::BX - self::R) {
                $pipe[2] = 1;
                $this->score();
            }
        }
        unset($pipe);

        while ($this->pipes && $this->pipes[0][0] + self::PW < 0) array_shift($this->pipes);

        $by = intdiv($this->y, self::FP);

        if ($by + self::R >= self::H - self::GROUND) {
            $this->intent(false);
            $this->lose();
            return;
        }

        foreach ($this->pipes as $pipe)
            if (self::BX + self::R > $pipe[0] && self::BX - self::R < $pipe[0] + self::PW
                && ($by - self::R < $pipe[1] || $by + self::R > $pipe[1] + self::GAP)) {
                $this->intent(false);
                $this->lose();
                return;
            }
    }

    protected function dump(): array {
        $out = [$this->w, $this->y, $this->vy, $this->dist, count($this->pipes)];
        foreach ($this->pipes as $pipe) array_push($out, $pipe[0], $pipe[1], $pipe[2]);

        return $out;
    }
}
