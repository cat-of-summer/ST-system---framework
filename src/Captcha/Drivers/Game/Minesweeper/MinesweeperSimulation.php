<?php

namespace ST_system\Captcha\Drivers\Game\Minesweeper;

use ST_system\Captcha\Drivers\Game\Simulation;

/** Сапёр. Зеркало src/sim.js — правила и коды ввода описаны там. */
final class MinesweeperSimulation extends Simulation {

    public const WIN_PAUSE = 15;

    public int $cols = 9;
    public int $rows = 6;
    public int $n = 54;
    public int $count = 8;
    public int $cleared = 0;
    public int $placed = 0;
    public int $opened = 0;
    public int $boom = -1;
    public int $won = 0;
    public array $mine = [];
    public array $open = [];
    public array $flag = [];

    protected function cooldown(): int {
        return 25;
    }

    protected function setup(): void {
        $this->cols    = (int)($this->params['cols'] ?? 0) ?: 9;
        $this->rows    = (int)($this->params['rows'] ?? 0) ?: 6;
        $this->n       = $this->cols * $this->rows;
        $this->count   = min((int)($this->params['mines'] ?? 0) ?: 8, $this->n - 9);
        $this->cleared = 0;
    }

    protected function restart(): void {
        $this->board();
    }

    private function board(): void {
        $this->mine   = array_fill(0, $this->n, 0);
        $this->open   = array_fill(0, $this->n, 0);
        $this->flag   = array_fill(0, $this->n, 0);
        $this->placed = 0;
        $this->opened = 0;
        $this->boom   = -1;
        $this->won    = 0;
    }

    /** @return int[] */
    private function around(int $c): array {
        $out = [];
        $r   = intdiv($c, $this->cols);
        $k   = $c % $this->cols;

        for ($dr = -1; $dr <= 1; $dr++)
            for ($dk = -1; $dk <= 1; $dk++) {
                if ($dr === 0 && $dk === 0) continue;
                $rr = $r + $dr;
                $kk = $k + $dk;
                if ($rr >= 0 && $rr < $this->rows && $kk >= 0 && $kk < $this->cols) $out[] = $rr * $this->cols + $kk;
            }

        return $out;
    }

    private function number(int $c): int {
        $m = 0;
        foreach ($this->around($c) as $a) $m += $this->mine[$a];
        return $m;
    }

    private function touches(int $c): bool {
        foreach ($this->around($c) as $a) if ($this->open[$a]) return true;
        return false;
    }

    private function place(int $first): void {
        $fr   = intdiv($first, $this->cols);
        $fk   = $first % $this->cols;
        $list = [];

        for ($c = 0; $c < $this->n; $c++) {
            $r = intdiv($c, $this->cols);
            $k = $c % $this->cols;
            if (abs($r - $fr) > 1 || abs($k - $fk) > 1) $list[] = $c;
        }

        $size = count($list);
        for ($i = 0; $i < $this->count; $i++) {
            $j = $i + $this->rng->int($size - $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            $this->mine[$list[$i]] = 1;
        }

        $this->placed = 1;
    }

    protected function handle(int $code, int $value): void {
        if ($this->won || $value < 0 || $value >= $this->n) return;

        if ($code === 0)     $this->reveal($value);
        elseif ($code === 1) $this->toggle($value);
        elseif ($code === 2) $this->chord($value);
    }

    private function reveal(int $c): void {
        if ($this->open[$c] || $this->flag[$c]) return;

        if (!$this->placed) {
            $this->place($c);
            $this->intent(true);
            $this->flood($c);
            return;
        }

        $this->intent(!$this->mine[$c] && $this->touches($c));

        if ($this->mine[$c]) {
            $this->explode($c);
            return;
        }

        $this->flood($c);
        $this->score();
        $this->check();
    }

    private function toggle(int $c): void {
        if ($this->open[$c] || !$this->placed) return;

        $this->flag[$c] = $this->flag[$c] ? 0 : 1;
        if ($this->flag[$c]) $this->intent($this->mine[$c] === 1 && $this->touches($c));
    }

    private function chord(int $c): void {
        if (!$this->open[$c]) return;

        $near   = $this->around($c);
        $flags  = 0;
        $hidden = 0;
        foreach ($near as $a) {
            $flags += $this->flag[$a];
            if (!$this->open[$a] && !$this->flag[$a]) $hidden++;
        }
        if ($hidden === 0 || $flags !== $this->number($c)) return;

        foreach ($near as $a)
            if (!$this->open[$a] && !$this->flag[$a] && $this->mine[$a]) {
                $this->intent(false);
                $this->explode($a);
                return;
            }

        $this->intent(true);
        foreach ($near as $a) if (!$this->open[$a] && !$this->flag[$a]) $this->flood($a);
        $this->score();
        $this->check();
    }

    private function explode(int $c): void {
        $this->boom = $c;
        $this->lose();
    }

    private function flood(int $c): void {
        $stack = [$c];

        while ($stack) {
            $x = array_pop($stack);
            if ($this->open[$x] || $this->flag[$x]) continue;

            $this->open[$x] = 1;
            $this->opened++;

            if ($this->number($x) === 0)
                foreach ($this->around($x) as $a) if (!$this->open[$a] && !$this->flag[$a]) $stack[] = $a;
        }
    }

    private function check(): void {
        if ($this->opened === $this->n - $this->count) {
            $this->won = self::WIN_PAUSE;
            $this->cleared++;
        }
    }

    protected function update(): void {
        if ($this->won && --$this->won === 0) $this->board();
    }

    protected function dump(): array {
        return array_merge([$this->placed, $this->opened, $this->boom, $this->won, $this->cleared], $this->mine, $this->open, $this->flag);
    }
}
