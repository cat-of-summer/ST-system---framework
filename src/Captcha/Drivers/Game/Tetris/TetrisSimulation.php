<?php

namespace ST_system\Captcha\Drivers\Game\Tetris;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Поле 8×14, внизу ряд мусора с дыркой в 3 клетки. Коды ввода: 0 — поворот,
 * 1 — вправо, 2 — вниз на клетку, 3 — влево, мгновенного сброса нет. Реакция — убранная линия.
 * Осмысленное действие — сдвиг ближе к одному из лучших мест для фигуры при её повороте; поворот
 * не оценивается.
 */
final class TetrisSimulation extends Simulation {

    private const COLS    = 8;
    private const ROWS    = 14;
    private const GRAV    = 10;
    private const GARBAGE = 8;
    private const KICKS   = [0, -1, 1, -2, 2];

    // [размер рамки поворота, клетки в положении 0]: I O T S Z J L
    private const BASE = [
        [4, [[0, 1], [1, 1], [2, 1], [3, 1]]],
        [2, [[0, 0], [1, 0], [0, 1], [1, 1]]],
        [3, [[1, 0], [0, 1], [1, 1], [2, 1]]],
        [3, [[1, 0], [2, 0], [0, 1], [1, 1]]],
        [3, [[0, 0], [1, 0], [1, 1], [2, 1]]],
        [3, [[0, 0], [0, 1], [1, 1], [2, 1]]],
        [3, [[2, 0], [0, 1], [1, 1], [2, 1]]],
    ];

    private static array $shapes = [];

    private array $grid = [];
    private array $goals = [];
    private int $type   = 0;
    private int $rot    = 0;
    private int $x      = 0;
    private int $y      = 0;
    private int $next   = 0;
    private int $clock  = 0;
    private int $moved  = 0;

    /** $shapes[тип][поворот] — клетки; поворот по часовой: (x, y) → (n-1-y, x). */
    private static function shapes(): array {
        if (self::$shapes) return self::$shapes;

        foreach (self::BASE as [$n, $cells]) {
            $rots = [$cells];
            for ($r = 1; $r < 4; $r++)
                $rots[] = array_map(static fn(array $c): array => [$n - 1 - $c[1], $c[0]], $rots[$r - 1]);

            self::$shapes[] = $rots;
        }

        return self::$shapes;
    }

    protected function restart(): void {
        $this->grid = array_fill(0, self::COLS * self::ROWS, 0);

        $gap = $this->rng->int(self::COLS - 2);
        for ($x = 0; $x < self::COLS; $x++)
            if ($x < $gap || $x >= $gap + 3) $this->grid[(self::ROWS - 1) * self::COLS + $x] = self::GARBAGE;

        $this->next = $this->rng->int(7);
        $this->spawn();
    }

    private function spawn(): void {
        $this->type  = $this->next;
        $this->next  = $this->rng->int(7);
        $this->rot   = 0;
        $this->x     = intdiv(self::COLS - self::BASE[$this->type][0], 2);
        $this->y     = 0;
        $this->clock = 0;
        $this->moved = 0;

        if ($this->collide($this->x, $this->y, $this->rot)) $this->lose();
        else $this->plan();
    }

    /** Лучшие места для новой фигуры: [поворот, x] с оценкой не ниже лучшей минус 2. */
    private function plan(): void {
        $all  = [];
        $best = -1000000000;

        for ($rot = 0; $rot < 4; $rot++)
            for ($x = -2; $x < self::COLS; $x++) {
                if ($this->collide($x, 0, $rot)) continue;

                $y = 0;
                while (!$this->collide($x, $y + 1, $rot)) $y++;

                $score = $this->rate($rot, $x, $y);
                $all[] = [$rot, $x, $score];
                if ($score > $best) $best = $score;
            }

        $this->goals = [];
        foreach ($all as $p) if ($p[2] >= $best - 2) $this->goals[] = [$p[0], $p[1]];
    }

    /** Оценка места: 20 за линию, −6 за дыру, −1 за клетку высоты. */
    private function rate(int $rot, int $x, int $y): int {
        $grid = $this->grid;
        foreach (self::shapes()[$this->type][$rot] as $c) $grid[($y + $c[1]) * self::COLS + $x + $c[0]] = 1;

        $lines  = 0;
        $holes  = 0;
        $height = 0;

        for ($r = 0; $r < self::ROWS; $r++) {
            $full = true;
            for ($cx = 0; $cx < self::COLS; $cx++) if (!$grid[$r * self::COLS + $cx]) { $full = false; break; }
            if ($full) $lines++;
        }

        for ($cx = 0; $cx < self::COLS; $cx++) {
            $seen = false;
            for ($r = 0; $r < self::ROWS; $r++) {
                if ($grid[$r * self::COLS + $cx]) {
                    if (!$seen && self::ROWS - $r > $height) $height = self::ROWS - $r;
                    $seen = true;
                } elseif ($seen) {
                    $holes++;
                }
            }
        }

        return $lines * 20 - $holes * 6 - $height;
    }

    /** Сколько сдвигов до ближайшего лучшего места при нынешнем повороте (нет такого — при любом). */
    private function away(): int {
        $same = 99;
        $any  = 99;

        foreach ($this->goals as $g) {
            $d = abs($g[1] - $this->x);
            if ($g[0] === $this->rot && $d < $same) $same = $d;
            if ($d < $any) $any = $d;
        }

        return $same < 99 ? $same : $any;
    }

    private function collide(int $x, int $y, int $rot): bool {
        foreach (self::shapes()[$this->type][$rot] as $c) {
            $cx = $x + $c[0];
            $cy = $y + $c[1];
            if ($cx < 0 || $cx >= self::COLS || $cy >= self::ROWS) return true;
            if ($cy >= 0 && $this->grid[$cy * self::COLS + $cx]) return true;
        }

        return false;
    }

    protected function handle(int $code, int $value): void {
        if ($code < 0 || $code > 3) return;

        $this->moved = 1;

        if ($code === 0) {
            $rot = ($this->rot + 1) % 4;
            foreach (self::KICKS as $k)
                if (!$this->collide($this->x + $k, $this->y, $rot)) {
                    $this->x  += $k;
                    $this->rot = $rot;
                    break;
                }
        } elseif ($code === 1 || $code === 3) {
            $before = $this->away();
            $dx     = $code === 1 ? 1 : -1;
            if (!$this->collide($this->x + $dx, $this->y, $this->rot)) $this->x += $dx;

            // Осмысленно — сдвинуть фигуру ближе к одному из лучших мест; между равноценными местами
            // и в стену — не в счёт.
            $after = $this->away();
            if ($after !== $before) $this->intent($after < $before);
        } elseif ($code === 2) {
            if (!$this->collide($this->x, $this->y + 1, $this->rot)) {
                $this->y++;
                $this->clock = 0;
            }
        }
    }

    protected function update(): void {
        if (++$this->clock < self::GRAV) return;
        $this->clock = 0;

        if (!$this->collide($this->x, $this->y + 1, $this->rot)) $this->y++;
        else $this->lock();
    }

    private function lock(): void {
        foreach (self::shapes()[$this->type][$this->rot] as $c)
            $this->grid[($this->y + $c[1]) * self::COLS + $this->x + $c[0]] = $this->type + 1;

        for ($row = self::ROWS - 1; $row >= 0;) {
            $full = true;
            for ($x = 0; $x < self::COLS; $x++)
                if (!$this->grid[$row * self::COLS + $x]) { $full = false; break; }

            if (!$full) { $row--; continue; }

            for ($r = $row; $r > 0; $r--)
                for ($x = 0; $x < self::COLS; $x++) $this->grid[$r * self::COLS + $x] = $this->grid[($r - 1) * self::COLS + $x];
            for ($x = 0; $x < self::COLS; $x++) $this->grid[$x] = 0;

            $this->score();
        }

        $this->spawn();
    }

    protected function dump(): array {
        return array_merge([$this->type, $this->rot, $this->x, $this->y, $this->next, $this->clock, $this->moved], $this->grid);
    }
}
