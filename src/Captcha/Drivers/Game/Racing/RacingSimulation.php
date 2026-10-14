<?php

namespace ST_system\Captcha\Drivers\Game\Racing;

use ST_system\Captcha\Drivers\Game\IntMath;
use ST_system\Captcha\Drivers\Game\Rng;
use ST_system\Captcha\Drivers\Game\Simulation;

/** Гонка. Зеркало src/sim.js — трасса, физика машины, чекпоинты и коды ввода описаны там. */
final class RacingSimulation extends Simulation {

    public const FP = 256;

    public const K = 12;
    public const S = 8;
    public const N = self::K * self::S;

    public const CX     = 600;
    public const CY     = 450;
    public const R0     = 160;
    public const RV     = 250;
    public const JITTER = 7;
    public const START_A = 128;

    public const GAP   = 150;
    public const BEND  = 20;
    public const TRIES = 40;

    public const ROAD  = 26;
    public const WALL  = 58;
    public const CAR_R = 5;

    public const CHECKS = 8;

    public const UP    = 1;
    public const RIGHT = 2;
    public const DOWN  = 4;
    public const LEFT  = 8;

    private const A0        = 30;
    private const VENG      = [1750, 700];
    private const BRAKE     = 24;
    private const VMAX      = 1500;
    private const VBACK     = 400;
    private const VGRASS    = 600;
    public const STEER      = 32;
    private const STEER_IN  = 11;
    private const STEER_OUT = 11;
    private const TURN      = 15;
    private const TURN_V    = 900;

    public const SLIP        = 100;
    private const GRIP       = [14, 6];
    private const SLIDE      = [80, 45];
    private const SLIP_GRASS = 80;

    public const AHEAD = 4;

    public int $laps0 = 1;
    public array $track = [];
    public array $checks = [];
    public int $x = 0;
    public int $y = 0;
    public int $h = 0;
    public int $vx = 0;
    public int $vy = 0;
    public int $vf = 0;
    public int $vl = 0;
    public int $steer = 0;
    public int $keys = 0;
    public int $seg = 0;
    public int $off = 0;
    public int $cp = 0;
    public int $laps = 0;
    public int $clock = 0;
    public int $lapAt = 0;
    public int $best = 0;
    public array $lapTimes = [];
    public int $drift = 0;
    public int $scrape = 0;

    protected function cooldown(): int {
        return 1;
    }

    protected function setup(): void {
        $this->laps0  = max(1, (int)($this->params['laps'] ?? 0) ?: 1);
        $this->track  = self::build($this->rng);
        $this->checks = [];
        for ($c = 1; $c <= self::CHECKS; $c++) $this->checks[] = intdiv($c * self::N, self::CHECKS) % self::N;
    }

    /** @return array<int, array{0:int,1:int}> */
    public static function build(Rng $rng): array {
        $track = [];
        for ($tries = 0; $tries < self::TRIES; $tries++) {
            $left  = self::TRIES - 1 - $tries;
            $track = self::shape($rng, intdiv(self::RV * $left, self::TRIES - 1), intdiv(self::JITTER * $left, self::TRIES - 1));
            if (self::valid($track)) break;
        }
        return $track;
    }

    /** @return array<int, array{0:int,1:int}> */
    private static function shape(Rng $rng, int $rv, int $jitter): array {
        $sx   = 115 + $rng->int(31);
        $ctrl = [];
        for ($k = 0; $k < self::K; $k++) {
            $a = self::START_A + intdiv($k * 256, self::K) + $rng->int($jitter * 2 + 1) - $jitter;
            $r = self::R0 + $rng->int($rv + 1);
            $ctrl[] = [
                self::CX + intdiv(intdiv($r * IntMath::cos($a), 1024) * $sx, 100),
                self::CY + intdiv($r * IntMath::sin($a), 1024),
            ];
        }

        $out = [];
        $s3  = self::S * self::S * self::S;
        for ($k = 0; $k < self::K; $k++) {
            $p0 = $ctrl[($k + self::K - 1) % self::K];
            $p1 = $ctrl[$k];
            $p2 = $ctrl[($k + 1) % self::K];
            $p3 = $ctrl[($k + 2) % self::K];
            for ($s = 0; $s < self::S; $s++) {
                $pt = [];
                for ($d = 0; $d < 2; $d++) {
                    $num = 2 * $p1[$d] * $s3
                        + ($p2[$d] - $p0[$d]) * $s * self::S * self::S
                        + (2 * $p0[$d] - 5 * $p1[$d] + 4 * $p2[$d] - $p3[$d]) * $s * $s * self::S
                        + (3 * $p1[$d] - $p0[$d] - 3 * $p2[$d] + $p3[$d]) * $s * $s * $s;
                    $pt[] = intdiv($num, 2 * $s3);
                }
                $out[] = $pt;
            }
        }

        return $out;
    }

    public static function valid(array $t): bool {
        $cum = [0];
        for ($i = 0; $i < self::N; $i++) {
            $a = $t[$i];
            $b = $t[($i + 1) % self::N];
            $c = $t[($i + 2) % self::N];
            if (abs(IntMath::adiff(IntMath::atan2($b[1] - $a[1], $b[0] - $a[0]), IntMath::atan2($c[1] - $b[1], $c[0] - $b[0]))) > self::BEND)
                return false;
            $cum[] = $cum[$i] + IntMath::sqrt(($b[0] - $a[0]) * ($b[0] - $a[0]) + ($b[1] - $a[1]) * ($b[1] - $a[1]));
        }

        $total = $cum[self::N];
        for ($i = 0; $i < self::N; $i++)
            for ($j = $i + 1; $j < self::N; $j++) {
                $along = $cum[$j] - $cum[$i];
                if (min($along, $total - $along) <= 2 * self::GAP) continue;
                $dx = $t[$j][0] - $t[$i][0];
                $dy = $t[$j][1] - $t[$i][1];
                if ($dx * $dx + $dy * $dy < self::GAP * self::GAP) return false;
            }
        return true;
    }

    protected function restart(): void {
        $p = $this->track[0];
        $q = $this->track[1];
        $this->x        = $p[0] * self::FP;
        $this->y        = $p[1] * self::FP;
        $this->h        = IntMath::atan2($q[1] - $p[1], $q[0] - $p[0]) * 8;
        $this->vx       = 0;
        $this->vy       = 0;
        $this->vf       = 0;
        $this->vl       = 0;
        $this->steer    = 0;
        $this->keys     = 0;
        $this->seg      = 0;
        $this->off      = 0;
        $this->cp       = 0;
        $this->laps     = 0;
        $this->clock    = 0;
        $this->lapAt    = 0;
        $this->best     = 0;
        $this->lapTimes = [];
        $this->drift    = 0;
        $this->scrape   = 0;
    }

    /** @return int[] [отрезок, квадрат расстояния, x, y точки осевой] — всё в FP */
    public function nearest(): array {
        $fp    = self::FP;
        $best  = -1;
        $bestD = PHP_INT_MAX;
        $px    = 0;
        $py    = 0;

        for ($o = -8; $o <= 8; $o++) {
            $i    = ($this->seg + $o + self::N) % self::N;
            $a    = $this->track[$i];
            $b    = $this->track[($i + 1) % self::N];
            $ax   = $a[0] * $fp;
            $ay   = $a[1] * $fp;
            $dx   = ($b[0] - $a[0]) * $fp;
            $dy   = ($b[1] - $a[1]) * $fp;
            $len2 = $dx * $dx + $dy * $dy;
            $t    = ($this->x - $ax) * $dx + ($this->y - $ay) * $dy;
            if ($t < 0) $t = 0;
            if ($t > $len2) $t = $len2;
            $qx = $ax + ($len2 ? intdiv($dx * $t, $len2) : 0);
            $qy = $ay + ($len2 ? intdiv($dy * $t, $len2) : 0);
            $d  = ($this->x - $qx) * ($this->x - $qx) + ($this->y - $qy) * ($this->y - $qy);
            if ($d < $bestD) {
                $bestD = $d;
                $best  = $i;
                $px    = $qx;
                $py    = $qy;
            }
        }

        return [$best, $bestD, $px, $py];
    }

    public function aim(): int {
        $t    = $this->track[($this->seg + self::AHEAD) % self::N];
        $want = IntMath::atan2($t[1] - intdiv($this->y, self::FP), $t[0] - intdiv($this->x, self::FP));

        return IntMath::adiff($this->h >> 3, $want);
    }

    private function grass(): bool {
        return $this->off > self::ROAD * self::ROAD * self::FP * self::FP;
    }

    protected function handle(int $code, int $value): void {
        if ($code !== 0) return;
        $value &= 15;

        $pressed = $value & ~$this->keys;
        if ($pressed & (self::LEFT | self::RIGHT)) {
            $d    = $this->aim();
            $left = ($pressed & self::LEFT) !== 0;
            $want = $this->vf < 0 ? !$left : $left;
            $this->intent($want ? $d <= 2 : $d >= -2);
        }

        $this->keys = $value;
    }

    protected function update(): void {
        $this->clock++;
        $k = $this->keys;
        $g = $this->grass() ? 1 : 0;

        $dir = (($k & self::RIGHT) ? 1 : 0) - (($k & self::LEFT) ? 1 : 0);
        if ($dir)                  $this->steer = max(-self::STEER, min(self::STEER, $this->steer + $dir * self::STEER_IN));
        elseif ($this->steer > 0)  $this->steer = max(0, $this->steer - self::STEER_OUT);
        else                       $this->steer = min(0, $this->steer + self::STEER_OUT);

        $c  = IntMath::cos($this->h >> 3);
        $s  = IntMath::sin($this->h >> 3);
        $vf = intdiv($this->vx * $c + $this->vy * $s, 1024);
        $vl = intdiv(-$this->vx * $s + $this->vy * $c, 1024);

        $top = $g ? self::VGRASS : self::VMAX;
        if ($k & self::UP) {
            if ($vf < $top) $vf = min($top, $vf + max(1, min(self::A0 * 2, intdiv(self::A0 * (self::VENG[$g] - $vf), self::VENG[$g]))));
        } elseif ($k & self::DOWN) {
            $vf = max(-self::VBACK, $vf - ($vf > 0 ? self::BRAKE : 10));
        } else {
            $drag = intdiv(abs($vf), 300) + 2;
            $vf   = $vf > 0 ? max(0, $vf - $drag) : min(0, $vf + $drag);
        }
        if ($vf > $top) $vf -= intdiv($vf - $top, 20) + 1;
        if ($g) $vf -= intdiv($vf, 80);

        $speed    = abs($vf);
        $steerAbs = abs($this->steer);
        $turn     = intdiv($this->steer * self::TURN * min($speed, self::TURN_V), self::STEER * self::TURN_V);
        if ($speed > self::TURN_V) $turn = intdiv($turn * 3 * self::TURN_V, 2 * self::TURN_V + $speed);
        $this->h  = ($this->h + ($vf < 0 ? -$turn : $turn) + 2048) % 2048;
        $vf      -= intdiv($vf * $steerAbs, self::STEER * 300);

        $slip = $g ? self::SLIP_GRASS : self::SLIP;
        if (abs($vl) <= $slip) {
            $vl -= intdiv($vl * self::GRIP[$g], 32);
            $this->drift = 0;
        } else {
            $loss = intdiv(abs($vl), 64);
            $vf  -= $vf > 0 ? min($vf, $loss) : -min(-$vf, $loss);
            $vl  -= $vl > 0 ? self::SLIDE[$g] : -self::SLIDE[$g];
            $this->drift = 1;
        }

        $this->vx = intdiv($vf * $c - $vl * $s, 1024);
        $this->vy = intdiv($vf * $s + $vl * $c, 1024);
        $this->vf = $vf;
        $this->vl = $vl;

        $this->x += $this->vx;
        $this->y += $this->vy;

        $n         = $this->nearest();
        $this->seg = $n[0];
        $this->off = $n[1];

        $limit        = (self::WALL - self::CAR_R) * self::FP;
        $this->scrape = 0;
        if ($n[1] > $limit * $limit) {
            $dx = $this->x - $n[2];
            $dy = $this->y - $n[3];
            $d  = max(1, IntMath::sqrt($n[1]));
            $nx = intdiv($dx * 1024, $d);
            $ny = intdiv($dy * 1024, $d);
            $this->x = $n[2] + intdiv($nx * $limit, 1024);
            $this->y = $n[3] + intdiv($ny * $limit, 1024);

            $into = intdiv($this->vx * $nx + $this->vy * $ny, 1024);
            if ($into > 0) {
                $this->vx -= intdiv($into * $nx, 1024);
                $this->vy -= intdiv($into * $ny, 1024);
            }
            $this->vx    -= intdiv($this->vx, 4);
            $this->vy    -= intdiv($this->vy, 4);
            $this->off    = $limit * $limit;
            $this->scrape = 1;
        }

        $target = $this->checks[$this->cp];
        if (($this->seg - $target + self::N) % self::N < 3) {
            $this->score();
            $this->cp++;
            if ($this->cp === self::CHECKS) {
                $this->cp = 0;
                $this->laps++;
                $time = $this->clock - $this->lapAt;
                $this->lapTimes[] = $time;
                if (!$this->best || $time < $this->best) $this->best = $time;
                $this->lapAt = $this->clock;
            }
        }
    }

    protected function dump(): array {
        return array_merge([$this->x, $this->y, $this->h, $this->vx, $this->vy, $this->vf, $this->vl, $this->steer, $this->keys,
            $this->seg, $this->off, $this->cp, $this->laps, $this->clock, $this->lapAt, $this->best, $this->drift, $this->scrape],
            $this->lapTimes);
    }
}
