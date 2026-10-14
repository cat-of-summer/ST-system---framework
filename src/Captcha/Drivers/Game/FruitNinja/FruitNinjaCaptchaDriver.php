<?php

namespace ST_system\Captcha\Drivers\Game\FruitNinja;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/** Fruit Ninja: разрезать goal фигур, не упустив трёх. На десктопе клинок ведёт курсор, на телефоне — палец. */
class FruitNinjaCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return FruitNinjaSimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return 6; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Ведите курсором по фигурам, чтобы разрезать их',
            'touch' => 'Проводите пальцем по фигурам, чтобы разрезать их',
        ];
    }

    /** Мир высотой 150 единиц, ширина — по пропорциям игровой зоны: 320 × 200 → 267. */
    protected static function layout(int $w, int $h): array {
        return ['w' => self::clamp(150 * $w / ($h - self::HUD), 120, 600)];
    }

    /** Взмахов за партию немного: по одному на фигуру или залп. */
    protected static function defaultCollect(): array {
        return ['actions' => 6] + parent::defaultCollect();
    }

    protected static function defaultQuality(): array {
        return [0.40, 0.80];
    }
}
