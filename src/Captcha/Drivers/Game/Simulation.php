<?php

namespace ST_system\Captcha\Drivers\Game;

/**
 * Серверная копия игры: прогоняет лог вводов и считает реакции. Зеркало assets/engine/src/sim.js —
 * каждая игра пишется дважды, и обе версии обязаны совпадать до бита: только целые числа,
 * деление через intdiv, случайность только из Rng.
 *
 * Жизнь: idle (ждёт первого ввода) → игра → lose() → dead тиков паузы → restart() → idle.
 * При params.per_life (по умолчанию) смерть обнуляет reactions, зачёт идёт по peak — лучшему
 * результату за одну жизнь; без него счёт копится через смерти.
 *
 * acts/good — запасной зачёт: сколько было действий, по которым видно намерение (intent()),
 * и сколько из них были ответом на ситуацию.
 */
abstract class Simulation {

    public int $tick      = 0;
    public int $reactions = 0;
    public int $peak      = 0;
    public int $dead      = 0;
    public int $idle      = 1;
    public int $acts      = 0;
    public int $good      = 0;

    protected Rng $rng;
    protected array $params;
    protected int $perLife;

    final public function __construct(int $seed, array $params = []) {
        $this->rng     = new Rng($seed);
        $this->params  = $params;
        $this->perLife = !array_key_exists('per_life', $params) || $params['per_life'] ? 1 : 0;

        $this->setup();
        $this->restart();
    }

    /** Один раз на партию: то, что переживает смерть (лабиринт, карта). */
    protected function setup(): void {}

    /** Новая жизнь. */
    abstract protected function restart(): void;

    protected function start(): void {}

    abstract protected function handle(int $code, int $value): void;

    abstract protected function update(): void;

    /** Состояние игры целыми числами — для digest(). */
    abstract protected function dump(): array;

    /** Пауза после смерти, в тиках. */
    protected function cooldown(): int {
        return 20;
    }

    /**
     * Код ввода «начать»: клик по кнопке старта или перезапуска. Выводит игру из ожидания, но в
     * игру не уходит и действием не считается.
     */
    public const START = 15;

    final public function input(int $code, int $value = 0): void {
        if ($this->dead > 0) return;

        if ($this->idle) {
            $this->idle = 0;
            $this->start();
        }

        if ($code !== self::START) $this->handle($code, $value);
    }

    final public function advance(): void {
        if ($this->dead > 0) {
            if (--$this->dead === 0) {
                $this->restart();
                $this->idle = 1;
            }
        } elseif (!$this->idle) {
            $this->update();
        }

        $this->tick++;
    }

    final protected function lose(): void {
        if ($this->perLife) $this->reactions = 0;
        $this->dead = $this->cooldown();
    }

    final protected function score(): void {
        $this->reactions++;
        if ($this->reactions > $this->peak) $this->peak = $this->reactions;
    }

    /** Действие, по которому видно намерение; $good — было ли оно ответом на ситуацию. */
    final protected function intent(bool $good): void {
        $this->acts++;
        if ($good) $this->good++;
    }

    final public function state(): array {
        return array_merge(
            [$this->tick, $this->reactions, $this->peak, $this->dead, $this->idle, $this->acts, $this->good, $this->rng->s],
            $this->dump()
        );
    }

    final public function digest(): int {
        return crc32(implode(',', $this->state()));
    }

    /**
     * Прогон лога, как его записал клиент: на тике N сначала применяются вводы с меткой N,
     * затем шаг. Клиент останавливается, как только peak достиг goal (после ввода или
     * после шага), и присылает tick на этот момент — отсюда проверка до и после шага.
     * goal = 0 — прогнать все $ticks шагов.
     *
     * @param array<int, array{0:int,1:int,2?:int}> $log проверенный GameCaptchaDriver::normalizeLog()
     */
    final public static function replay(int $seed, array $params, array $log, int $ticks, int $goal = 0): self {
        $sim = new static($seed, $params);
        $i   = 0;
        $n   = count($log);

        for ($tick = 0; ; $tick++) {
            while ($i < $n && $log[$i][0] === $tick) {
                $sim->input($log[$i][1], $log[$i][2] ?? 0);
                $i++;
            }

            if (($goal > 0 && $sim->peak >= $goal) || $tick >= $ticks) break;

            $sim->advance();

            if ($goal > 0 && $sim->peak >= $goal) break;
        }

        return $sim;
    }
}
