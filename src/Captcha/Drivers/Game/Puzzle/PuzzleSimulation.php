<?php

namespace ST_system\Captcha\Drivers\Game\Puzzle;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Коды ввода: 0 — поставить кусочек (value = k × 64 + s), 1 — убрать кусочек
 * k на стол. params.order (только у сервера): место кусочка из ячейки спрайта k — ячейка order[k].
 * Реакции — сколько кусочков на своих местах.
 */
final class PuzzleSimulation extends Simulation {

    private const SLOT_BITS = 64;

    private int $rows    = 0;
    private int $cols    = 0;
    private int $n       = 0;
    private ?array $order = null;
    private array $slots = [];
    private array $where = [];

    protected function cooldown(): int {
        return 1;
    }

    protected function setup(): void {
        $this->rows  = (int)($this->params['rows'] ?? 0);
        $this->cols  = (int)($this->params['cols'] ?? 0);
        $this->n     = $this->rows * $this->cols;
        $this->order = isset($this->params['order']) ? array_values((array)$this->params['order']) : null;
        $this->slots = $this->n > 0 ? array_fill(0, $this->n, -1) : [];
        $this->where = $this->slots;
    }

    protected function restart(): void {}

    protected function handle(int $code, int $value): void {
        if ($code === 0 && $value >= 0) {
            $k = intdiv($value, self::SLOT_BITS);
            $s = $value % self::SLOT_BITS;
            if ($k < $this->n && $s < $this->n) $this->place($k, $s);
        } elseif ($code === 1 && $value >= 0 && $value < $this->n && $this->where[$value] >= 0) {
            $this->slots[$this->where[$value]] = -1;
            $this->where[$value] = -1;
            $this->recount();
        }
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
