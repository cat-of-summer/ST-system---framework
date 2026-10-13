<?php

namespace ST_system\Captcha\Drivers\Game\Tetris;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Тетрис 8×14 с рядом мусора внизу, без мгновенного сброса: убрать goal линий в одной партии. */
class TetrisCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return TetrisSimulation::class; }
    protected static function tps(): int           { return 20; }
    protected static function defaultGoal(): int   { return 1; }

    /** Ряд мусора с дыркой в три клетки закрывается и случайно — цель засчитывается только при высокой доле. */
    protected static function defaultGoalRatio(): float {
        return 0.7;
    }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки — двигать, вверх или пробел — повернуть',
            'touch' => 'Крестовина — двигать, вверх или большая кнопка — повернуть',
        ];
    }

    /** Осмысленные действия — сдвиги к лучшему месту для фигуры; поворот и шаг вниз не в счёт. */
    protected static function defaultCollect(): array {
        return ['actions' => 12] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.55, 0.85];
    }
}
