<?php

namespace ST_system\Captcha\Drivers\Game\Puzzle;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Коды ввода: 0 — поставить кусочек (value = k × 128 + s), 1 — убрать кусочек
 * k на стол. params.fixed — кусочки, уже стоящие на поле ([[k, s], ...]): их не снять и не
 * вытеснить. params.order (только у сервера): место кусочка из ячейки спрайта k — ячейка order[k].
 * Реакции — сколько кусочков на своих местах.
 */
final class PuzzleSimulation extends Simulation {

    private const SLOT_BITS = 128;

    private int $rows    = 0;
    private int $cols    = 0;
    private int $n       = 0;
    private ?array $order = null;
    private array $slots = [];
    private array $where = [];
    private array $locked = [];

    protected function cooldown(): int {
        return 1;
    }

    protected function setup(): void {
        $this->rows  = (int)($this->params['rows'] ?? 0);
        $this->cols  = (int)($this->params['cols'] ?? 0);
        $this->n     = $this->rows * $this->cols;
        $this->order = isset($this->params['order']) ? array_values((array)$this->params['order']) : null;
        $this->slots = $this->n > 0 ? array_fill(0, $this->n, -1) : [];
        $this->where  = $this->slots;
        $this->locked = $this->n > 0 ? array_fill(0, $this->n, 0) : [];

        foreach ((array)($this->params['fixed'] ?? []) as $f) {
            $k = (int)($f[0] ?? -1);
            $s = (int)($f[1] ?? -1);
            if ($k < 0 || $k >= $this->n || $s < 0 || $s >= $this->n) continue;

            $this->slots[$s]  = $k;
            $this->where[$k]  = $s;
            $this->locked[$k] = 1;
        }

        $this->recount();
    }

    protected function restart(): void {}

    protected function handle(int $code, int $value): void {
        if ($code === 0 && $value >= 0) {
            $k = intdiv($value, self::SLOT_BITS);
            $s = $value % self::SLOT_BITS;
            if ($k < $this->n && $s < $this->n && $this->free($k, $s)) $this->place($k, $s);
        } elseif ($code === 1 && $value >= 0 && $value < $this->n && $this->where[$value] >= 0 && !$this->locked[$value]) {
            $this->slots[$this->where[$value]] = -1;
            $this->where[$value] = -1;
            $this->recount();
        }
    }

    private function free(int $k, int $s): bool {
        return !$this->locked[$k] && !($this->slots[$s] >= 0 && $this->locked[$this->slots[$s]]);
    }

    private function place(int $k, int $s): void {
        $from     = $this->where[$k];
        $occupant = $this->slots[$s];

        if ($from === $s) return;

        if ($from >= 0) $this->slots[$from] = -1;

        if ($occupant >= 0) {
            $this->where[$occupant] = $from;
            if ($from >= 0) $this->slots[$from] = $occupant;
        }

        $this->slots[$s] = $k;
        $this->where[$k] = $s;

        if ($this->order !== null) $this->intent(($this->order[$k] ?? -1) === $s);

        $this->recount();
    }

    private function recount(): void {
        if ($this->order === null) return;

        $placed = 0;
        foreach ($this->slots as $s => $k)
            if ($k >= 0 && ($this->order[$k] ?? -1) === $s) $placed++;

        $this->reactions = $placed;
        if ($placed > $this->peak) $this->peak = $placed;
    }

    protected function update(): void {}

    protected function dump(): array {
        return array_merge($this->slots, $this->where);
    }
}
