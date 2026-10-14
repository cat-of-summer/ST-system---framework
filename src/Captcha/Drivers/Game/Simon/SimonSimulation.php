<?php

namespace ST_system\Captcha\Drivers\Game\Simon;

use ST_system\Captcha\Drivers\Game\Simulation;

/** Simon. Зеркало src/sim.js — правила, фазы и коды ввода описаны там. */
final class SimonSimulation extends Simulation {

    public const FIRST = 4;

    public const WAIT  = 0;
    public const SHOW  = 1;
    public const INPUT = 2;
    public const DONE  = 3;

    public const PAUSE   = 16;
    public const SUCCESS = 20;
    public const TIMEOUT = 100;
    public const FLASH   = 6;

    public int $buttons = 4;
    public int $rounds = 2;
    public array $seq = [];
    public int $round = 0;
    public int $phase = self::WAIT;
    public int $timer = 0;
    public int $step = 0;
    public int $pos = 0;
    public int $flash = -1;
    public int $flashT = 0;
    public int $wrong = -1;

    public static function onTicks(int $round): int {
        return max(5, 11 - $round);
    }

    public static function offTicks(int $round): int {
        return max(3, 6 - intdiv($round, 2));
    }

    protected function cooldown(): int {
        return 24;
    }

    protected function setup(): void {
        $this->buttons = max(3, min(6, (int)($this->params['buttons'] ?? 0) ?: 4));
        $this->rounds  = max(1, (int)($this->params['rounds'] ?? 0) ?: 2);
    }

    protected function restart(): void {
        $this->round  = 0;
        $this->deal();
        $this->phase  = self::WAIT;
        $this->timer  = self::PAUSE;
        $this->step   = 0;
        $this->pos    = 0;
        $this->flash  = -1;
        $this->flashT = 0;
        $this->wrong  = -1;
    }

    /** Новая случайная последовательность раунда: FIRST шагов в первом, на шаг больше в каждом следующем. */
    private function deal(): void {
        $this->seq = [];
        for ($i = 0; $i < self::FIRST + $this->round; $i++) $this->seq[] = $this->rng->int($this->buttons);
    }

    protected function handle(int $code, int $value): void {
        if ($code !== 0 || $this->phase !== self::INPUT || $value < 0 || $value >= $this->buttons) return;

        $this->flash  = $value;
        $this->flashT = self::FLASH;

        if ($value !== $this->seq[$this->pos]) {
            $this->intent(false);
            $this->wrong = $value;
            $this->lose();
            return;
        }

        $this->intent(true);
        $this->score();
        $this->pos++;
        $this->timer = self::TIMEOUT;

        if ($this->pos === count($this->seq)) {
            $this->round++;
            $this->phase = self::DONE;
            $this->timer = self::SUCCESS;
        }
    }

    protected function update(): void {
        if ($this->flashT > 0 && --$this->flashT === 0) $this->flash = -1;

        if ($this->phase === self::WAIT) {
            if (--$this->timer === 0) {
                $this->phase = self::SHOW;
                $this->step  = 0;
                $this->timer = self::onTicks($this->round) + self::offTicks($this->round);
            }
        } elseif ($this->phase === self::SHOW) {
            if (--$this->timer === 0) {
                if (++$this->step === count($this->seq)) {
                    $this->phase = self::INPUT;
                    $this->pos   = 0;
                    $this->timer = self::TIMEOUT;
                } else {
                    $this->timer = self::onTicks($this->round) + self::offTicks($this->round);
                }
            }
        } elseif ($this->phase === self::INPUT) {
            if (--$this->timer === 0) $this->lose();
        } elseif (--$this->timer === 0) {
            $this->deal();
            $this->phase = self::WAIT;
            $this->timer = self::PAUSE;
        }
    }

    protected function dump(): array {
        return array_merge([$this->buttons, $this->rounds, $this->round, $this->phase, $this->timer, $this->step, $this->pos,
            $this->flash, $this->flashT, $this->wrong, count($this->seq)], $this->seq);
    }
}
