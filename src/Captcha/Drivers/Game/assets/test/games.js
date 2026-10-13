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
        dir: 'Puzzle', cls: 'PuzzleSim', tps: 10, goal: 9, goalRatio: 0, collect: false, maxTime: 60, required: true,
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

const COLLECT = { time: 8, actions: 10 };

// Свои пороги у игр (defaultCollect() и defaultQuality() в PHP): действий больше там, где они
// частые; доля осмысленных у случайных нажатий и у человека — своя у каждой игры.
const OWN = {
    snake:      { actions: 12, quality: [0.45, 0.80] },
    flappy:     { actions: 20, quality: [0.52, 0.80] },
    arkanoid:   { actions: 14, quality: [0.65, 0.92] },
    tetris:     { actions: 12, quality: [0.45, 0.80] },
    pacman:     { actions: 12, quality: [0.53, 0.80] },
    battlecity: { actions: 20, quality: [0.50, 0.75] },
};

/** Порог сбора данных, как его отдаёт браузеру PHP; null — порога нет (пазл). */
export function collectFor(name) {
    if (GAMES[name] && GAMES[name].collect === false) return null;
    return { time: COLLECT.time, actions: (OWN[name] || {}).actions || COLLECT.actions };
}

/** Доли осмысленных действий: [случайные нажатия, человек] — оценка осмысленности 0 и 1. */
export function qualityFor(name) {
    return (OWN[name] || {}).quality || [0.45, 0.80];
}

/** Взвешенное решение по умолчанию (decision в CaptchaManager): итог = 0.6 · игра + 0.4 · поведение ≥ 0.6. */
export const DECISION = { task: 0.6, behavior: 0.4, pass: 0.6, floor: 0.2 };

export function decide(task, behavior) {
    const combined = (DECISION.task * task + DECISION.behavior * behavior) / (DECISION.task + DECISION.behavior);
    return task >= DECISION.floor && combined >= DECISION.pass;
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
