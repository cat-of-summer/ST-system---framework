<?php

namespace ST_system\Captcha\Drivers\Game\Platformer;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Карта в три экрана из seed: на каждом два обрыва, два шипа и ярусы; герой
 * слева, монетка у правого края, после неё экран сдвигается на следующий уровень. Коды ввода:
 * 1/3 — зажали вправо/влево, 5 — отпустили, 4 — прыжок. Реакция — монетка.
 */
final class PlatformerSimulation extends Simulation {

    private const T       = 16;
    private const FP      = 256;
    private const PW      = 10;
    private const PH      = 14;
    private const SCREENS = 3;
    private const RUN     = 512;
    private const GRAVITY = 128;
    private const JUMP    = 1408;
    private const FALL    = 2048;
    private const LOW     = 2;
    private const HIGH    = 4;
    private const NEAR    = 16;
    private const EDGE    = 6;

    private int $cols     = 20;
    private int $rows     = 11;
    private int $shift    = 17;
    private int $screen   = 0;
    private array $g      = [];
    private array $spikes = [];
    private int $x        = 0;
    private int $y        = 0;
    private int $vy       = 0;
    private int $dir      = 0;
    private int $face     = 1;
    private int $floor    = 1;

    protected function setup(): void {
        $this->cols   = (int)($this->params['cols'] ?? 0) ?: 20;
        $this->rows   = (int)($this->params['rows'] ?? 0) ?: 11;
        $this->shift  = $this->cols - 3;
        $this->screen = 0;

        $len          = self::SCREENS * $this->shift + 3;
        $this->g      = array_fill(0, $len, self::LOW);
        $this->spikes = array_fill(0, $len, 0);

        $h = self::LOW;
        for ($s = 0; $s < self::SCREENS; $s++) $h = $this->generate($s * $this->shift, $h);
    }

    private function generate(int $base, int $h): int {
        $list = ['pit', 'pit', 'spike', 'spike'];
        for ($i = 0; $i < intdiv(max(0, $this->cols - 20), 8); $i++) $list[] = $i % 2 === 0 ? 'pit' : 'spike';

        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = $this->rng->int($i + 1);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }

        $n     = count($list);
        $level = [$this->step($h)];
        for ($i = 0; $i < $n; $i++) $level[] = $this->step($level[$i]);

        $end = $base + $this->cols - 3;
        $c   = $base + 3;

        for ($i = 0; $i < $n; $i++) {
            $spare  = $end - $c - 3 * ($n - $i);
            $run    = 2 + ($spare > 0 ? $this->rng->int(2) : 0);
            $spare -= $run - 2;
            for ($k = 0; $k < $run; $k++) $this->g[$c++] = $level[$i];

            if ($list[$i] === 'pit') {
                $width = 1 + ($spare > 0 && $level[$i + 1] <= $level[$i] ? $this->rng->int(2) : 0);
                for ($k = 0; $k < $width; $k++) $this->g[$c++] = 0;
            } else {
                $this->g[$c]        = $level[$i];
                $this->spikes[$c++] = 1;
            }
        }

        for (; $c < $base + $this->cols; $c++) $this->g[$c] = $level[$n];

        return $level[$n];
    }

    private function step(int $h): int {
        if ($h <= self::LOW) return $h + 1;
        if ($h >= self::HIGH) return $h - 1;

        return $h + ($this->rng->int(2) ? 1 : -1);
    }

    private function base(): int {
        return $this->screen * $this->shift;
    }

    private function coin(): int {
        return $this->base() + $this->cols - 2;
    }

    protected function restart(): void {
        $c           = $this->base() + 1;
        $this->x     = ($c * self::T + 3) * self::FP;
        $this->y     = ($this->rows - $this->g[$c]) * self::T * self::FP;
        $this->vy    = 0;
        $this->dir   = 0;
        $this->face  = 1;
        $this->floor = 1;
    }

    private function solid(int $c, int $r): bool {
        if ($c < $this->base() || $c >= $this->base() + $this->cols) return true;

        return $r >= $this->rows - $this->g[$c] && $r < $this->rows;
    }

    private function blocked(int $x, int $y): bool {
        $x0 = intdiv($x, self::FP);
        $x1 = $x0 + self::PW - 1;
        $y0 = max(0, intdiv($y, self::FP) - self::PH);
        $y1 = intdiv($y, self::FP) - 1;
        if ($x0 < 0) return true;

        for ($c = intdiv($x0, self::T); $c <= intdiv($x1, self::T); $c++)
            for ($r = intdiv($y0, self::T); $r <= intdiv(max(0, $y1), self::T); $r++)
                if ($this->solid($c, $r)) return true;

        return false;
    }

    private function column(): int {
        return intdiv(intdiv($this->x, self::FP) + intdiv(self::PW, 2), self::T);
    }

    private function danger(): int {
        $x0   = intdiv($this->x, self::FP);
        $here = $this->column();
        $cf   = $this->face > 0 ? intdiv($x0 + self::PW - 1, self::T) : intdiv($x0, self::T);

        for ($k = 0; $k <= 3; $k++) {
            $n = $cf + $k * $this->face;
            if ($n < $this->base() || $n >= $this->base() + $this->cols) return -1;
            if ($n === $here || !($this->g[$n] === 0 || $this->spikes[$n] || $this->g[$n] > $this->g[$here])) continue;

            return max(0, $this->face > 0 ? $n * self::T - $x0 - self::PW : $x0 - $n * self::T - self::T);
        }

        return -1;
    }

    protected function handle(int $code, int $value): void {
        if ($code === 1 || $code === 3) {
            $dir = $code === 1 ? 1 : -1;
            if ($dir === $this->dir) return;

            $this->intent($dir > 0);
            $this->dir  = $dir;
            $this->face = $dir;
        } elseif ($code === 5) {
            $this->dir = 0;
        } elseif ($code === 4 && $this->floor) {
            $d = $this->danger();
            $this->intent($d >= 0 && $d <= ($this->dir === $this->face ? self::NEAR : self::EDGE));
            $this->vy    = -self::JUMP;
            $this->floor = 0;
        }
    }

    protected function update(): void {
        if ($this->dir) {
            $nx = $this->x + $this->dir * self::RUN;
            if (!$this->blocked($nx, $this->y)) $this->x = $nx;
        }

        $standing = $this->vy >= 0 && $this->blocked($this->x, $this->y + self::FP);
        if (!$standing) $this->vy = min(self::FALL, $this->vy + self::GRAVITY);
        $ny = $this->y + $this->vy;

        if ($standing) {
            $this->vy    = 0;
            $this->floor = 1;
        } elseif (!$this->blocked($this->x, $ny)) {
            $this->y     = $ny;
            $this->floor = 0;
        } elseif ($this->vy > 0) {
            $this->y     = intdiv(intdiv($ny, self::FP) - 1, self::T) * self::T * self::FP;
            $this->vy    = 0;
            $this->floor = 1;
        } else {
            $this->vy = 0;
        }

        if (intdiv($this->y, self::FP) - self::PH > $this->rows * self::T) { $this->lose(); return; }

        $px0 = intdiv($this->x, self::FP);
        $py1 = intdiv($this->y, self::FP);

        for ($c = intdiv($px0, self::T); $c <= intdiv($px0 + self::PW - 1, self::T); $c++) {
            if (!$this->spikes[$c]) continue;
            $top = ($this->rows - $this->g[$c]) * self::T;
            if ($px0 + self::PW > $c * self::T + 3 && $px0 < $c * self::T + self::T - 3 && $py1 > $top - 9) { $this->lose(); return; }
        }

        $coin = $this->coin();
        $cy   = ($this->rows - $this->g[$coin] - 1) * self::T;
        if ($px0 + self::PW > $coin * self::T + 3 && $px0 < $coin * self::T + self::T - 3
            && $py1 > $cy + 3 && $py1 - self::PH < $cy + self::T - 3) {
            $this->score();
            $this->screen = ($this->screen + 1) % self::SCREENS;
            if ($this->screen === 0) $this->restart();
        }
    }

    protected function dump(): array {
        return array_merge(
            [$this->cols, $this->rows, $this->screen, $this->x, $this->y, $this->vy, $this->dir, $this->face, $this->floor],
            $this->g,
            $this->spikes
        );
    }
}
