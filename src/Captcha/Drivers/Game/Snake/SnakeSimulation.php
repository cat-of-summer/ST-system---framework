<?php

namespace ST_system\Captcha\Drivers\Game\Snake;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Поле cols × rows из params без стен: выход за край — с противоположной
 * стороны, смерть только о себя. Шаг — раз в STEP тиков. Коды ввода: 0..3 — направление.
 * Реакция — съеденное яблоко (не ближе ⌊(cols + rows) / 4⌋ от головы). Осмысленное действие — поворот, после которого голова ближе к яблоку, чем если ехать прямо.
 */
final class SnakeSimulation extends Simulation {

    private const DX = [0, 1, 0, -1];
    private const DY = [-1, 0, 1, 0];
    private const STEP = 5;

    private int $cols   = 16;
    private int $rows   = 9;
    private array $body = [];
    private int $dir    = 1;
    private int $next   = 1;
    private int $ax     = 0;
    private int $ay     = 0;
    private int $clock  = 0;

    protected function cooldown(): int {
        return 24;
    }

    protected function setup(): void {
        $this->cols = (int)($this->params['cols'] ?? 0) ?: 16;
        $this->rows = (int)($this->params['rows'] ?? 0) ?: 9;
    }

    protected function restart(): void {
        $x = intdiv($this->cols, 2);
        $y = intdiv($this->rows, 2);

        $this->body = [[$x, $y], [$x - 1, $y], [$x - 2, $y]];
        $this->dir   = 1;
        $this->next  = 1;
        $this->clock = 0;
        $this->place();
    }

    private function away(int $x, int $y): int {
        return self::wrapDist($x, $this->body[0][0], $this->cols) + self::wrapDist($y, $this->body[0][1], $this->rows);
    }

    private function place(): void {
        $length = count($this->body);
        $far    = 0;

        for ($y = 0; $y < $this->rows; $y++)
            for ($x = 0; $x < $this->cols; $x++)
                if (!$this->hits($x, $y, $length)) $far = max($far, $this->away($x, $y));

        $min   = min($far, intdiv($this->cols + $this->rows, 4));
        $count = 0;

        for ($y = 0; $y < $this->rows; $y++)
            for ($x = 0; $x < $this->cols; $x++)
                if (!$this->hits($x, $y, $length) && $this->away($x, $y) >= $min) $count++;

        $k = $this->rng->int($count);

        for ($y = 0; $y < $this->rows; $y++)
            for ($x = 0; $x < $this->cols; $x++) {
                if ($this->hits($x, $y, $length) || $this->away($x, $y) < $min) continue;
                if ($k === 0) { $this->ax = $x; $this->ay = $y; return; }
                $k--;
            }
    }

    private function hits(int $x, int $y, int $length): bool {
        for ($i = 0; $i < $length; $i++)
            if ($this->body[$i][0] === $x && $this->body[$i][1] === $y) return true;

        return false;
    }

    private static function wrapDist(int $a, int $b, int $n): int {
        $d = abs($a - $b);

        return min($d, $n - $d);
    }

    /** Расстояние от клетки рядом с головой (в направлении $d) до яблока. */
    private function reach(int $d): int {
        $x = ($this->body[0][0] + self::DX[$d] + $this->cols) % $this->cols;
        $y = ($this->body[0][1] + self::DY[$d] + $this->rows) % $this->rows;

        return self::wrapDist($x, $this->ax, $this->cols) + self::wrapDist($y, $this->ay, $this->rows);
    }

    protected function handle(int $code, int $value): void {
        if ($code < 0 || $code > 3 || ($code + 2) % 4 === $this->dir || $code === $this->next) return;

        $this->intent($this->reach($code) < $this->reach($this->next));
        $this->next = $code;
    }

    protected function update(): void {
        if (++$this->clock % self::STEP !== 0) return;

        $this->dir = $this->next;

        $x   = ($this->body[0][0] + self::DX[$this->dir] + $this->cols) % $this->cols;
        $y   = ($this->body[0][1] + self::DY[$this->dir] + $this->rows) % $this->rows;
        $eat = $x === $this->ax && $y === $this->ay;

        if ($this->hits($x, $y, $eat ? count($this->body) : count($this->body) - 1)) {
            $this->lose();
            return;
        }

        array_unshift($this->body, [$x, $y]);

        if ($eat) {
            $this->score();
            $this->place();
        } else {
            array_pop($this->body);
        }
    }

    protected function dump(): array {
        $out = [$this->cols, $this->rows, $this->clock, $this->dir, $this->next, $this->ax, $this->ay, count($this->body)];
        foreach ($this->body as $cell) array_push($out, $cell[0], $cell[1]);

        return $out;
    }
}
