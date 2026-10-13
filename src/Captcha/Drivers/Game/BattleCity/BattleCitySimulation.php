<?php

namespace ST_system\Captcha\Drivers\Game\BattleCity;

use ST_system\Captcha\Drivers\Game\Simulation;

/**
 * Зеркало src/sim.js. Нажатие стрелки — поворот и шаг, зажатая — едет дальше, шаг не чаще раза
 * в PLAYER_PERIOD тиков. Поле cols × rows: кирпич и сталь по seed, три вражеских танка. Коды ввода:
 * 0..3 — повернуть и ехать, 5 — остановиться, 4 — выстрел. Реакция — подбитый враг.
 */
final class BattleCitySimulation extends Simulation {

    private const DX            = [0, 1, 0, -1];
    private const DY            = [-1, 0, 1, 0];
    private const BRICK         = 1;
    private const STEEL         = 2;
    private const PLAYER_PERIOD = 4;
    private const ENEMY_PERIOD  = 6;
    private const BULLET_PERIOD = 2;
    private const RESPAWN       = 40;
    private const FIRE          = 40;
    private const CALM          = 40;

    private array $map     = [];
    private array $player  = [];
    private array $enemies = [];
    private array $bullets = [];
    private array $spawns  = [];
    private int $clock     = 0;
    private int $cols      = 20;
    private int $rows      = 11;
    private int $mid       = 10;

    protected function setup(): void {
        $cols = $this->cols = (int)($this->params['cols'] ?? 0) ?: 20;
        $rows = $this->rows = (int)($this->params['rows'] ?? 0) ?: 11;
        $mid  = $this->mid  = intdiv($cols, 2);

        $this->spawns = [[0, 0], [$mid, 0], [$cols - 1, 0]];

        for ($y = 0; $y < $rows; $y++)
            for ($x = 0; $x < $cols; $x++) {
                $near = $x >= $mid - 1 && $x <= $mid + 1;
                $safe = $y === 0 || $y === $rows - 1
                    || ($y === $rows - 2 && $near)
                    || ($y === 1 && ($x <= 1 || $x >= $cols - 2 || $near));

                if ($safe) { $this->map[] = 0; continue; }

                $r = $this->rng->int(100);
                $this->map[] = $r < 22 ? self::BRICK : ($r < 27 ? self::STEEL : 0);
            }
    }

    protected function restart(): void {
        $this->player  = [$this->mid, $this->rows - 1, 0, 0, 0, 0];
        $this->enemies = array_map(static fn(array $s): array => [$s[0], $s[1], 2, 1, 0], $this->spawns);
        $this->bullets = [];
        $this->clock   = 0;
    }

    protected function handle(int $code, int $value): void {
        $p = $this->player;

        if ($code >= 0 && $code <= 3) {
            // Осмысленно — повернуть к ближайшему врагу.
            $e = $code !== $p[2] ? $this->nearest() : null;
            if ($e) $this->intent(($code === 0 && $e[1] < $p[1]) || ($code === 1 && $e[0] > $p[0])
                || ($code === 2 && $e[1] > $p[1]) || ($code === 3 && $e[0] < $p[0]));

            $this->player[2] = $code;
            $this->player[3] = 1;
            $this->player[4] = 1;
        } elseif ($code === 5) {
            $this->player[3] = 0;
        } elseif ($code === 4 && !$this->armed(-1)) {
            // Осмысленно — стрелять, когда враг на линии огня; расчистка кирпича не в счёт ни так, ни так.
            $sight = $this->inSight();
            if ($sight !== 2) $this->intent($sight === 1);
            $this->bullets[] = [$p[0], $p[1], $p[2], -1];
        }
    }

    private function nearest(): ?array {
        $best = null;
        $dist = PHP_INT_MAX;

        foreach ($this->enemies as $e) {
            if (!$e[3]) continue;
            $d = abs($e[0] - $this->player[0]) + abs($e[1] - $this->player[1]);
            if ($d < $dist) { $dist = $d; $best = $e; }
        }

        return $best;
    }

    /**
     * Что впереди по направлению ствола: 1 — живой враг (кирпич между не мешает, сталь мешает),
     * 2 — первым на пути кирпич и врага за ним нет, 0 — мимо.
     */
    private function inSight(): int {
        $d     = $this->player[2];
        $x     = $this->player[0];
        $y     = $this->player[1];
        $brick = false;

        for (;;) {
            $x += self::DX[$d];
            $y += self::DY[$d];
            if ($x < 0 || $y < 0 || $x >= $this->cols || $y >= $this->rows || $this->map[$y * $this->cols + $x] === self::STEEL) return $brick ? 2 : 0;
            if ($this->map[$y * $this->cols + $x] === self::BRICK && !$brick) $brick = true;
            foreach ($this->enemies as $e) if ($e[3] && $e[0] === $x && $e[1] === $y) return 1;
        }
    }

    private function armed(int $owner): bool {
        foreach ($this->bullets as $b) if ($b[3] === $owner) return true;

        return false;
    }

    private function free(int $x, int $y): bool {
        if ($x < 0 || $y < 0 || $x >= $this->cols || $y >= $this->rows || $this->map[$y * $this->cols + $x]) return false;
        if ($this->player[0] === $x && $this->player[1] === $y) return false;
        foreach ($this->enemies as $e) if ($e[3] && $e[0] === $x && $e[1] === $y) return false;

        return true;
    }

    protected function update(): void {
        $this->clock++;

        if ($this->clock % self::BULLET_PERIOD === 0 && !$this->flyBullets()) return;

        if ($this->player[5] > 0) $this->player[5]--;

        $p = $this->player;

        if (($p[3] || $p[4]) && $p[5] === 0) {
            if ($this->free($p[0] + self::DX[$p[2]], $p[1] + self::DY[$p[2]])) {
                $this->player[0] += self::DX[$p[2]];
                $this->player[1] += self::DY[$p[2]];
            }
            $this->player[4] = 0;
            $this->player[5] = self::PLAYER_PERIOD;
        }

        $count = count($this->enemies);

        for ($i = 0; $i < $count; $i++) {
            $e = $this->enemies[$i];

            if (!$e[3]) {
                if (--$this->enemies[$i][4] <= 0) {
                    $s = $this->spawns[$this->rng->int(count($this->spawns))];
                    if ($this->free($s[0], $s[1])) $this->enemies[$i] = [$s[0], $s[1], 2, 1, 0];
                }
                continue;
            }

            if ($this->clock % self::ENEMY_PERIOD === 0) {
                $turn = $this->rng->int(4) === 0;
                if ($turn || !$this->free($e[0] + self::DX[$e[2]], $e[1] + self::DY[$e[2]])) $e[2] = $this->rng->int(4);
                if ($this->free($e[0] + self::DX[$e[2]], $e[1] + self::DY[$e[2]])) {
                    $e[0] += self::DX[$e[2]];
                    $e[1] += self::DY[$e[2]];
                }
                $this->enemies[$i] = $e;
            }

            if ($this->clock > self::CALM && $this->rng->int(self::FIRE) === 0 && !$this->armed($i)) $this->bullets[] = [$e[0], $e[1], $e[2], $i];
        }

        $kept = [];

        foreach ($this->bullets as $b) {
            $hit = $this->hit($b);
            if ($hit === 2) { $this->bullets = []; $this->lose(); return; }
            if ($hit === 0) $kept[] = $b;
        }

        $this->bullets = $kept;
    }

    /** false — игрок подбит. */
    private function flyBullets(): bool {
        $kept = [];

        foreach ($this->bullets as $b) {
            $b[0] += self::DX[$b[2]];
            $b[1] += self::DY[$b[2]];

            if ($b[0] < 0 || $b[1] < 0 || $b[0] >= $this->cols || $b[1] >= $this->rows) continue;

            $tile = $this->map[$b[1] * $this->cols + $b[0]];
            if ($tile === self::BRICK) { $this->map[$b[1] * $this->cols + $b[0]] = 0; continue; }
            if ($tile === self::STEEL) continue;

            $hit = $this->hit($b);
            if ($hit === 2) { $this->bullets = []; $this->lose(); return false; }
            if ($hit === 0) $kept[] = $b;
        }

        $this->bullets = $kept;

        return true;
    }

    /** 0 — мимо, 1 — подбит враг (пуля израсходована), 2 — подбит игрок. */
    private function hit(array $b): int {
        if ($b[3] < 0) {
            foreach ($this->enemies as $i => $e)
                if ($e[3] && $e[0] === $b[0] && $e[1] === $b[1]) {
                    $this->enemies[$i][3] = 0;
                    $this->enemies[$i][4] = self::RESPAWN;
                    $this->score();
                    return 1;
                }

            return 0;
        }

        return $this->player[0] === $b[0] && $this->player[1] === $b[1] ? 2 : 0;
    }

    protected function dump(): array {
        $out = array_merge([$this->cols, $this->rows, $this->clock], $this->player);
        foreach ($this->enemies as $e) $out = array_merge($out, $e);
        $out[] = count($this->bullets);
        foreach ($this->bullets as $b) $out = array_merge($out, $b);

        return array_merge($out, $this->map);
    }
}
