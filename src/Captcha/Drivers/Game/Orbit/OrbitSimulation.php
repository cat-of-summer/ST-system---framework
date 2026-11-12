<?php

namespace ST_system\Captcha\Drivers\Game\Orbit;

use ST_system\Captcha\Drivers\Game\IntMath;
use ST_system\Captcha\Drivers\Game\Simulation;

/** Орбиты. Зеркало src/sim.js — правила, единицы и коды ввода описаны там. */
final class OrbitSimulation extends Simulation {

    public const FP            = 1024;
    public const STEPS         = 100;
    public const ROCKET        = 20;
    public const GRAV          = 3 * self::FP;
    public const MAX_PULL      = 220;
    public const MIN_PULL      = 20;
    public const PULL_Q        = 110;
    public const PULL_PACK     = 2 * self::PULL_Q + 1;
    public const V_MIN         = 380 * self::FP;
    public const V_MAX         = 580 * self::FP;
    public const ALONG_CATCH   = 9;
    public const FAST_MAX      = 26;
    public const SETTLE        = 984;
    public const ORBIT_MIN_ALT = self::ROCKET;
    public const OUT           = 300;
    public const MAX_FLIGHT    = 15 * self::STEPS;
    public const RATE_FULL     = 1000;
    public const RATE_AIM      = 100;
    public const EASE          = 157;

    public const ORBIT  = 0;
    public const FLIGHT = 1;

    public const FLYING = -1;
    public const CRASH  = -2;
    public const LOST   = -3;

    public const LANDSCAPE = [
        [240, 620, 60, 300, 135],
        [520, 300, 36, 240, 90],
        [780, 620, 85, 340, 175],
    ];

    public const PORTRAIT = [
        [350, 780, 60, 300, 135],
        [680, 520, 36, 240, 90],
        [420, 270, 85, 340, 175],
    ];

    public int $w = 1778;
    public int $h = 1000;
    /** @var array<int, array{x:int, y:int, r:int, field:int, orbit:int}> */
    public array $planets = [];

    public int $mode    = self::ORBIT;
    public int $planet  = 0;
    public int $from    = 0;
    public int $ignore  = -1;
    public int $flight  = 0;
    public int $ox      = 0;
    public int $oy      = 0;
    public int $radius  = 0;
    public int $radialV = 0;
    public int $speed   = 0;
    public int $dir     = 1;
    public int $x       = 0;
    public int $y       = 0;
    public int $px      = 0;
    public int $py      = 0;
    public int $vx      = 0;
    public int $vy      = 0;
    public int $aiming  = 0;
    public int $rate    = self::RATE_FULL;
    public int $tacc    = 0;
    public int $steps   = 0;

    protected function cooldown(): int {
        return 60;
    }

    protected function setup(): void {
        $this->w = (int)($this->params['w'] ?? 0) ?: 1778;
        $this->h = (int)($this->params['h'] ?? 0) ?: 1000;

        $this->planets = [];
        foreach ($this->h > $this->w ? self::PORTRAIT : self::LANDSCAPE as $s)
            $this->planets[] = [
                'x'     => intdiv($s[0] * $this->w * self::FP, 1000),
                'y'     => intdiv($s[1] * $this->h * self::FP, 1000),
                'r'     => $s[2],
                'field' => $s[3],
                'orbit' => $s[4],
            ];
    }

    protected function restart(): void {
        $p = $this->planets[0];

        $this->mode    = self::ORBIT;
        $this->planet  = 0;
        $this->from    = 0;
        $this->ignore  = -1;
        $this->flight  = 0;
        $this->ox      = 0;
        $this->oy      = -$p['orbit'] * self::FP;
        $this->radius  = $p['orbit'] * self::FP;
        $this->radialV = 0;
        $this->speed   = IntMath::sqrt(300 * $this->radius * self::FP);
        $this->dir     = 1;
        $this->x       = $p['x'] + $this->ox;
        $this->y       = $p['y'] + $this->oy;
        $this->px      = $this->x;
        $this->py      = $this->y;
        $this->vx      = 0;
        $this->vy      = 0;
        $this->aiming  = 0;
        $this->rate    = self::RATE_FULL;
        $this->tacc    = 0;
        $this->steps   = 0;
    }

    /** @return array{0:int, 1:int}|null */
    public static function launchVelocity(int $qx, int $qy): ?array {
        $len = IntMath::sqrt(($qx * $qx + $qy * $qy) * 4 * self::FP * self::FP);
        if ($len < self::MIN_PULL * self::FP) return null;

        $speed = self::V_MIN + intdiv(
            (self::V_MAX - self::V_MIN) * (min($len, self::MAX_PULL * self::FP) - self::MIN_PULL * self::FP),
            (self::MAX_PULL - self::MIN_PULL) * self::FP
        );
        return [intdiv(2 * $qx * self::FP * $speed, $len), intdiv(2 * $qy * self::FP * $speed, $len)];
    }

    /** @return array{0:int, 1:int}|null */
    public static function unpackPull(int $value): ?array {
        if ($value < 0 || $value >= self::PULL_PACK * self::PULL_PACK) return null;
        return [intdiv($value, self::PULL_PACK) - self::PULL_Q, $value % self::PULL_PACK - self::PULL_Q];
    }

    protected function handle(int $code, int $value): void {
        if ($code === 0) {
            $this->aiming = 1;
            return;
        }
        if ($code === 2) {
            $this->aiming = 0;
            return;
        }
        if ($code !== 1) return;

        $this->aiming = 0;
        $q = self::unpackPull($value);
        $v = $q ? self::launchVelocity($q[0], $q[1]) : null;
        if (!$v || $this->mode !== self::ORBIT) return;

        $this->mode   = self::FLIGHT;
        $this->vx     = $v[0];
        $this->vy     = $v[1];
        $this->from   = $this->planet;
        $this->ignore = $this->planet;
        $this->flight = 0;
    }

    protected function update(): void {
        $target     = $this->aiming && $this->mode === self::ORBIT ? self::RATE_AIM : self::RATE_FULL;
        $ease       = intdiv(($target - $this->rate) * self::EASE, 1024);
        $this->rate = $ease === 0 ? $target : $this->rate + $ease;

        $this->tacc += $this->rate;
        while ($this->tacc >= self::RATE_FULL) {
            $this->tacc -= self::RATE_FULL;
            $this->step();
            if ($this->dead > 0) break;
        }
    }

    private function step(): void {
        $this->px = $this->x;
        $this->py = $this->y;
        $this->steps++;

        if ($this->mode === self::ORBIT) {
            $this->stepOrbit();
            return;
        }

        $r = $this->flightStep();
        if ($r === self::FLYING) return;

        if ($r < 0) {
            $this->intent(false);
            $this->lose();
            return;
        }

        $this->intent($r !== $this->from);
        if ($r !== $this->from) $this->score();
        $this->catchOrbit($r);
    }

    private function gravityStep(): void {
        $ax = 0;
        $ay = 0;
        foreach ($this->planets as $p) {
            $dx = $p['x'] - $this->x;
            $dy = $p['y'] - $this->y;
            $d  = IntMath::len($dx, $dy);
            if ($d < self::FP || $d > $p['field'] * self::FP) continue;
            $ax += intdiv($dx * self::GRAV, $d);
            $ay += intdiv($dy * self::GRAV, $d);
        }
        $this->vx += $ax;
        $this->vy += $ay;
        $this->x  += intdiv($this->vx, self::STEPS);
        $this->y  += intdiv($this->vy, self::STEPS);
    }

    private function hitPlanet(): int {
        foreach ($this->planets as $i => $p) {
            $dx    = $this->x - $p['x'];
            $dy    = $this->y - $p['y'];
            $reach = intdiv(($p['r'] * 2 + self::ROCKET) * self::FP, 2);
            if ($dx * $dx + $dy * $dy < $reach * $reach) return $i;
        }
        return -1;
    }

    private function catches(array $p): bool {
        $rx = $this->x - $p['x'];
        $ry = $this->y - $p['y'];
        $d  = IntMath::len($rx, $ry);
        if ($d > $p['field'] * self::FP || $d < self::FP) return false;

        $v = IntMath::len($this->vx, $this->vy);
        if ($v >= self::FP && 10 * abs($rx * $this->vy - $ry * $this->vx) < self::ALONG_CATCH * $d * $v) return false;

        return 100 * $v * $v <= self::FAST_MAX * self::FAST_MAX * 300 * $d * self::FP;
    }

    private function outside(): bool {
        return $this->x < -self::OUT * self::FP || $this->y < -self::OUT * self::FP
            || $this->x > ($this->w + self::OUT) * self::FP || $this->y > ($this->h + self::OUT) * self::FP;
    }

    private function flightStep(): int {
        $this->gravityStep();
        $this->flight++;

        if ($this->hitPlanet() >= 0) return self::CRASH;
        if ($this->outside() || $this->flight > self::MAX_FLIGHT) return self::LOST;

        $home = $this->planets[$this->ignore] ?? null;
        if ($home) {
            $dx = $this->x - $home['x'];
            $dy = $this->y - $home['y'];
            if ($dx * $dx + $dy * $dy > $home['field'] * $home['field'] * self::FP * self::FP) $this->ignore = -1;
        }

        foreach ($this->planets as $i => $p)
            if ($i !== $this->ignore && $this->catches($p)) return $i;

        return self::FLYING;
    }

    private function stepOrbit(): void {
        $p = $this->planets[$this->planet];

        $this->radialV = intdiv($this->radialV * self::SETTLE, 1024);
        $this->radius  = max(($p['r'] + self::ORBIT_MIN_ALT) * self::FP,
            min($p['field'] * self::FP, $this->radius + intdiv($this->radialV, self::STEPS)));

        $s  = 1048576;
        $th = intdiv($this->speed * $s, self::STEPS * $this->radius) * $this->dir;
        $t2 = intdiv($th * $th, $s);
        $sn = $th - intdiv(intdiv($t2 * $th, $s), 6);
        $cs = $s - intdiv($t2, 2);

        $nx = intdiv($this->ox * $cs - $this->oy * $sn, $s);
        $ny = intdiv($this->oy * $cs + $this->ox * $sn, $s);
        $d  = max(1, IntMath::len($nx, $ny));

        $this->ox = intdiv($nx * $this->radius, $d);
        $this->oy = intdiv($ny * $this->radius, $d);
        $this->x  = $p['x'] + $this->ox;
        $this->y  = $p['y'] + $this->oy;
    }

    private function catchOrbit(int $i): void {
        $p  = $this->planets[$i];
        $rx = $this->x - $p['x'];
        $ry = $this->y - $p['y'];
        $d  = max(1, IntMath::len($rx, $ry));

        $this->mode    = self::ORBIT;
        $this->planet  = $i;
        $this->ignore  = -1;
        $this->flight  = 0;
        $this->dir     = $rx * $this->vy - $ry * $this->vx >= 0 ? 1 : -1;
        $this->ox      = $rx;
        $this->oy      = $ry;
        $this->radius  = $d;
        $this->radialV = intdiv($rx * $this->vx + $ry * $this->vy, $d);
        $this->speed   = IntMath::len($this->vx, $this->vy);
    }

    protected function dump(): array {
        $out = [$this->w, $this->h, $this->mode, $this->planet, $this->from, $this->ignore, $this->flight, $this->ox, $this->oy,
            $this->radius, $this->radialV, $this->speed, $this->dir, $this->x, $this->y, $this->px, $this->py, $this->vx, $this->vy,
            $this->aiming, $this->rate, $this->tacc, $this->steps];
        foreach ($this->planets as $p) {
            $out[] = $p['x'];
            $out[] = $p['y'];
            $out[] = $p['r'];
            $out[] = $p['field'];
            $out[] = $p['orbit'];
        }
        return $out;
    }
}
