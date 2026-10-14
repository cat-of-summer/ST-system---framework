<?php

namespace ST_system\Captcha\Drivers\Game\Invaders;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Space Invaders: сбить goal противников, не попав под бомбу. */
class InvadersCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return InvadersSimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return 3; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки влево и вправо — корабль, пробел — огонь',
            'touch' => 'Кнопки ◀ ▶ — корабль, большая кнопка — огонь',
        ];
    }

    /** Мир высотой 200 единиц, ширина — по пропорциям игровой зоны: 320 × 200 → 356. */
    protected static function layout(int $w, int $h): array {
        return ['w' => self::clamp(200 * $w / ($h - self::HUD), 160, 900)];
    }

    /** Манёвров и выстрелов много: выборка больше. */
    protected static function defaultCollect(): array {
        return ['actions' => 16] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.50, 0.80];
    }
}
