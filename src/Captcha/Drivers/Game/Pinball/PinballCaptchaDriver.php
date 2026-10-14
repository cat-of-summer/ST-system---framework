<?php

namespace ST_system\Captcha\Drivers\Game\Pinball;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Пинбол: отбить мяч флипперами goal раз, не уронив его в слив. */
class PinballCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return PinballSimulation::class; }
    protected static function tps(): int           { return 60; }
    protected static function defaultGoal(): int   { return 3; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Пробел — пуск мяча, стрелки влево и вправо — флипперы',
            'touch' => 'Большая кнопка — пуск мяча, ◀ ▶ — флипперы',
        ];
    }

    /** Мяч сперва летит по бамперам, к флипперам возвращается не сразу. */
    protected static function defaultMaxTime(): int {
        return 40;
    }

    /** Действия — нажатия флипперов, пока мяч в игре: у человека 8 набирается секунд за 15-20. */
    protected static function defaultCollect(): array {
        return ['time' => 15, 'actions' => 8];
    }

    /** Случайное нажатие застаёт мяч у своего флиппера в трети случаев, человек — в четырёх из пяти. */
    protected static function defaultQuality(): array {
        return [0.55, 0.90];
    }

    /** Цель засчитывается, только если и нажимали по делу: отбить можно и случайно. */
    protected static function defaultGoalRatio(): float {
        return 0.7;
    }
}
