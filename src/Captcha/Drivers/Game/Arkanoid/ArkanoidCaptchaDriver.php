<?php

namespace ST_system\Captcha\Drivers\Game\Arkanoid;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Арканоид: отбить мяч goal раз подряд, не уронив его. */
class ArkanoidCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return ArkanoidSimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return 2; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки влево и вправо — ракетка',
            'touch' => 'Кнопки ◀ ▶ — ракетка',
        ];
    }

    /** Мир высотой 280 единиц, ширина — по пропорциям игровой зоны: 320 × 200 → 498. */
    protected static function layout(int $w, int $h): array {
        return ['w' => self::clamp(280 * $w / ($h - self::HUD), 200, 1200)];
    }

    /** Случайные нажатия иногда отбивают мяч дважды — цель засчитывается только при высокой доле. */
    protected static function defaultGoalRatio(): float {
        return 0.75;
    }

    /** Ракетка к мячу — у человека почти всегда; случайные нажатия набирают до 65 %. */
    protected static function defaultCollect(): array {
        return ['actions' => 14] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.65, 0.92];
    }
}
