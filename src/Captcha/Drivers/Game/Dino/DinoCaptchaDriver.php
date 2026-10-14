<?php

namespace ST_system\Captcha\Drivers\Game\Dino;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** DinoRun: перепрыгнуть goal препятствий на ускоряющейся карте. */
class DinoCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return DinoSimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return 3; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Пробел или стрелка вверх — прыжок',
            'touch' => 'Большая кнопка — прыжок',
        ];
    }

    /** Мир высотой 150 единиц, ширина — по пропорциям игровой зоны: 320 × 200 → 267. */
    protected static function layout(int $w, int $h): array {
        return ['w' => self::clamp(150 * $w / ($h - self::HUD), 120, 700)];
    }

    /** Прыжков немного — по одному на препятствие. */
    protected static function defaultCollect(): array {
        return ['actions' => 4] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.45, 0.80];
    }
}
