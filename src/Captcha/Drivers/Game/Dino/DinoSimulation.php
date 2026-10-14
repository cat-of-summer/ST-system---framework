<?php

namespace ST_system\Captcha\Drivers\Game\Dino;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Динозавр бежит, карта ускоряется, препятствия — от seed. Код ввода 0 —
 * прыжок с земли. Реакция — пройденное препятствие. Осмысленный прыжок перелетает ближайшее
 * препятствие.
 */
final class DinoSimulation extends Simulation {

    private const H         = 150;
    private const FP        = 256;
    private const GROUND    = self::H - 20;
    private const DX        = 30;
    private const DW        = 18;
    private const DH        = 20;
    private const GRAVITY   = 128;
    private const JUMP      = 1920;
    private const SPEED0    = 1024;
    private const ACCEL     = 64;
    private const EVERY     = 60;
    private const MAXSPD    = 2048;
    private const AIR       = 32;
    private const BIRD_LIFT = 6;

    // [вид, ширина, высота]: 0 — кактус, 1 — птица. Те же, что KINDS в sim.js.
    private const KINDS = [[0, 12, 24], [0, 24, 28], [0, 12, 32], [0, 10, 20], [1, 18, 12]];

    // Хитбокс динозавра — голова, туловище, ноги от левого верхнего угла спрайта, как PARTS в sim.js.
    private const PARTS = [[10, 1, 17, 6], [2, 7, 13, 15], [4, 15, 10, 20]];

    private int $w     = 267;
    private int $y     = 0;
    private int $vy    = 0;
    private int $speed = 0;
    private int $clock = 0;
    private int $dist  = 0;
    private array $obs = [];

    protected function setup(): void {
        $this->w = (int)($this->params['w'] ?? 0) ?: 267;
    }

    protected function restart(): void {
        $this->y     = self::GROUND * self::FP;
        $this->vy    = 0;
        $this->speed = self::SPEED0;
        $this->clock = 0;
        $this->obs   = [];
        $this->dist  = intdiv($this->w * self::FP, 2);
    }

    private function gap(): int {
        return $this->speed * (self::AIR + 20) + (60 + $this->rng->int(120)) * self::FP;
    }

    private function spawn(): void {
        $kind        = $this->rng->int($this->clock > self::EVERY * 3 ? count(self::KINDS) : count(self::KINDS) - 1);
        $this->obs[] = [$this->w * self::FP + 8 * self::FP, $kind, 0];
        $this->dist  = $this->gap();
    }

    private static function box(array $o): array {
        $k  = self::KINDS[$o[1]];
        $x0 = intdiv($o[0], self::FP);
        $y1 = $k[0] === 1 ? self::GROUND - self::BIRD_LIFT : self::GROUND;

        return [$x0, $y1 - $k[2], $x0 + $k[1], $y1];
    }

    private static function boxes(array $o): array {
        $b = self::box($o);
        $k = self::KINDS[$o[1]];
        if ($k[0] === 1) return [[$b[0] + 1, $b[3] - 8, $b[2] - 3, $b[3] - 2]];

        $out = [];
        $n   = max(1, intdiv($k[1], 12));
        $sw  = intdiv($k[1], $n);
        for ($i = 0; $i < $n; $i++) {
            $x     = $b[0] + $i * $sw;
            $sh    = $k[2] - ($i % 2) * 4;
            $top   = $b[3] - $sh;
            $tx    = $x + intdiv($sw - 4, 2);
            $out[] = [$tx, $top, $tx + 4, $b[3]];
            $out[] = [$x + 1, $top + intdiv($sh * 3, 10) + 2, $x + $sw - 1, $top + intdiv($sh * 6, 10)];
        }

        return $out;
    }

    private function hits(int $y, array $o): bool {
        $top = intdiv($y, self::FP) - self::DH;
        foreach (self::boxes($o) as $b)
            foreach (self::PARTS as $p)
                if (self::DX + $p[2] > $b[0] && self::DX + $p[0] < $b[2] && $top + $p[3] > $b[1] && $top + $p[1] < $b[3]) return true;

        return false;
    }

    private function ahead(): ?array {
        foreach ($this->obs as $o) if (self::box($o)[2] > self::DX) return $o;

        return null;
    }

    private function clears(): bool {
        $o = $this->ahead();
        if ($o === null) return false;

        $y     = $this->y;
        $vy    = -self::JUMP;
        $probe = [$o[0], $o[1], 0];

        for ($t = 0; $t < self::AIR + 4; $t++) {
            $vy       += self::GRAVITY;
            $y        += $vy;
            $probe[0] -= $this->speed;

            if ($y >= self::GROUND * self::FP) return self::box($probe)[2] <= self::DX;
            if ($this->hits($y, $probe)) return false;
        }

        return self::box($probe)[2] <= self::DX;
    }

    protected function handle(int $code, int $value): void {
        if ($code !== 0 || $this->y < self::GROUND * self::FP) return;

        $this->intent($this->clears());
        $this->vy = -self::JUMP;
    }

    protected function update(): void {
        if (++$this->clock % self::EVERY === 0 && $this->speed < self::MAXSPD) $this->speed += self::ACCEL;

        if ($this->y < self::GROUND * self::FP || $this->vy < 0) {
            $this->vy += self::GRAVITY;
            $this->y  += $this->vy;
            if ($this->y >= self::GROUND * self::FP) { $this->y = self::GROUND * self::FP; $this->vy = 0; }
        }

        $this->dist -= $this->speed;
        if ($this->dist <= 0) $this->spawn();

        foreach ($this->obs as &$o) {
            $o[0] -= $this->speed;

            if (!$o[2] && self::box($o)[2] <= self::DX) {
                $o[2] = 1;
                $this->score();
            }
        }
        unset($o);

        while ($this->obs && self::box($this->obs[0])[2] < -10) array_shift($this->obs);

        foreach ($this->obs as $o)
            if ($this->hits($this->y, $o)) { $this->lose(); return; }
    }

    protected function dump(): array {
        $out = [$this->w, $this->y, $this->vy, $this->speed, $this->clock, $this->dist, count($this->obs)];
        foreach ($this->obs as $o) array_push($out, $o[0], $o[1], $o[2]);

        return $out;
    }
}
