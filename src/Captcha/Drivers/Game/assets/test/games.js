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
    tetris:     { dir: 'Tetris',     cls: 'TetrisSim',     tps: 20, goal: 1, goalRatio: 70, codes: [0, 1, 2, 3] },
    pacman:     { dir: 'PacMan',     cls: 'PacManSim',     tps: 10, goal: 2, codes: [0, 1, 2, 3], layout: { cols: 19, rows: 11 } },
    battlecity: { dir: 'BattleCity', cls: 'BattleCitySim', tps: 20, goal: 2, codes: [0, 1, 2, 3, 4, 5], layout: { cols: 20, rows: 11 } },
    dino:       { dir: 'Dino',       cls: 'DinoSim',       tps: 30, goal: 3, codes: [0], layout: { w: 267 } },
    invaders:   { dir: 'Invaders',   cls: 'InvadersSim',   tps: 30, goal: 3, codes: [0, 1], values: [11, 345], layout: { w: 356 } },
    platformer: { dir: 'Platformer', cls: 'PlatformerSim', tps: 30, goal: 1, codes: [1, 3, 4, 5], layout: { cols: 20, rows: 11 } },
    minesweeper: { dir: 'Minesweeper', cls: 'MinesweeperSim', tps: 10, goal: 6, maxTime: 60, codes: [0, 1, 2], values: [0, 54], layout: { cols: 9, rows: 6, mines: 8 } },
    simon:      { dir: 'Simon', cls: 'SimonSim', tps: 20, goal: 9, maxTime: 60, collect: false, codes: [0], values: [0, 4], layout: { buttons: 4, rounds: 2 } },
    memory:     { dir: 'Memory', cls: 'MemorySim', tps: 10, goal: 8, goalRatio: 50, maxTime: 90, collect: false, codes: [0], values: [0, 16], layout: { pairs: 8, lives: 4, show: 50 } },
    fruitninja: { dir: 'FruitNinja', cls: 'FruitNinjaSim', tps: 30, goal: 6, codes: [0, 0, 0, 0, 1], values: [0, 267 * 160], layout: { w: 267 } },
    pinball:    { dir: 'Pinball', cls: 'PinballSim', tps: 60, goal: 3, goalRatio: 70, maxTime: 40, codes: [0, 1, 2, 3, 4, 5] },
    racing:     { dir: 'Racing', cls: 'RacingSim', tps: 30, goal: 8, maxTime: 90, collect: false, codes: [0], values: [0, 16], layout: { laps: 1 } },
    puzzle: {
        dir: 'Puzzle', cls: 'PuzzleSim', tps: 10, goal: 9, goalRatio: 0, collect: false, maxTime: 60, required: true,
        codes: [0, 1], values: [0, 9 * 128],
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
    tetris:     { actions: 12, quality: [0.55, 0.85] },
    pacman:     { actions: 12, quality: [0.53, 0.80] },
    battlecity: { actions: 20, quality: [0.50, 0.75] },
    dino:       { actions: 4,  quality: [0.45, 0.80] },
    invaders:   { actions: 16, quality: [0.50, 0.80] },
    platformer: { actions: 8,  quality: [0.55, 0.80] },
    minesweeper: { time: 12, actions: 6, quality: [0.45, 0.85] },
    simon:      { quality: [0.45, 0.85] },
    memory:     { quality: [0.25, 0.65] },
    fruitninja: { actions: 6, quality: [0.40, 0.80] },
    pinball:    { time: 15, actions: 8, quality: [0.55, 0.90] },
    racing:     { quality: [0.60, 0.90] },
};

/** Порог сбора данных, как его отдаёт браузеру PHP; null — порога нет (пазл). */
export function collectFor(name) {
    if (GAMES[name] && GAMES[name].collect === false) return null;
    return { time: (OWN[name] || {}).time || COLLECT.time, actions: (OWN[name] || {}).actions || COLLECT.actions };
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
