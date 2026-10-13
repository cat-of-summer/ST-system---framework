<?php

namespace ST_system\Captcha\Drivers\Game\PacMan;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Лабиринт cols × rows строится из зерна, два призрака. Коды ввода: 0..3 —
 * куда свернуть при первой возможности. Реакция — съеденная вишня. Осмысленное действие —
 * поворот туда, где путь до вишни по лабиринту короче.
 */
final class PacManSimulation extends Simulation {

    private const DX = [0, 1, 0, -1];
    private const DY = [-1, 0, 1, 0];

    private const DANGER = 3;

    private array $walls  = [];
    private array $map    = [];
    private array $dots   = [];
    private array $starts = [];
    private array $ghosts = [];
    private int $cols  = 19;
    private int $rows  = 11;
    private int $sx    = 0;
    private int $sy    = 0;
    private int $px    = 0;
    private int $py    = 0;
    private int $dir   = -1;
    private int $want  = -1;
    private int $fx    = 0;
    private int $fy    = 0;
    private int $clock = 0;
    private int $left  = 0;

    protected function cooldown(): int {
        return 10;
    }

    protected function setup(): void {
        $this->cols = (int)($this->params['cols'] ?? 0) ?: 19;
        $this->rows = (int)($this->params['rows'] ?? 0) ?: 11;
        $this->maze();

        $this->sx     = intdiv($this->cols, 2) | 1;
        $this->sy     = $this->rows - 2;
        $this->starts = [[1, 1], [$this->cols - 2, 1]];

        $this->map = array_map(static fn(int $w): int => $w ? 0 : 1, $this->walls);
        $this->map[$this->sy * $this->cols + $this->sx] = 0;
        foreach ($this->starts as $s) $this->map[$s[1] * $this->cols + $s[0]] = 0;

        $this->refill();
        $this->px = $this->sx;
        $this->py = $this->sy;
        $this->place();
    }

    /** Комнаты на нечётных клетках, обход в глубину, пробитые тупики и несколько петель. */
    private function maze(): void {
        $rc = intdiv($this->cols - 1, 2);
        $rr = intdiv($this->rows - 1, 2);

        $this->walls = array_fill(0, $this->cols * $this->rows, 1);
        $seen        = array_fill(0, $rc * $rr, 0);

        for ($r = 0; $r < $rc * $rr; $r++)
            $this->walls[(2 * intdiv($r, $rc) + 1) * $this->cols + 2 * ($r % $rc) + 1] = 0;

        $stack = [0];
        $seen[0] = 1;

        while ($stack) {
            $r = $stack[count($stack) - 1];
            $options = [];

            for ($d = 0; $d < 4; $d++) {
                $n = $this->room($r, $d, $rc, $rr);
                if ($n >= 0 && !$seen[$n]) $options[] = $d;
            }

            if (!$options) { array_pop($stack); continue; }

            $d = $options[$this->rng->int(count($options))];
            $n = $this->room($r, $d, $rc, $rr);
            $this->dig($r, $d, $rc);
            $seen[$n] = 1;
            $stack[] = $n;
        }

        for ($r = 0; $r < $rc * $rr; $r++) {
            $options = [];
            $exits   = 0;

            for ($d = 0; $d < 4; $d++) {
                if ($this->room($r, $d, $rc, $rr) < 0) continue;
                if ($this->walls[$this->gate($r, $d, $rc)]) $options[] = $d;
                else $exits++;
            }

            if ($exits === 1 && $options) $this->dig($r, $options[$this->rng->int(count($options))], $rc);
        }

        for ($k = intdiv($rc * $rr, 6); $k > 0; $k--) {
            $r = $this->rng->int($rc * $rr);
            $d = $this->rng->int(4);
            if ($this->room($r, $d, $rc, $rr) >= 0) $this->dig($r, $d, $rc);
        }
    }

    /** Соседняя комната по направлению d или -1 за краем. */
    private function room(int $r, int $d, int $rc, int $rr): int {
        $x = $r % $rc + self::DX[$d];
        $y = intdiv($r, $rc) + self::DY[$d];

        return $x >= 0 && $y >= 0 && $x < $rc && $y < $rr ? $y * $rc + $x : -1;
    }

    /** Клетка проёма между комнатой r и её соседом по направлению d. */
    private function gate(int $r, int $d, int $rc): int {
        return (2 * intdiv($r, $rc) + 1 + self::DY[$d]) * $this->cols + 2 * ($r % $rc) + 1 + self::DX[$d];
    }

    private function dig(int $r, int $d, int $rc): void {
        $this->walls[$this->gate($r, $d, $rc)] = 0;
    }

    private function refill(): void {
        $this->dots = $this->map;
        $this->left = array_sum($this->dots);
    }

    protected function restart(): void {
        $this->px     = $this->sx;
        $this->py     = $this->sy;
        $this->dir    = -1;
        $this->want   = -1;
        $this->clock  = 0;
        $this->ghosts = array_map(static fn(array $s): array => [$s[0], $s[1], -1], $this->starts);
    }

    private function open(int $x, int $y): bool {
        return $x >= 0 && $y >= 0 && $x < $this->cols && $y < $this->rows && !$this->walls[$y * $this->cols + $x];
    }

    private function place(): void {
        $n   = count($this->walls);
        $far = 0;

        for ($i = 0; $i < $n; $i++)
            if (!$this->walls[$i]) $far = max($far, $this->away($i));

        $min   = min($far, intdiv($this->cols + $this->rows, 3));
        $count = 0;

        for ($i = 0; $i < $n; $i++)
            if (!$this->walls[$i] && $this->away($i) >= $min) $count++;

        $k = $this->rng->int($count);

        for ($i = 0; $i < $n; $i++) {
            if ($this->walls[$i] || $this->away($i) < $min) continue;
            if ($k === 0) { $this->fx = $i % $this->cols; $this->fy = intdiv($i, $this->cols); return; }
            $k--;
        }
    }

    private function away(int $i): int {
        return abs($i % $this->cols - $this->px) + abs(intdiv($i, $this->cols) - $this->py);
    }

    /** Длина кратчайшего пути от каждой клетки до вишни (обход в ширину); стены и недостижимые — −1. */
    private function distances(): array {
        $cols  = $this->cols;
        $dist  = array_fill(0, count($this->walls), -1);
        $start = $this->fy * $cols + $this->fx;
        $queue = [$start];

        $dist[$start] = 0;

        for ($q = 0; $q < count($queue); $q++) {
            $i = $queue[$q];
            $x = $i % $cols;
            $y = intdiv($i, $cols);

            for ($d = 0; $d < 4; $d++) {
                $nx = $x + self::DX[$d];
                $ny = $y + self::DY[$d];
                $k  = $ny * $cols + $nx;
                if (!$this->open($nx, $ny) || $dist[$k] >= 0) continue;
                $dist[$k] = $dist[$i] + 1;
                $queue[]  = $k;
            }
        }

        return $dist;
    }

    /** Клетка, где можно свернуть в $code: та, где Пакман сейчас, или первая такая по ходу; −1 — нигде. */
    private function turnAt(int $code): int {
        $x = $this->px;
        $y = $this->py;

        for ($step = 0; $step <= $this->cols + $this->rows; $step++) {
            if ($this->open($x + self::DX[$code], $y + self::DY[$code])) return $y * $this->cols + $x;
            if ($this->dir < 0 || !$this->open($x + self::DX[$this->dir], $y + self::DY[$this->dir])) return -1;
            $x += self::DX[$this->dir];
            $y += self::DY[$this->dir];
        }

        return -1;
    }

    /** Призрак ближе DANGER клеток по прямой. */
    private function threatened(): bool {
        foreach ($this->ghosts as $g)
            if (abs($g[0] - $this->px) + abs($g[1] - $this->py) <= self::DANGER) return true;

        return false;
    }

    protected function handle(int $code, int $value): void {
        if ($code < 0 || $code > 3 || $code === $this->want) return;

        // Осмысленно — свернуть туда, где путь до вишни короче: сразу или на ближайшей развилке по ходу.
        // Рядом с призраком повороты не оцениваются: уходить от него — тоже игра.
        $at = $this->turnAt($code);

        if ($this->threatened()) {
            // не в счёт
        } elseif ($at < 0) {
            $this->intent(false);
        } else {
            $dist = $this->distances();
            $x    = $at % $this->cols;
            $y    = intdiv($at, $this->cols);
            $this->intent($dist[($y + self::DY[$code]) * $this->cols + $x + self::DX[$code]] < $dist[$at]);
        }

        $this->want = $code;
    }

    protected function update(): void {
        $this->clock++;

        $ox = $this->px;
        $oy = $this->py;

        if ($this->clock % 2 === 0) {
            if ($this->want >= 0 && $this->open($this->px + self::DX[$this->want], $this->py + self::DY[$this->want]))
                $this->dir = $this->want;

            if ($this->dir >= 0 && $this->open($this->px + self::DX[$this->dir], $this->py + self::DY[$this->dir])) {
                $this->px += self::DX[$this->dir];
                $this->py += self::DY[$this->dir];
            }

            $i = $this->py * $this->cols + $this->px;

            if ($this->dots[$i]) {
                $this->dots[$i] = 0;
                if (--$this->left === 0) $this->refill();
            }

            if ($this->px === $this->fx && $this->py === $this->fy) {
                $this->score();
                $this->place();
            }

            foreach ($this->ghosts as $g)
                if ($g[0] === $this->px && $g[1] === $this->py) { $this->lose(); return; }
        }

        if ($this->clock % 3 === 0)
            foreach ($this->ghosts as $n => $g) {
                [$gx, $gy] = $g;

                $this->ghosts[$n] = $g = $this->chase($g);

                if (($g[0] === $this->px && $g[1] === $this->py)
                    || ($g[0] === $ox && $g[1] === $oy && $gx === $this->px && $gy === $this->py)) {
                    $this->lose();
                    return;
                }
            }
    }

    private function chase(array $g): array {
        $options = [];

        for ($d = 0; $d < 4; $d++) {
            if ($g[2] >= 0 && $d === ($g[2] + 2) % 4) continue;
            if ($this->open($g[0] + self::DX[$d], $g[1] + self::DY[$d])) $options[] = $d;
        }

        if (!$options) {
            if ($g[2] < 0) return $g;
            $back = ($g[2] + 2) % 4;
            if (!$this->open($g[0] + self::DX[$back], $g[1] + self::DY[$back])) return $g;
            $options[] = $back;
        }

        $d = $options[0];

        if ($this->rng->int(3) === 0) {
            $best = PHP_INT_MAX;
            foreach ($options as $o) {
                $dist = abs($g[0] + self::DX[$o] - $this->px) + abs($g[1] + self::DY[$o] - $this->py);
                if ($dist < $best) { $best = $dist; $d = $o; }
            }
        } else {
            $d = $options[$this->rng->int(count($options))];
        }

        return [$g[0] + self::DX[$d], $g[1] + self::DY[$d], $d];
    }

    protected function dump(): array {
        $out = [$this->cols, $this->rows, $this->px, $this->py, $this->dir, $this->want, $this->fx, $this->fy, $this->clock, $this->left];
        foreach ($this->ghosts as $g) array_push($out, $g[0], $g[1], $g[2]);

        return array_merge($out, $this->dots);
    }
}
