<?php

namespace ST_system\Captcha\Drivers\Game\Asteroids;

use ST_system\Captcha\Drivers\Game\IntMath;
use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Ракета у левого края ходит по вертикали и стреляет вправо, астероиды летят
 * справа налево. Хитбокс — контур: астероид — неровный многоугольник, повёрнутый на rot, ракета —
 * три выпуклых многоугольника. Коды ввода: 0 — куда вести ракету, 1 — огонь включён, 2 — огонь
 * выключен. Реакция — сбитый астероид и увёрт от астероида, который шёл в ракету.
 */
final class AsteroidsSimulation extends Simulation {

    private const H  = 150;
    private const FP = 256;

    private const RX     = 28;
    private const ROCKET = [
        [[15, 0], [8, -4], [-11, -4], [-11, 4], [8, 4]],
        [[-1, -4], [-11, -4], [-14, -10], [-8, -10]],
        [[-1, 4], [-8, 10], [-14, 10], [-11, 4]],
    ];
    private const NOSE = 15;
    private const TAIL = 14;
    private const RB   = 18;
    private const YMIN = 11;
    private const YMAX = self::H - 11;

    private const R  = [8, 13, 20];
    private const N  = 10;
    private const M0 = 12;
    private const M1 = 17;

    private const HP0 = 5;
    private const HP1 = 10;

    private const PSPEED = 4;
    private const SHOT   = 9;
    private const FIRE   = 6;
    private const SHOTS  = 6;
    private const ROCKS  = 4;
    private const DANGER = 100;
    private const MARGIN = 2;
    private const MOVED  = 8;
    private const KEEP   = 15;

    private const HIT = 0, SHRINK = 1, BOOM = 2, DODGE = 3;

    private int $w        = 267;
    private int $ids      = 0;
    private int $y        = 0;
    private int $target   = 0;
    private array $rocks  = [];
    private array $shots  = [];
    private int $firing   = 0;
    private int $cool     = 0;
    private int $next     = 0;
    private int $clock    = 0;
    private array $events = [];

    protected function setup(): void {
        $this->w   = (int)($this->params['w'] ?? 0) ?: 267;
        $this->ids = 0;
    }

    protected function restart(): void {
        $this->y      = intdiv(self::H, 2) * self::FP;
        $this->target = $this->y;
        $this->rocks  = [];
        $this->shots  = [];
        $this->firing = 0;
        $this->cool   = 0;
        $this->next   = 30;
        $this->clock  = 0;
        $this->events = [];
    }

    private static function tierOf(int $hp): int {
        return $hp >= 6 ? 2 : ($hp >= 3 ? 1 : 0);
    }

    private static function reachOf(int $tier): int {
        return intdiv(self::R[$tier] * self::FP * self::M1, 16);
    }

    private static function orient(int $ax, int $ay, int $bx, int $by, int $cx, int $cy): int {
        return ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax) <=> 0;
    }

    private static function within(int $a, int $b, int $c): bool {
        return min($a, $b) <= $c && $c <= max($a, $b);
    }

    private static function segments(array $p1, array $p2, array $q1, array $q2): bool {
        $o1 = self::orient($p1[0], $p1[1], $p2[0], $p2[1], $q1[0], $q1[1]);
        $o2 = self::orient($p1[0], $p1[1], $p2[0], $p2[1], $q2[0], $q2[1]);
        $o3 = self::orient($q1[0], $q1[1], $q2[0], $q2[1], $p1[0], $p1[1]);
        $o4 = self::orient($q1[0], $q1[1], $q2[0], $q2[1], $p2[0], $p2[1]);

        if ($o1 * $o2 < 0 && $o3 * $o4 < 0) return true;

        if ($o1 === 0 && self::within($p1[0], $p2[0], $q1[0]) && self::within($p1[1], $p2[1], $q1[1])) return true;
        if ($o2 === 0 && self::within($p1[0], $p2[0], $q2[0]) && self::within($p1[1], $p2[1], $q2[1])) return true;
        if ($o3 === 0 && self::within($q1[0], $q2[0], $p1[0]) && self::within($q1[1], $q2[1], $p1[1])) return true;
        if ($o4 === 0 && self::within($q1[0], $q2[0], $p2[0]) && self::within($q1[1], $q2[1], $p2[1])) return true;

        return false;
    }

    private static function inside(array $poly, int $x, int $y): bool {
        $odd = false;
        $n   = count($poly);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$ax, $ay] = $poly[$j];
            [$bx, $by] = $poly[$i];
            if (($ay > $y) === ($by > $y)) continue;

            $lhs = ($bx - $ax) * ($y - $ay);
            $rhs = ($x - $ax) * ($by - $ay);
            if ($by > $ay ? $lhs > $rhs : $lhs < $rhs) $odd = !$odd;
        }

        return $odd;
    }

    private static function overlap(array $p, array $q): bool {
        $np = count($p);
        $nq = count($q);

        for ($i = 0, $j = $np - 1; $i < $np; $j = $i++)
            for ($k = 0, $l = $nq - 1; $k < $nq; $l = $k++)
                if (self::segments($p[$j], $p[$i], $q[$l], $q[$k])) return true;

        return self::inside($q, $p[0][0], $p[0][1]) || self::inside($p, $q[0][0], $q[0][1]);
    }

    private static function hull(array $a): array {
        $out  = [];
        $r    = self::R[$a['tier']] * self::FP;
        $base = $a['rot'] >> 3;

        for ($k = 0; $k < self::N; $k++) {
            $ang   = $base + intdiv($k * 256, self::N) + $a['j'][$k];
            $rr    = intdiv($r * $a['m'][$k], 16);
            $out[] = [$a['x'] + intdiv($rr * IntMath::cos($ang), IntMath::ONE), $a['y'] + intdiv($rr * IntMath::sin($ang), IntMath::ONE)];
        }

        return $out;
    }

    private static function rocket(int $y): array {
        $out = [];
        foreach (self::ROCKET as $part) {
            $poly = [];
            foreach ($part as $p) $poly[] = [(self::RX + $p[0]) * self::FP, $y + $p[1] * self::FP];
            $out[] = $poly;
        }

        return $out;
    }

    private function shape(array &$a): void {
        $a['m'] = [];
        $a['j'] = [];
        for ($k = 0; $k < self::N; $k++) {
            $a['m'][] = self::M0 + $this->rng->int(self::M1 - self::M0 + 1);
            $a['j'][] = $this->rng->int(9) - 4;
        }
    }

    private function spawn(): void {
        $hp    = self::HP0 + $this->rng->int(self::HP1 - self::HP0 + 1);
        $tier  = self::tierOf($hp);
        $reach = self::reachOf($tier);
        $lo    = $reach + self::FP;
        $span  = self::H * self::FP - 2 * $reach - 2 * self::FP;
        $y     = $lo + $this->rng->int(max(1, intdiv($span, self::FP))) * self::FP;

        foreach ($this->rocks as $b)
            if ($b['x'] > ($this->w - 40) * self::FP && abs($b['y'] - $y) < $reach + self::reachOf($b['tier']) + 4 * self::FP) {
                $this->next = 8;
                return;
            }

        $a = [
            'id'     => $this->ids++,
            'x'      => $this->w * self::FP + $reach + self::FP,
            'y'      => $y,
            'vx'     => -(400 + $this->rng->int(180) + min(200, intdiv($this->clock, 3))),
            'vy'     => $this->rng->int(129) - 64,
            'rot'    => $this->rng->int(2048),
            'spin'   => (1 + $this->rng->int(8)) * ($this->rng->int(2) ? 1 : -1),
            'hp'     => $hp,
            'tier'   => $tier,
            'm'      => [],
            'j'      => [],
            'seen'   => 0,
            'threat' => 0,
            'py'     => 0,
            'ty'     => 0,
            'passed' => 0,
        ];
        $this->shape($a);
        $this->rocks[] = $a;

        $this->next = max(28, 75 - intdiv($this->clock, 12)) + $this->rng->int(25);
    }

    private static function predict(array $a): int {
        $t      = intdiv($a['x'] - self::RX * self::FP, -$a['vx']);
        $reach  = self::reachOf($a['tier']);
        $lo     = $reach;
        $span   = self::H * self::FP - 2 * $reach;
        $period = 2 * $span;
        if ($span <= 0) return $a['y'];

        $v = ($a['y'] + $a['vy'] * $t - $lo) % $period;
        if ($v < 0) $v += $period;
        if ($v > $span) $v = $period - $v;

        return $lo + $v;
    }

    private static function band(array $a): int {
        return self::reachOf($a['tier']) + (10 + self::MARGIN) * self::FP;
    }

    private function threat(): ?array {
        $best = null;
        foreach ($this->rocks as $a)
            if ($a['threat'] && !$a['passed'] && ($best === null || $a['x'] < $best['x'])) $best = $a;

        return $best;
    }

    private function ahead(): ?array {
        $best = null;
        foreach ($this->rocks as $a)
            if ($a['x'] > (self::RX + self::NOSE) * self::FP && ($best === null || $a['x'] < $best['x'])) $best = $a;

        return $best;
    }

    private function aimed(): bool {
        foreach ($this->rocks as $a) {
            if ($a['x'] + self::reachOf($a['tier']) <= (self::RX + self::NOSE) * self::FP) continue;

            $top    = $a['y'];
            $bottom = $a['y'];
            foreach (self::hull($a) as $p) {
                if ($p[1] < $top) $top = $p[1];
                if ($p[1] > $bottom) $bottom = $p[1];
            }
            if ($this->y >= $top && $this->y <= $bottom) return true;
        }

        return false;
    }

    protected function handle(int $code, int $value): void {
        if ($code === 0) {
            $target = max(self::YMIN, min(self::YMAX, $value)) * self::FP;

            if ($target !== $this->target) {
                $t = $this->threat();
                if ($t !== null) {
                    $this->intent(abs($target - $t['py']) >= self::band($t));
                } else {
                    $a = $this->ahead();
                    if ($a !== null) {
                        $before = abs($this->target - $a['y']);
                        $after  = abs($target - $a['y']);
                        if ($before !== $after) $this->intent($after < $before);
                    }
                }
            }

            $this->target = $target;
        } elseif ($code === 1) {
            if (!$this->firing) $this->intent($this->aimed());
            $this->firing = 1;
        } elseif ($code === 2) {
            $this->firing = 0;
        }
    }

    private function event(int $kind, int $x, int $y, int $id): void {
        $this->events[] = [$this->clock, $kind, $x, $y, $id];
    }

    protected function update(): void {
        $this->clock++;

        $kept = [];
        foreach ($this->events as $e) if ($this->clock - $e[0] < self::KEEP) $kept[] = $e;
        $this->events = $kept;

        $d    = $this->target - $this->y;
        $step = self::PSPEED * self::FP;
        $this->y += $d > $step ? $step : ($d < -$step ? -$step : $d);

        if (--$this->next <= 0 && count($this->rocks) < self::ROCKS) $this->spawn();

        $this->fly();

        if ($this->cool > 0) $this->cool--;
        if ($this->firing && $this->cool === 0 && count($this->shots) < self::SHOTS) {
            $this->shots[] = [(self::RX + self::NOSE) * self::FP, $this->y];
            $this->cool    = self::FIRE;
        }

        $this->shoot();

        $body = self::rocket($this->y);
        foreach ($this->rocks as $a) {
            $dx   = $a['x'] - self::RX * self::FP;
            $dy   = $a['y'] - $this->y;
            $near = self::reachOf($a['tier']) + self::RB * self::FP;
            if ($dx * $dx + $dy * $dy > $near * $near) continue;

            $hull = self::hull($a);
            foreach ($body as $part)
                if (self::overlap($part, $hull)) { $this->lose(); return; }
        }
    }

    private function fly(): void {
        $kept = [];

        foreach ($this->rocks as $a) {
            $reach = self::reachOf($a['tier']);

            $a['x']  += $a['vx'];
            $a['y']  += $a['vy'];
            $a['rot'] = ($a['rot'] + $a['spin'] + 2048) % 2048;

            if ($a['y'] < $reach) { $a['y'] = $reach; $a['vy'] = abs($a['vy']); }
            if ($a['y'] > self::H * self::FP - $reach) { $a['y'] = self::H * self::FP - $reach; $a['vy'] = -abs($a['vy']); }

            if (!$a['seen'] && $a['x'] - (self::RX + self::NOSE) * self::FP < self::DANGER * self::FP) {
                $a['seen'] = 1;
                $a['py']   = self::predict($a);
                if (abs($a['py'] - $this->y) < self::band($a)) { $a['threat'] = 1; $a['ty'] = $this->y; }
            }

            if (!$a['passed'] && $a['x'] + $reach < (self::RX - self::TAIL) * self::FP) {
                $a['passed'] = 1;
                if ($a['threat'] && abs($this->y - $a['ty']) >= self::MOVED * self::FP) {
                    $this->score();
                    $this->event(self::DODGE, self::RX * self::FP, $this->y, $a['id']);
                }
            }

            if ($a['x'] + $reach < 0) continue;
            $kept[] = $a;
        }

        $this->rocks = $kept;
    }

    private function shoot(): void {
        $kept = [];

        foreach ($this->shots as $s) {
            $x0  = $s[0];
            $x1  = $s[0] + self::SHOT * self::FP;
            $y   = $s[1];
            $hit = -1;
            $hx  = 0;

            foreach ($this->rocks as $i => $a) {
                $reach = self::reachOf($a['tier']);
                if ($a['hp'] <= 0 || abs($a['y'] - $y) > $reach || $a['x'] + $reach < $x0 || $a['x'] - $reach > $x1) continue;

                $ix = self::entry(self::hull($a), $x0, $x1, $y);
                if ($ix >= 0 && ($hit < 0 || $ix < $hx)) { $hit = $i; $hx = $ix; }
            }

            if ($hit >= 0) {
                $this->damage($hit, $hx, $y);
                continue;
            }

            $s[0] = $x1;
            if ($x1 <= ($this->w + 30) * self::FP) $kept[] = $s;
        }

        $this->shots = $kept;
        $this->rocks = array_values(array_filter($this->rocks, static function (array $a): bool { return $a['hp'] > 0; }));
    }

    private static function entry(array $hull, int $x0, int $x1, int $y): int {
        if (self::inside($hull, $x0, $y)) return $x0;

        $best = -1;
        $n    = count($hull);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$ax, $ay] = $hull[$j];
            [$bx, $by] = $hull[$i];
            if (min($ay, $by) > $y || max($ay, $by) < $y) continue;

            $ix = $ay === $by ? min($ax, $bx) : $ax + intdiv(($bx - $ax) * ($y - $ay), $by - $ay);
            if ($ix >= $x0 && $ix <= $x1 && ($best < 0 || $ix < $best)) $best = $ix;
        }

        return $best;
    }

    private function damage(int $i, int $x, int $y): void {
        $a = &$this->rocks[$i];
        $a['hp']--;
        $this->event(self::HIT, $x, $y, $a['id']);

        if ($a['hp'] <= 0) {
            $this->event(self::BOOM, $a['x'], $a['y'], $a['id']);
            $this->score();
            return;
        }

        $tier = self::tierOf($a['hp']);
        if ($tier !== $a['tier']) {
            $a['tier'] = $tier;
            $this->shape($a);
            $this->event(self::SHRINK, $a['x'], $a['y'], $a['id']);
        }
    }

    protected function dump(): array {
        $out = [$this->w, $this->ids, $this->y, $this->target, $this->firing, $this->cool, $this->next, $this->clock, count($this->rocks)];
        foreach ($this->rocks as $a)
            array_push($out, $a['id'], $a['x'], $a['y'], $a['vx'], $a['vy'], $a['rot'], $a['spin'], $a['hp'], $a['tier'],
                $a['seen'], $a['threat'], $a['py'], $a['ty'], $a['passed'], ...$a['m'], ...$a['j']);
        $out[] = count($this->shots);
        foreach ($this->shots as $s) array_push($out, $s[0], $s[1]);
        $out[] = count($this->events);
        foreach ($this->events as $e) array_push($out, ...$e);

        return $out;
    }
}
