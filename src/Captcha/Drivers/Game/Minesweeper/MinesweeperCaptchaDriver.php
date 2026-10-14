<?php

namespace ST_system\Captcha\Drivers\Game\Minesweeper;

use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;

/**
 * Сапёр: сделать goal безопасных ходов, открывающих клетки (первый клик не в счёт — он наугад).
 * Поле — ключи `cols`, `rows`, `mines`; на вертикальном холсте стороны меняются местами.
 */
class MinesweeperCaptchaDriver extends GameCaptchaDriver {

    protected static function simulation(): string { return MinesweeperSimulation::class; }
    protected static function tps(): int           { return 10; }
    protected static function defaultGoal(): int   { return 6; }

    protected static function defaultHint(): array {
        return [
            'keys'  => 'Клик — открыть, правая кнопка — флажок; или стрелки, пробел и F',
            'touch' => 'Тап — открыть, долгое нажатие — флажок',
        ];
    }

    /** Партия идёт, пока не собраны данные или не открыто поле. */
    protected static function defaultMaxTime(): int {
        return 60;
    }

    protected static function defaultCollect(): array {
        return ['time' => 12, 'actions' => 6];
    }

    protected static function defaultQuality(): array {
        return [0.45, 0.85];
    }

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'cols'  => 9,
            'rows'  => 6,
            'mines' => 8,
        ]);
    }

    protected function __init(array $config): void {
        parent::__init($config);

        $this->attributes['cols']  = 9;
        $this->attributes['rows']  = 6;
        $this->attributes['mines'] = 8;
        $this->applyBoard($config);
    }

    protected function __rebind(array $override): void {
        parent::__rebind($override);

        $this->applyBoard($override);
    }

    private function applyBoard(array $config): void {
        if (isset($config['cols']))  $this->attributes['cols']  = max(5, min(16, (int)$config['cols']));
        if (isset($config['rows']))  $this->attributes['rows']  = max(4, min(12, (int)$config['rows']));
        if (isset($config['mines'])) $this->attributes['mines'] = max(2, (int)$config['mines']);
    }

    /** Поле под холст: на вертикальной игровой зоне длинная сторона идёт вниз; мин не больше трети клеток. */
    protected function params(): array {
        [$w, $h] = $this->canvasSize();

        $cols = (int)$this->attributes['cols'];
        $rows = (int)$this->attributes['rows'];
        if (($h - self::HUD > $w) !== ($rows > $cols)) [$cols, $rows] = [$rows, $cols];

        return parent::params() + [
            'cols'  => $cols,
            'rows'  => $rows,
            'mines' => min((int)$this->attributes['mines'], intdiv($cols * $rows, 3)),
        ];
    }
}
