<?php

namespace ST_system\Captcha\Drivers\Game\Orbit;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/**
 * Орбиты: рогаткой с любой точки поля ракета перелетает между тремя планетами, гравитация гнёт
 * траекторию, орбиты подхватывают ракету. Цель — goal перелётов на орбиту другой планеты за одну
 * жизнь. Пока тянут рогатку, игра замедлена, подсказка показывает траекторию.
 */
class OrbitCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return OrbitSimulation::class; }
    protected static function tps(): int           { return 60; }
    protected static function defaultGoal(): int   { return 3; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Потяните мышью в любом месте поля и отпустите — ракета полетит в обратную сторону',
            'touch' => 'Потяните пальцем в любом месте поля и отпустите — ракета полетит в обратную сторону',
        ];
    }

    /**
     * Короткая сторона игровой зоны — 1000 единиц, длинная — по пропорциям: 320 × 200 → 1778 × 1000.
     * Зона выше, чем шире, — планеты встают вертикальной раскладкой.
     */
    protected static function layout(int $w, int $h): array {
        $short = max(1, min($w, $h - self::HUD));

        return [
            'w' => self::clamp(1000 * $w / $short, 1000, 4000),
            'h' => self::clamp(1000 * ($h - self::HUD) / $short, 1000, 4000),
        ];
    }

    /** Перелёт — пара секунд, прицеливание замедлено: на партию нужно больше времени. */
    protected static function defaultMaxTime(): int {
        return 40;
    }

    /**
     * Запусков за партию — по одному на перелёт; восемь — чтобы случайные выстрелы не набрали долю
     * удачных везением: при четырёх два попадания из четырёх проходили в 6 % случайных партий.
     */
    protected static function defaultCollect(): array {
        return ['time' => 12, 'actions' => 8];
    }

    /** Случайный выстрел ложится на чужую орбиту в ~8 % случаев, человек с подсказкой — чаще половины. */
    protected static function defaultQuality(): array {
        return [0.25, 0.75];
    }
}
