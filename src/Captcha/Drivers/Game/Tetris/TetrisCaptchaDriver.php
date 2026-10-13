<?php

namespace ST_system\Captcha\Drivers\Game\Tetris;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Тетрис 8×14 с рядом мусора внизу: убрать goal линий в одной партии. */
class TetrisCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return TetrisSimulation::class; }
    protected static function tps(): int           { return 20; }
    protected static function defaultGoal(): int   { return 1; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки — двигать, вверх — сбросить, пробел — повернуть',
            'touch' => 'Крестовина — двигать, вверх — сбросить, большая кнопка — повернуть',
        ];
    }

    /** Осмысленные действия считаются по фигурам, а не по нажатиям. */
    protected static function defaultFallback(): array {
        return ['actions' => 12] + parent::defaultFallback();
    }
}
