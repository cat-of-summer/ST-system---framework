<?php

namespace ST_system\Captcha\Drivers\Game\Platformer;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Платформер на один экран: дойти до монетки справа через обрывы и шипы. */
class PlatformerCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return PlatformerSimulation::class; }
    protected static function tps(): int           { return 30; }
    /** Монетка в конце уровня — одна. */
    protected static function defaultGoal(): int   { return 1; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки влево и вправо — идти, пробел или стрелка вверх — прыжок',
            'touch' => 'Кнопки ◀ ▶ — идти, большая кнопка — прыжок',
        ];
    }

    /** Клетка 16 единиц: 320 × 200 → 20 × 11. */
    protected static function layout(int $w, int $h): array {
        return [
            'cols' => self::clamp($w / 16, 18, 40),
            'rows' => self::clamp(($h - self::HUD) / 16, 8, 24),
        ];
    }

    protected static function defaultCollect(): array {
        return ['actions' => 8] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.55, 0.80];
    }
}
