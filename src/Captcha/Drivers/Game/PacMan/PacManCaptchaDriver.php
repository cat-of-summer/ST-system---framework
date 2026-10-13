<?php

namespace ST_system\Captcha\Drivers\Game\PacMan;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Пакман: съесть goal вишен, не попавшись призракам. */
class PacManCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return PacManSimulation::class; }
    protected static function tps(): int           { return 10; }
    protected static function defaultGoal(): int   { return 2; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки или WASD — куда свернуть',
            'touch' => 'Крестовина — куда свернуть',
        ];
    }

    /** Клетка около 16 px, стороны лабиринта нечётные: 320 × 200 → 19 × 11. */
    protected static function layout(int $w, int $h): array {
        $cols = self::clamp($w / 16, 7, 41);
        $rows = self::clamp(($h - self::HUD) / 16, 7, 41);

        return ['cols' => $cols - 1 + $cols % 2, 'rows' => $rows - 1 + $rows % 2];
    }

    /** Повороты только на развилках — их немного; случайные попадают «к вишне» чаще, чем в других играх. */
    protected static function defaultCollect(): array {
        return ['actions' => 12] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.53, 0.80];
    }
}
