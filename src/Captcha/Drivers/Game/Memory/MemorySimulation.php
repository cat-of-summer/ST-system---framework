<?php

namespace ST_system\Captcha\Drivers\Game\Memory;

use ST_system\Captcha\Drivers\Game\Simulation;

/** Memory. Зеркало src/sim.js — правила, фазы и коды ввода описаны там. */
final class MemorySimulation extends Simulation {

    public const PREVIEW = 0;
    public const PLAY    = 1;
    public const MISS    = 2;
    public const WON     = 3;

    public const MISS_TICKS = 8;
    public const WIN_PAUSE  = 20;

    public const DOWN  = 0;
    public const UP    = 1;
    public const FOUND = 2;

    public int $pairs = 8;
    public int $n = 16;
    public int $max = 4;
    public int $show = 50;
    public int $solved = 0;
    public int $lives = 4;
    public array $deck = [];
    public array $face = [];
    public int $phase = self::PREVIEW;
    public int $timer = 0;
    public int $first = -1;
    public int $missA = -1;
    public int $missB = -1;
    public int $found = 0;

    protected function setup(): void {
        $this->pairs  = max(2, min(20, (int)($this->params['pairs'] ?? 0) ?: 8));
        $this->n      = $this->pairs * 2;
        $this->max    = max(1, (int)($this->params['lives'] ?? 0) ?: 4);
        $this->show   = max(1, (int)($this->params['show'] ?? 0) ?: 50);
        $this->solved = 0;
    }

    protected function restart(): void {
        $this->lives = $this->max;
        $this->deal();
    }

    private function deal(): void {
        $this->deck = [];
        $this->face = [];
        for ($i = 0; $i < $this->n; $i++) {
            $this->deck[] = $i >> 1;
            $this->face[] = self::DOWN;
        }
        for ($i = $this->n - 1; $i > 0; $i--) {
            $j = $this->rng->int($i + 1);
            [$this->deck[$i], $this->deck[$j]] = [$this->deck[$j], $this->deck[$i]];
        }

        $this->phase = self::PREVIEW;
        $this->timer = $this->show;
        $this->first = -1;
        $this->missA = -1;
        $this->missB = -1;
        $this->found = 0;
    }

    protected function start(): void {
        $this->reveal(self::UP);
    }

    private function reveal(int $state): void {
        for ($i = 0; $i < $this->n; $i++) $this->face[$i] = $state;
    }

    private function hide(): void {
        $this->face[$this->missA] = self::DOWN;
        $this->face[$this->missB] = self::DOWN;
        $this->missA = $this->missB = -1;
        $this->phase = self::PLAY;
    }

    protected function handle(int $code, int $value): void {
        if ($code !== 0 || $value < 0 || $value >= $this->n) return;
        if ($this->phase === self::MISS) $this->hide();
        if ($this->phase !== self::PLAY || $this->face[$value] !== self::DOWN) return;

        $this->face[$value] = self::UP;

        if ($this->first < 0) {
            $this->first = $value;
            return;
        }

        $a = $this->first;
        $this->first = -1;

        if ($this->deck[$a] === $this->deck[$value]) {
            $this->intent(true);
            $this->face[$a] = $this->face[$value] = self::FOUND;
            $this->score();

            if (++$this->found === $this->pairs) {
                $this->solved++;
                $this->phase = self::WON;
                $this->timer = self::WIN_PAUSE;
            }
            return;
        }

        $this->intent(false);
        $this->missA = $a;
        $this->missB = $value;
        $this->phase = self::MISS;
        $this->timer = self::MISS_TICKS;

        if (--$this->lives === 0) $this->lose();
    }

    protected function update(): void {
        if ($this->phase === self::PREVIEW) {
            if (--$this->timer === 0) {
                $this->reveal(self::DOWN);
                $this->phase = self::PLAY;
            }
        } elseif ($this->phase === self::MISS) {
            if (--$this->timer === 0) $this->hide();
        } elseif ($this->phase === self::WON) {
            if (--$this->timer === 0) {
                $this->deal();
                $this->start();
            }
        }
    }

    protected function dump(): array {
        return array_merge(
            [$this->pairs, $this->lives, $this->solved, $this->phase, $this->timer, $this->first, $this->missA, $this->missB, $this->found],
            $this->deck, $this->face
        );
    }
}
