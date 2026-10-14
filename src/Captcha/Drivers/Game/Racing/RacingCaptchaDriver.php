<?php

namespace ST_system\Captcha\Drivers\Game\Racing;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/**
 * Гонка с видом сверху: проехать `laps` кругов (1…3) по замкнутой трассе от seed. Цель — чекпоинты
 * этих кругов (8 на круг), ключ `goal` её не меняет. Очки на экране — время круга.
 */
class RacingCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return RacingSimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return RacingSimulation::CHECKS; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки: вверх — газ, вниз — тормоз, влево и вправо — руль',
            'touch' => 'Слева — газ и тормоз, справа — руль',
        ];
    }

    /** Круг у человека — 20-30 секунд; лимит — и на три круга. */
    protected static function defaultMaxTime(): int {
        return 90;
    }

    /** Порога сбора данных нет: ответ — когда круги пройдены или вышло время. */
    protected static function defaultCollect(): array {
        return [];
    }

    /** Случайный руль поворачивает в сторону трассы через раз, человек — почти всегда. */
    protected static function defaultQuality(): array {
        return [0.60, 0.90];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), ['laps' => 1]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->attributes['laps'] = 1;
        $this->applyLaps($config);
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        $this->applyLaps($override);
    }

    private function applyLaps(array $config): void {
        if (isset($config['laps'])) $this->attributes['laps'] = max(1, min(3, (int)$config['laps']));
    }

    protected function params(): array {
        return parent::params() + ['laps' => (int)$this->attributes['laps']];
    }

    protected function prepare(array $params, int $seed): array {
        return ['goal' => RacingSimulation::CHECKS * (int)$this->attributes['laps']];
    }
}
