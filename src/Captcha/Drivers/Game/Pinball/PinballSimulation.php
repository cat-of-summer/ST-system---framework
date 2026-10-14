<?php

namespace ST_system\Captcha\Drivers\Game\Pinball;

use ST_system\Captcha\Drivers\Game\IntMath;
use ST_system\Captcha\Drivers\Game\Simulation;

/** Пинбол. Зеркало src/sim.js — стол, физика и коды ввода описаны там. */
final class PinballSimulation extends Simulation {

    public const W  = 160;
    public const H  = 240;
    public const FP = 256;

    public const R    = 4;
    public const G    = 9;
    public const VMAX = 1400;

    public const WALLS = [
        [30, 4, 130, 4],
        [4, 40, 30, 4],
        [130, 4, 156, 30],
        [4, 40, 4, 168],
        [156, 30, 156, 236],
        [144, 64, 144, 236],
        [4, 168, 38, 200],
        [144, 168, 106, 200],
        [144, 236, 156, 236],
    ];

    public const GATE    = [156, 40, 144, 64];
    public const CLOSE_Y = 36;

    public const SLINGS = [
        [16, 138, 16, 166, 32, 178],
        [128, 138, 128, 166, 112, 178],
    ];

    public const BUMPER_ZONE = [28, 48, 124, 128];
    public const BUMPER_GAP  = 12;

    public const FLIPPERS = [[42, 204, 26, 22, -18], [102, 204, 26, 106, 146]];

    public const FR    = 3;
    public const SWING = 8;
    private const WALL_E     = 45;
    private const FLIP_E     = 30;
    private const BUMP_KICK  = 520;
    private const SLING_KICK = 380;

    public const BALL0  = [150, 232];
    public const CHARGE = 48;
    private const LAUNCH0  = 520;
    private const LAUNCH_K = 18;

    public const LOW  = 150;
    public const WINDOW = 24;

    public const BUMP_POINTS  = 100;
    public const SLING_POINTS = 10;

    public int $points = 0;
    public int $bx = 0;
    public int $by = 0;
    public int $vx = 0;
    public int $vy = 0;
    public array $fa = [0, 0];
    public array $fu = [0, 0];
    public array $fd = [0, 0];
    public array $wait = [0, 0];
    public int $charge = 0;
    public int $pull = 0;
    public int $lock = 0;
    public int $closed = 0;
    public int $shut = 0;
    public array $flash = [];
    public array $bumpers = [];

    protected function cooldown(): int {
        return 60;
    }

    protected function setup(): void {
        $this->points  = 0;
        $this->bumpers = [];

        $count = 3 + $this->rng->int(2);
        $z     = self::BUMPER_ZONE;
        for ($i = 0; $i < $count; $i++)
            for ($tries = 0; $tries < 30; $tries++) {
                $x    = $z[0] + $this->rng->int($z[2] - $z[0]);
                $y    = $z[1] + $this->rng->int($z[3] - $z[1]);
                $r    = 8 + $this->rng->int(3);
                $free = true;
                foreach ($this->bumpers as $b) {
                    $dx  = $x - $b[0];
                    $dy  = $y - $b[1];
                    $gap = $r + $b[2] + self::BUMPER_GAP;
                    if ($dx * $dx + $dy * $dy < $gap * $gap) { $free = false; break; }
                }
                if ($free) { $this->bumpers[] = [$x, $y, $r]; break; }
            }
    }

    protected function restart(): void {
        $this->bx     = self::BALL0[0] * self::FP;
        $this->by     = self::BALL0[1] * self::FP;
        $this->vx     = 0;
        $this->vy     = 0;
        $this->fa     = [self::FLIPPERS[0][3], self::FLIPPERS[1][3]];
        $this->fu     = [0, 0];
        $this->fd     = [0, 0];
        $this->wait   = [0, 0];
        $this->charge = 0;
        $this->pull   = 0;
        $this->lock   = 0;
        $this->closed = 0;
        $this->shut   = 0;
        $this->flash  = array_fill(0, count($this->bumpers) + count(self::SLINGS), 0);
    }

    private function side(int $f): bool {
        $x = intdiv($this->bx, self::FP);
        return $f === 0 ? $x <= 80 : $x >= 64;
    }

    private function low(): bool {
        return $this->bx < 144 * self::FP && $this->by >= self::LOW * self::FP;
    }

    protected function handle(int $code, int $value): void {
        if ($code === 0 || $code === 2) {
            $f = $code >> 1;
            if (!$this->fu[$f] && $this->bx < 144 * self::FP) {
                if ($this->wait[$f]) {
                    $this->intent(false);
                    $this->wait[$f] = 0;
                }
                if ($this->low() && $this->side($f)) $this->intent(true);
                else $this->wait[$f] = self::WINDOW;
            }
            $this->fu[$f] = 1;
        } elseif ($code === 1 || $code === 3) {
            $this->fu[$code >> 1] = 0;
        } elseif ($code === 4) {
            $this->pull = 1;
        } elseif ($code === 5) {
            if ($this->pull && $this->inLane()) {
                $jitter   = $this->rng->int(41);
                $this->vx = $jitter - 20;
                $this->vy = -min(self::VMAX, self::LAUNCH0 + $this->charge * self::LAUNCH_K + $jitter);
            }
            $this->pull   = 0;
            $this->charge = 0;
        }
    }

    private function inLane(): bool {
        return $this->bx > 144 * self::FP && $this->by > 220 * self::FP;
    }

    protected function update(): void {
        if ($this->pull && $this->charge < self::CHARGE) $this->charge++;
        for ($f = 0; $f < 2; $f++) if ($this->wait[$f] && --$this->wait[$f] === 0) $this->intent(false);
        for ($i = 0; $i < count($this->flash); $i++) if ($this->flash[$i] > 0) $this->flash[$i]--;

        for ($f = 0; $f < 2; $f++) {
            $p  = self::FLIPPERS[$f];
            $to = $this->fu[$f] ? $p[4] : $p[3];
            $this->fd[$f] = max(-self::SWING, min(self::SWING, $to - $this->fa[$f]));
        }

        $this->vy += self::G;
        $this->clamp();

        for ($k = 0; $k < 2; $k++) {
            for ($f = 0; $f < 2; $f++)
                $this->fa[$f] += $k === 0 ? intdiv($this->fd[$f], 2) : $this->fd[$f] - intdiv($this->fd[$f], 2);

            $this->bx += intdiv($this->vx, 2);
            $this->by += intdiv($this->vy, 2);
            $this->collide();
        }

        if ($this->by < 160 * self::FP) $this->lock = 0;
        if (!$this->closed && $this->bx > 144 * self::FP && $this->by < self::CLOSE_Y * self::FP) $this->closed = 1;
        if ($this->closed && $this->shut < 10) $this->shut++;

        if ($this->by > (self::H + self::R * 2) * self::FP) {
            for ($f = 0; $f < 2; $f++) if ($this->wait[$f]) { $this->intent(false); $this->wait[$f] = 0; }
            $this->lose();
        }
    }

    private function clamp(): void {
        $len = IntMath::sqrt($this->vx * $this->vx + $this->vy * $this->vy);
        if ($len > self::VMAX) {
            $this->vx = intdiv($this->vx * self::VMAX, $len);
            $this->vy = intdiv($this->vy * self::VMAX, $len);
        }
    }

    private function collide(): void {
        $fp = self::FP;

        foreach (self::WALLS as $w) $this->segment($w[0] * $fp, $w[1] * $fp, $w[2] * $fp, $w[3] * $fp, 0, self::WALL_E, 0, 0, 0, -1);
        if ($this->closed) $this->segment(self::GATE[0] * $fp, self::GATE[1] * $fp, self::GATE[2] * $fp, self::GATE[3] * $fp, 0, self::WALL_E, 0, 0, 0, -1);

        foreach (self::SLINGS as $i => $s) {
            if ($this->segment($s[0] * $fp, $s[1] * $fp, $s[4] * $fp, $s[5] * $fp, 0, self::WALL_E, self::SLING_KICK, 0, 0, -1)) {
                $this->flash[count($this->bumpers) + $i] = 8;
                $this->points += self::SLING_POINTS;
            }
            $this->segment($s[0] * $fp, $s[1] * $fp, $s[2] * $fp, $s[3] * $fp, 0, self::WALL_E, 0, 0, 0, -1);
            $this->segment($s[2] * $fp, $s[3] * $fp, $s[4] * $fp, $s[5] * $fp, 0, self::WALL_E, 0, 0, 0, -1);
        }

        foreach ($this->bumpers as $i => $b) {
            if ($this->segment($b[0] * $fp, $b[1] * $fp, $b[0] * $fp, $b[1] * $fp, $b[2] * $fp, self::WALL_E, self::BUMP_KICK, 0, 0, -1)) {
                $this->flash[$i] = 10;
                $this->points += self::BUMP_POINTS;
            }
        }

        for ($f = 0; $f < 2; $f++) {
            $p   = self::FLIPPERS[$f];
            $tip = $this->tip($f);
            $this->segment($p[0] * $fp, $p[1] * $fp, $tip[0], $tip[1], self::FR * $fp, self::FLIP_E, 0, $p[0] * $fp, $p[1] * $fp, $f);
        }

        $this->clamp();
    }

    /** @return int[] */
    public function tip(int $f): array {
        $p = self::FLIPPERS[$f];

        return [
            $p[0] * self::FP + intdiv($p[2] * self::FP * IntMath::cos($this->fa[$f]), IntMath::ONE),
            $p[1] * self::FP + intdiv($p[2] * self::FP * IntMath::sin($this->fa[$f]), IntMath::ONE),
        ];
    }

    private function segment(int $ax, int $ay, int $bx, int $by, int $rad, int $e, int $kick, int $ox, int $oy, int $f): bool {
        $dx   = $bx - $ax;
        $dy   = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $px   = $ax;
        $py   = $ay;

        if ($len2 > 0) {
            $t = ($this->bx - $ax) * $dx + ($this->by - $ay) * $dy;
            if ($t < 0) $t = 0;
            if ($t > $len2) $t = $len2;
            $px = $ax + intdiv($dx * $t, $len2);
            $py = $ay + intdiv($dy * $t, $len2);
        }

        $cx    = $this->bx - $px;
        $cy    = $this->by - $py;
        $reach = self::R * self::FP + $rad;
        $d2    = $cx * $cx + $cy * $cy;
        if ($d2 >= $reach * $reach) return false;

        $d = IntMath::sqrt($d2);
        if ($d === 0) return false;

        $one = IntMath::ONE;
        $nx  = intdiv($cx * $one, $d);
        $ny  = intdiv($cy * $one, $d);

        $this->bx += intdiv($nx * ($reach - $d), $one);
        $this->by += intdiv($ny * ($reach - $d), $one);

        $sx = 0;
        $sy = 0;
        if ($f >= 0 && $this->fd[$f] !== 0) {
            $sx = intdiv(-($py - $oy) * $this->fd[$f] * 804, 32768);
            $sy = intdiv(($px - $ox) * $this->fd[$f] * 804, 32768);
        }

        $rx = $this->vx - $sx;
        $ry = $this->vy - $sy;
        $vn = intdiv($rx * $nx + $ry * $ny, $one);

        if ($vn < 0) {
            $j   = intdiv(-$vn * (100 + $e), 100);
            $rx += intdiv($j * $nx, $one);
            $ry += intdiv($j * $ny, $one);
        }
        if ($kick) {
            $rx += intdiv($kick * $nx, $one);
            $ry += intdiv($kick * $ny, $one);
        }

        $this->vx = $rx + $sx;
        $this->vy = $ry + $sy;

        if ($f >= 0 && $this->fu[$f] && $this->wait[$f]) {
            $this->intent(true);
            $this->wait[$f] = 0;
        }

        if ($f >= 0 && $this->fu[$f] && $this->fd[$f] !== 0 && $this->vy < 0 && !$this->lock) {
            $this->lock = 1;
            $this->score();
        }

        return true;
    }

    protected function dump(): array {
        return array_merge([$this->bx, $this->by, $this->vx, $this->vy, $this->fa[0], $this->fa[1], $this->fu[0], $this->fu[1],
            $this->fd[0], $this->fd[1], $this->wait[0], $this->wait[1], $this->charge, $this->pull, $this->lock, $this->closed, $this->shut, $this->points, count($this->bumpers)],
            $this->flash, array_merge([], ...$this->bumpers));
    }
}
