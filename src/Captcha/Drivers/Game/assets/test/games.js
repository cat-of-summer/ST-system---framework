// Игры для фикстур и тестов. tps, goal, пороги запасного зачёта, время партии и раскладка поля
// под холст по умолчанию (320 × 200) — те же, что в PHP-драйверах (tps(), defaultGoal(),
// defaultFallback(), defaultMaxTime(), layout()); PHPUnit сверяет их с фикстурами.
import { Rng } from '../engine/src/rng.js';

/** Перестановка пазла для фикстур: от seed, как Фишер–Йетс на сервере (там — random_int). */
function puzzleOrder(seed, n) {
    const rng   = new Rng(seed ^ 0x51515151);
    const order = [];
    for (let i = 0; i < n; i++) order.push(i);
    for (let i = n - 1; i > 0; i--) {
        const j = rng.int(i + 1);
        const t = order[i]; order[i] = order[j]; order[j] = t;
    }
    return order;
}

export const GAMES = {
    snake:      { dir: 'Snake',      cls: 'SnakeSim',      tps: 24, goal: 2, codes: [0, 1, 2, 3], layout: { cols: 16, rows: 9 } },
    flappy:     { dir: 'Flappy',     cls: 'FlappySim',     tps: 30, goal: 3, codes: [0], layout: { w: 533 } },
    arkanoid:   { dir: 'Arkanoid',   cls: 'ArkanoidSim',   tps: 30, goal: 2, goalRatio: 75, codes: [0], values: [24, 474], layout: { w: 498 } },
    tetris:     { dir: 'Tetris',     cls: 'TetrisSim',     tps: 20, goal: 1, codes: [0, 1, 2, 3, 4] },
    pacman:     { dir: 'PacMan',     cls: 'PacManSim',     tps: 10, goal: 2, codes: [0, 1, 2, 3], layout: { cols: 19, rows: 11 } },
    battlecity: { dir: 'BattleCity', cls: 'BattleCitySim', tps: 20, goal: 2, codes: [0, 1, 2, 3, 4, 5], layout: { cols: 20, rows: 11 } },
    puzzle: {
        dir: 'Puzzle', cls: 'PuzzleSim', tps: 10, goal: 9, goalRatio: 0, fallback: false, maxTime: 60,
        codes: [0, 1], values: [0, 9 * 64],
        // Сервер отдаёт клиенту rows/cols, а order — только своей копии игры.
        params: function (seed) { return { per_life: true, rows: 3, cols: 3, order: puzzleOrder(seed, 9) }; },
        publicParams: { per_life: true, rows: 3, cols: 3 },
    },
};

/** Параметры обеих копий игры, как их выдаёт сервер (у пазла сервер добавляет себе order). */
export function paramsFor(name, seed) {
    const game = GAMES[name];
    return game.params ? game.params(seed) : Object.assign({ per_life: true }, game.layout);
}

/** Параметры, которые видит браузер. */
export function publicParamsFor(name) {
    const game = GAMES[name];
    return game.publicParams || Object.assign({ per_life: true }, game.layout);
}

const FALLBACK = { time: 8, actions: 10, ratio: 60 };

// Свои пороги у игр (defaultFallback() в PHP): действий больше там, где они частые, доля выше
// там, где у человека она заметно выше, чем у случайных нажатий.
const OWN = {
    snake:      { actions: 12 },
    flappy:     { actions: 20, ratio: 65 },
    arkanoid:   { actions: 14, ratio: 75 },
    tetris:     { actions: 12 },
    pacman:     { actions: 12, ratio: 65 },
    battlecity: { actions: 20, ratio: 65 },
};

/** Пороги запасного зачёта, как их отдаёт клиенту PHP: ratio — в процентах; null — выключен. */
export function fallbackFor(name) {
    if (GAMES[name] && GAMES[name].fallback === false) return null;
    return Object.assign({}, FALLBACK, OWN[name] || {});
}

/** Порог сбора данных, который видит браузер: время и число действий из запасного зачёта. */
export function collectFor(name) {
    const fallback = fallbackFor(name);
    return fallback ? { time: fallback.time, actions: fallback.actions } : null;
}

/** Секунд на партию (max_time в PHP): дальше браузер отдаёт то, что набрано. */
export function maxTimeFor(name) {
    return GAMES[name].maxTime || 20;
}

/** Минимальная доля осмысленных действий для зачёта по цели, % (goal_ratio в PHP). */
export const GOAL_RATIO = 60;

export function goalRatioFor(name) {
    return GAMES[name] && GAMES[name].goalRatio !== undefined ? GAMES[name].goalRatio : GOAL_RATIO;
}
