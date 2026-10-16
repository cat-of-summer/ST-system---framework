<?php

namespace ST_system\Captcha\Drivers\Game\Asteroids;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/**
 * Астероиды: ракета у левого края уворачивается от астероидов или сбивает их — goal раз за одну
 * жизнь. Ключ `show_hitbox` рисует поверх контуры, по которым считаются столкновения.
 */
class AsteroidsCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return AsteroidsSimulation::class; }
    protected static function tps(): int           { return 30; }
    protected static function defaultGoal(): int   { return 4; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Стрелки вверх и вниз — ракета, пробел — огонь (держите для очереди)',
            'touch' => 'Кнопки ▲ ▼ — ракета, большая кнопка — огонь (держите для очереди)',
        ];
    }

    /** Мир высотой 150 единиц, ширина — по пропорциям игровой зоны: 320 × 200 → 267. */
    protected static function layout(int $w, int $h): array {
        return ['w' => self::clamp(150 * $w / ($h - self::HUD), 120, 600)];
    }

    /** Случайные нажатия осмысленны в 40–55 % случаев, человек — почти всегда. */
    protected static function defaultQuality(): array {
        return [0.50, 0.85];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), ['show_hitbox' => false]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->attributes['show_hitbox'] = false;
        $this->applyHitbox($config);
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        $this->applyHitbox($override);
    }

    private function applyHitbox(array $config): void {
        if (isset($config['show_hitbox'])) $this->attributes['show_hitbox'] = (bool)$config['show_hitbox'];
    }

    /** Отладка хитбоксов — только отрисовке: на игру и проверку не влияет. */
    protected function prepare(array $params, int $seed): array {
        return $this->attributes['show_hitbox'] ? ['public' => ['show_hitbox' => true]] : [];
    }
}
