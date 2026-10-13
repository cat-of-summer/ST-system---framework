<?php

namespace ST_system\Captcha\Drivers\Game\BattleCity;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Battle City: подбить goal вражеских танков за одну жизнь. */
class BattleCityCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return BattleCitySimulation::class; }
    protected static function tps(): int           { return 20; }
    protected static function defaultGoal(): int   { return 2; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки — шаг и поворот, пробел — огонь',
            'touch' => 'Кнопки справа — шаг и поворот, кнопка слева или тап — огонь',
        ];
    }

    /** Клетка около 16 px: 320 × 200 → 20 × 11. */
    protected static function layout(int $w, int $h): array {
        return ['cols' => self::clamp($w / 16, 9, 40), 'rows' => self::clamp(($h - self::HUD) / 16, 7, 40)];
    }

    /** Выстрелов и поворотов много: выборка больше, доля выше. */
    protected static function defaultFallback(): array {
        return ['actions' => 20, 'ratio' => 0.65] + parent::defaultFallback();
    }
}
