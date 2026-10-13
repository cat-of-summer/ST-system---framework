<?php

namespace ST_system\Captcha\Drivers\Game\Tetris;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Поле 8×14, внизу ряд мусора с дыркой в 3 клетки. Коды ввода: 0 — поворот,
 * 1 — вправо, 2 — вниз на клетку, 3 — влево, 4 — сбросить. Реакция — убранная линия.
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
    }

    /** Пустые клетки, над которыми в том же столбце есть занятые. */
    private function holes(): int {
        $holes = 0;

        for ($x = 0; $x < self::COLS; $x++) {
            $covered = false;
            for ($y = 0; $y < self::ROWS; $y++) {
                if ($this->grid[$y * self::COLS + $x]) $covered = true;
                elseif ($covered) $holes++;
            }
        }

        return $holes;
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
        if ($code < 0 || $code > 4) return;

        $this->moved = 1;

        if ($code === 0) {
            $rot = ($this->rot + 1) % 4;
            foreach (self::KICKS as $k)
                if (!$this->collide($this->x + $k, $this->y, $rot)) {
                    $this->x  += $k;
                    $this->rot = $rot;
                    return;
                }
        } elseif ($code === 1 || $code === 3) {
            $dx = $code === 1 ? 1 : -1;
            if (!$this->collide($this->x + $dx, $this->y, $this->rot)) $this->x += $dx;
        } elseif ($code === 2) {
            if (!$this->collide($this->x, $this->y + 1, $this->rot)) {
                $this->y++;
                $this->clock = 0;
            }
        } elseif ($code === 4) {
            while (!$this->collide($this->x, $this->y + 1, $this->rot)) $this->y++;
            $this->lock();
        }
    }

    protected function update(): void {
        if (++$this->clock < self::GRAV) return;
        $this->clock = 0;

        if (!$this->collide($this->x, $this->y + 1, $this->rot)) $this->y++;
        else $this->lock();
    }

    private function lock(): void {
        $before = $this->holes();

        foreach (self::shapes()[$this->type][$this->rot] as $c)
            $this->grid[($this->y + $c[1]) * self::COLS + $this->x + $c[0]] = $this->type + 1;

        // Осмысленно — фигуру, которую двигали, положить без новых дыр.
        if ($this->moved) $this->intent($this->holes() <= $before);

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
