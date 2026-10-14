<?php

namespace ST_system\Captcha\Drivers\Game\Simon;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/**
 * Simon: повторить показанную последовательность. Каждый раунд — новая случайная: в первом 4 шага,
 * в каждом следующем на шаг больше. Ключи: `buttons` — секторов на круге (3…6), `rounds` — сколько
 * раундов повторить; цель — верные нажатия за эти раунды (4 + 5 + …), ключ `goal` её не меняет.
 */
class SimonCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return SimonSimulation::class; }
    protected static function tps(): int           { return 20; }
    protected static function defaultGoal(): int   { return self::stepsFor(2); }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Запомните, что загорится, и повторите: клик по кругу, стрелки или цифры',
            'touch' => 'Запомните, что загорится, и повторите касаниями',
        ];
    }

    /** Раунды ждут показа: партия дольше, чем у игр на реакцию. */
    protected static function defaultMaxTime(): int {
        return 60;
    }

    /** Порога сбора данных нет: ответ — когда повторены все раунды или вышло время. */
    protected static function defaultCollect(): array {
        return [];
    }

    protected static function defaultQuality(): array {
        return [0.45, 0.85];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'buttons' => 4,
            'rounds'  => 2,
        ]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->attributes['buttons'] = 4;
        $this->attributes['rounds']  = 2;
        $this->applySimon($config);
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        $this->applySimon($override);
    }

    private function applySimon(array $config): void {
        if (isset($config['buttons'])) $this->attributes['buttons'] = max(3, min(6, (int)$config['buttons']));
        if (isset($config['rounds']))  $this->attributes['rounds']  = max(1, min(5, (int)$config['rounds']));
    }

    /** Верных нажатий за rounds раундов: 4 + 5 + … */
    public static function stepsFor(int $rounds): int {
        $steps = 0;
        for ($r = 0; $r < $rounds; $r++) $steps += SimonSimulation::FIRST + $r;

        return $steps;
    }

    protected function params(): array {
        return parent::params() + [
            'buttons' => (int)$this->attributes['buttons'],
            'rounds'  => (int)$this->attributes['rounds'],
        ];
    }

    protected function prepare(array $params, int $seed): array {
        return ['goal' => self::stepsFor((int)$this->attributes['rounds'])];
    }
}
