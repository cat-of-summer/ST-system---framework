<?php

namespace ST_system\Captcha\Drivers\Game\Snake;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Змейка без стен на сетке под пропорции холста: съесть goal яблок за одну жизнь. */
class SnakeCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return SnakeSimulation::class; }
    /** 24 тика в секунду, шаг раз в 5 тиков — 4,8 клетки в секунду; ввод ловится точнее шага. */
    protected static function tps(): int           { return 24; }
    protected static function defaultGoal(): int   { return 2; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки или WASD — повернуть змейку',
            'touch' => 'Кнопки справа или свайп — повернуть змейку',
        ];
    }

    /** Клетка около 20 px: 320 × 200 → 16 × 9. */
    protected static function layout(int $w, int $h): array {
        return [
            'cols' => self::clamp($w / 20, 8, 32),
            'rows' => self::clamp(($h - self::HUD) / 20, 6, 32),
        ];
    }

    /** Поворотов немного, около одного в секунду: двенадцать набираются примерно за 10 секунд. */
    protected static function defaultFallback(): array {
        return ['actions' => 12] + parent::defaultFallback();
    }
}
