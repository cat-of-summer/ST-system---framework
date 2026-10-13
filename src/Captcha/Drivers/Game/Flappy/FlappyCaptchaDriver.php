<?php

namespace ST_system\Captcha\Drivers\Game\Flappy;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Flappy Bird: пролететь goal труб за одну жизнь. */
class FlappyCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return FlappySimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return 3; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Пробел или стрелка вверх — взмах',
            'touch' => 'Кнопка слева или тап по полю — взмах',
        ];
    }

    /** Мир высотой 300 единиц, ширина — по пропорциям игровой зоны: 320 × 200 → 533. */
    protected static function layout(int $w, int $h): array {
        return ['w' => self::clamp(300 * $w / ($h - self::HUD), 200, 1200)];
    }

    /** Взмахов много и они ритмичные: выборка больше, доля выше. */
    protected static function defaultFallback(): array {
        return ['actions' => 20, 'ratio' => 0.65] + parent::defaultFallback();
    }
}
