import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SnakeSim, STEP } from '../../Snake/src/sim.js';
import { FlappySim, FP as FFP, BX, R as FR, PW as FPW, H as FH, GROUND } from '../../Flappy/src/sim.js';
import { ArkanoidSim, FP as AFP, PY, PW as APW, R as AR } from '../../Arkanoid/src/sim.js';
import { TetrisSim, COLS, ROWS } from '../../Tetris/src/sim.js';
import { PacManSim, DX, DY } from '../../PacMan/src/sim.js';
import { BattleCitySim, BRICK, STEEL, PLAYER_PERIOD } from '../../BattleCity/src/sim.js';
import { PuzzleSim, SLOT_BITS } from '../../Puzzle/src/sim.js';

function run(sim, ticks) {
    for (let i = 0; i < ticks; i++) sim.advance();
}

/** Шаг змейки: она двигается раз в STEP тиков. */
function step(sim, steps) {
    run(sim, STEP * (steps || 1));
}

// --- Snake ----------------------------------------------------------------------------------------

test('Snake: стены телепортируют на другую сторону', function () {
    const sim = new SnakeSim(1, {});
    sim.body = [[sim.cols - 1, 5], [sim.cols - 2, 5], [sim.cols - 3, 5]];
    sim.ax = 0; sim.ay = 0;
    sim.input(1);
    step(sim);

    assert.equal(sim.dead, 0);
    assert.deepEqual(sim.body[0], [0, 5]);
});

test('Snake: смерть — только о собственный хвост', function () {
    const sim = new SnakeSim(1, {});
    sim.body = [[2, 2], [2, 3], [1, 3], [1, 2], [0, 2]];
    sim.dir = 0; sim.next = 0;
    sim.ax = 8; sim.ay = 8;
    sim.input(3);
    step(sim);

    assert.ok(sim.dead > 0);
});

test('Snake: яблоко — очко и рост; поворот к яблоку — осмысленный', function () {
    const sim = new SnakeSim(1, {});
    sim.body = [[5, 5], [4, 5], [3, 5]];
    sim.ax = 5; sim.ay = 3;

    sim.input(0);
    assert.deepEqual([sim.acts, sim.good], [1, 1], 'вверх — к яблоку');

    step(sim, 2);
    assert.equal(sim.reactions, 1);
    assert.equal(sim.body.length, 4);

    sim.ax = 0; sim.ay = 0;
    sim.input(1);
    assert.deepEqual([sim.acts, sim.good], [2, 1], 'вправо — от яблока (с учётом телепорта)');

    sim.input(2);
    assert.equal(sim.acts, 2, 'разворот назад игнорируется и не считается');
});

// --- Flappy ---------------------------------------------------------------------------------------

test('Flappy: взмах поднимает, без взмахов — падение на землю', function () {
    const sim = new FlappySim(1, {});
    const y0 = sim.y;

    sim.input(0);
    sim.advance();
    assert.ok(sim.y < y0);

    run(sim, 200);
    assert.ok(sim.dead > 0 || sim.idle, 'птица разбилась');
});

test('Flappy: пройденная труба — очко', function () {
    const sim = new FlappySim(1, {});
    sim.input(0);
    sim.pipes = [[BX - FR - FPW + 2, 40, 0]];
    sim.advance();

    assert.equal(sim.reactions, 1);
    assert.equal(sim.pipes[0][2], 1);
});

test('Flappy: взмах под серединой зазора — осмысленный, над ней — нет', function () {
    const sim = new FlappySim(1, {});
    sim.pipes = [[200, 100, 0]];

    sim.y = 170 * FFP;
    sim.input(0);
    assert.deepEqual([sim.acts, sim.good], [1, 1], 'ниже середины зазора (155)');

    sim.y = 30 * FFP;
    sim.input(0);
    assert.deepEqual([sim.acts, sim.good], [2, 1], 'выше — нет');

    sim.y = (FH - GROUND - 20) * FFP;
    sim.input(0);
    assert.deepEqual([sim.acts, sim.good], [2, 1], 'далеко под зазором — спасается, не в счёт');
});

test('Flappy: разбилась — неосмысленное действие', function () {
    const sim = new FlappySim(1, {});
    sim.input(0);
    run(sim, 200);
    assert.ok(sim.acts > sim.good, 'смерть добавила действие без пользы');
});

// --- Arkanoid -------------------------------------------------------------------------------------

test('Arkanoid: ракетка едет к цели не быстрее 8 px за тик', function () {
    const sim = new ArkanoidSim(1, {});
    const x0  = sim.px;

    sim.input(0, APW / 2);
    sim.advance();
    assert.equal(x0 - sim.px, 8);
});

test('Arkanoid: отбитый мяч — очко; потерянный — смерть', function () {
    const sim = new ArkanoidSim(1, {});
    sim.input(0, sim.px);
    sim.bx = sim.px * AFP;
    sim.by = (PY - AR - 2) * AFP;
    sim.vx = 0;
    sim.vy = 1536;
    sim.advance();

    assert.equal(sim.reactions, 1);
    assert.ok(sim.vy < 0);

    sim.bx = 10 * AFP;
    sim.by = (PY + 40) * AFP;
    sim.vy = 1536;
    sim.input(0, sim.w - APW / 2);
    run(sim, 3);
    assert.ok(sim.dead > 0 || sim.idle);
});

test('Arkanoid: вести ракетку к падающему мячу — осмысленно', function () {
    const sim = new ArkanoidSim(1, {});
    sim.input(0, sim.px);
    sim.vy = 1536;
    sim.bx = 200 * AFP;

    sim.input(0, 180);
    sim.input(0, 40);
    assert.deepEqual([sim.acts, sim.good], [2, 1]);

    sim.vy = -1536;
    sim.input(0, 100);
    assert.equal(sim.acts, 2, 'пока мяч летит вверх, движения не оцениваются');
});

// --- Tetris ---------------------------------------------------------------------------------------

/** Опустить фигуру по клетке до упора и дать ей лечь (сброса нет). */
function land(sim) {
    do sim.input(2); while (!sim.collide(sim.x, sim.y + 1, sim.rot));
    run(sim, 10);
}

test('Tetris: мгновенного сброса нет — код 4 ничего не делает', function () {
    const sim = new TetrisSim(1, {});
    sim.input(2);
    const y = sim.y;
    sim.input(4);
    assert.equal(sim.y, y);
});

test('Tetris: заполненный ряд убирается и даёт очко', function () {
    const sim = new TetrisSim(1, {});
    sim.grid = sim.grid.map(function () { return 0; });
    for (let x = 1; x < COLS; x++) sim.grid[(ROWS - 1) * COLS + x] = 8;
    sim.type = 0; sim.rot = 1; sim.x = -2; sim.y = 0;

    land(sim);
    assert.equal(sim.reactions, 1);
    for (let x = 1; x < COLS; x++) assert.equal(sim.grid[(ROWS - 1) * COLS + x], 0, 'ряд сдвинулся');
    assert.equal(sim.grid[(ROWS - 1) * COLS], 1, 'над рядом осталась часть палки');
});

test('Tetris: поворот у стенки сдвигает фигуру', function () {
    const sim = new TetrisSim(1, {});
    sim.grid = sim.grid.map(function () { return 0; });
    sim.type = 0; sim.rot = 1; sim.x = -2; sim.y = 2;

    sim.input(0);
    assert.equal(sim.rot, 2);
    assert.ok(!sim.collide(sim.x, sim.y, sim.rot));
});

test('Tetris: сдвиг к лучшему месту — осмысленно, от него — нет; поворот не оценивается', function () {
    const sim = new TetrisSim(1, {});
    sim.grid = sim.grid.map(function () { return 0; });
    for (let x = 0; x < COLS - 2; x++) sim.grid[(ROWS - 1) * COLS + x] = 8;
    sim.type = 1; sim.rot = 0; sim.x = 2; sim.y = 0;   // квадрат; линию закрывает только справа
    sim.plan();

    assert.deepEqual(sim.goals.map(function (g) { return g[1]; }).filter(function (x, i, a) { return a.indexOf(x) === i; }), [6]);

    sim.input(1);
    assert.deepEqual([sim.acts, sim.good], [1, 1], 'вправо — к месту');

    sim.input(3);
    assert.deepEqual([sim.acts, sim.good], [2, 1], 'влево — от него');

    sim.input(0);
    assert.equal(sim.acts, 2, 'поворот не оценивается');
});

// --- Pac-Man --------------------------------------------------------------------------------------

test('Pac-Man: вишня — очко и новая вишня', function () {
    const sim = new PacManSim(1, {});
    const [x, y] = [sim.px, sim.py];
    const dir = sim.open(x + 1, y) ? 1 : 3;
    sim.ghosts = [];
    sim.fx = x + (dir === 1 ? 1 : -1); sim.fy = y;

    sim.input(dir);
    run(sim, 2);
    assert.equal(sim.reactions, 1);
    assert.notDeepEqual([sim.fx, sim.fy], [sim.px, sim.py]);
});

test('Pac-Man: встреча с призраком — смерть', function () {
    const sim = new PacManSim(1, {});
    const dir = sim.open(sim.px + 1, sim.py) ? 1 : 3;
    sim.ghosts = [[sim.px + (dir === 1 ? 1 : -1), sim.py, -1]];

    sim.input(dir);
    run(sim, 2);
    assert.ok(sim.dead > 0);
});

test('Pac-Man: осмысленно — свернуть туда, где путь до вишни по лабиринту короче', function () {
    const sim  = new PacManSim(1, {});
    sim.ghosts = [];

    const dist = sim.distances();
    const here = sim.py * sim.cols + sim.px;
    const ways = [0, 1, 2, 3].filter(function (d) { return sim.open(sim.px + DX[d], sim.py + DY[d]); });
    const near = ways.filter(function (d) { return dist[(sim.py + DY[d]) * sim.cols + sim.px + DX[d]] < dist[here]; });
    const far  = ways.filter(function (d) { return near.indexOf(d) < 0; });

    assert.ok(near.length && far.length, 'со старта есть ход ближе и ход дальше');

    sim.input(near[0]);
    assert.deepEqual([sim.acts, sim.good], [1, 1], 'ближе по лабиринту');

    sim.input(far[0]);
    assert.deepEqual([sim.acts, sim.good], [2, 1], 'дальше — нет');
});

test('Pac-Man: рядом с призраком повороты не оцениваются; новая вишня — далеко', function () {
    const sim  = new PacManSim(1, {});
    sim.ghosts = [[sim.px + 1, sim.py, -1]];
    sim.input(0);
    sim.input(1);
    assert.equal(sim.acts, 0, 'уходить от призрака — тоже игра');

    for (let i = 0; i < 20; i++) {
        sim.place();
        assert.ok(Math.abs(sim.fx - sim.px) + Math.abs(sim.fy - sim.py) >= Math.floor((sim.cols + sim.rows) / 3));
    }
});

// --- Battle City ----------------------------------------------------------------------------------

function clearField(sim) {
    sim.map = sim.map.map(function () { return 0; });
    sim.enemies.forEach(function (e) { e[3] = 0; e[4] = 1000; });
}

test('Battle City: пуля разбивает кирпич и гаснет о сталь', function () {
    const sim = new BattleCitySim(1, {});
    clearField(sim);
    const [x, y] = sim.player;
    sim.map[(y - 2) * sim.cols + x] = BRICK;
    sim.map[(y - 4) * sim.cols + x] = STEEL;

    sim.input(4);
    run(sim, 4);
    assert.equal(sim.map[(y - 2) * sim.cols + x], 0, 'кирпич разбит');
    assert.equal(sim.bullets.length, 0);

    sim.input(4);
    run(sim, 8);
    assert.equal(sim.map[(y - 4) * sim.cols + x], STEEL, 'сталь цела');
    assert.equal(sim.bullets.length, 0);
});

test('Battle City: подбитый враг — очко; выстрел по нему — осмысленный', function () {
    const sim = new BattleCitySim(1, {});
    clearField(sim);
    const [x, y] = sim.player;
    sim.enemies[0] = [x, y - 3, 1, 1, 0];

    sim.input(4);
    assert.deepEqual([sim.acts, sim.good], [1, 1]);

    run(sim, 8);
    assert.equal(sim.reactions, 1);
    assert.equal(sim.enemies[0][3], 0);
});

test('Battle City: поворот к ближайшему врагу — осмысленный', function () {
    const sim = new BattleCitySim(1, {});
    clearField(sim);
    const [x, y] = sim.player;
    sim.enemies[0] = [x + 4, y - 1, 2, 1, 0];

    sim.input(1);
    sim.input(3);
    assert.deepEqual([sim.acts, sim.good], [2, 1]);
});

test('Battle City: нажатие — поворот и шаг, даже если сразу отпустили', function () {
    const sim = new BattleCitySim(1, {});
    clearField(sim);
    const [x, y] = sim.player;

    sim.input(3);
    sim.input(5);
    run(sim, 1);
    assert.deepEqual([sim.player[0], sim.player[1], sim.player[2]], [x - 1, y, 3], 'шаг влево');

    run(sim, 10);
    assert.equal(sim.player[0], x - 1, 'отпущено — дальше не едет');
});

test('Battle City: частые нажатия быстрее не везут, зажатая клавиша — едет', function () {
    const sim = new BattleCitySim(1, {});
    clearField(sim);
    const x = sim.player[0];

    for (let i = 0; i < 8; i++) { sim.input(i % 2 ? 1 : 3); sim.input(5); run(sim, 1); }
    assert.ok(Math.abs(sim.player[0] - x) <= 2, 'не больше шага за ' + PLAYER_PERIOD + ' тика');

    const y = sim.player[1];
    sim.input(0);
    run(sim, PLAYER_PERIOD * 3);
    assert.equal(y - sim.player[1], 3, 'зажата — шаг каждые ' + PLAYER_PERIOD + ' тика');
});

test('Battle City: после смерти — чистая жизнь и одна пуля на выстрел', function () {
    const sim = new BattleCitySim(1, {});
    clearField(sim);
    sim.input(4);
    sim.bullets.push([sim.player[0], sim.player[1] - 1, 2, 0]);
    run(sim, 2);
    assert.ok(sim.dead > 0, 'подбит');

    run(sim, sim.dead);
    assert.equal(sim.idle, 1);
    assert.deepEqual(sim.bullets, []);
    assert.deepEqual(sim.player, [sim.mid, sim.rows - 1, 0, 0, 0, 0]);

    sim.input(4);
    sim.input(4);
    assert.equal(sim.bullets.filter(function (b) { return b[3] < 0; }).length, 1);
});

test('Battle City: в начале жизни враги не встречают игрока залпом', function () {
    for (let seed = 1; seed <= 200; seed++) {
        const sim = new BattleCitySim(seed, {});
        sim.input(5);
        for (let i = 0; i < 40; i++) {
            sim.advance();
            assert.equal(sim.bullets.filter(function (b) { return b[3] >= 0; }).length, 0, 'seed ' + seed + ', тик ' + sim.tick);
        }
    }
});

// --- Перезапуск после смерти -----------------------------------------------------------------------

/** Довести игру до смерти, переждать паузу: игра снова ждёт ввода, а счётчики капчи копятся. */
function die(sim) {
    sim.input(0, 100);
    run(sim, 30);
    const before = { acts: sim.acts, good: sim.good, tick: sim.tick, peak: sim.peak };
    sim.lose();
    run(sim, sim.dead);
    assert.equal(sim.idle, 1, 'ждёт ввода — кнопка перезапуска');
    assert.deepEqual([sim.acts, sim.good, sim.peak], [before.acts, before.good, before.peak], 'статистика не обнулилась');
    assert.ok(sim.tick > before.tick, 'время партии идёт');
}

test('Перезапуск: каждая игра начинает жизнь с чистого поля', function () {
    const snake = new SnakeSim(1, {});
    die(snake);
    assert.equal(snake.body.length, 3);
    assert.deepEqual(snake.body[0], [Math.floor(snake.cols / 2), Math.floor(snake.rows / 2)]);

    const flappy = new FlappySim(1, {});
    die(flappy);
    assert.deepEqual([flappy.y, flappy.vy], [150 * FFP, 0]);

    const ark = new ArkanoidSim(1, {});
    die(ark);
    assert.deepEqual([ark.vx, ark.vy, ark.bx], [0, 0, ark.px * AFP], 'мяч на ракетке');

    const tetris = new TetrisSim(1, {});
    die(tetris);
    assert.equal(tetris.grid.filter(function (c) { return c; }).length, COLS - 3, 'только ряд мусора');

    const pac = new PacManSim(1, {});
    die(pac);
    assert.deepEqual([pac.px, pac.py, pac.dir, pac.want], [pac.sx, pac.sy, -1, -1]);
    assert.deepEqual(pac.ghosts.map(function (g) { return [g[0], g[1]]; }), pac.starts);
});

test('Змейка: яблоко появляется не ближе ⌊(cols + rows) / 4⌋ от головы', function () {
    const sim = new SnakeSim(7, {});
    for (let i = 0; i < 30; i++) {
        sim.place();
        assert.ok(sim.away(sim.ax, sim.ay) >= Math.floor((sim.cols + sim.rows) / 4));
    }
});

test('Арканоид: на экране — разбитые кирпичи, цель — отбитые мячи', function () {
    const sim = new ArkanoidSim(1, {});
    // Кирпич нижнего ряда: под ним пусто, мяч подлетает снизу.
    const i   = sim.bricks.lastIndexOf(1);
    const x   = sim.layout.x0 + (i % sim.layout.cols) * 28 + 13;
    const y   = 22 + Math.floor(i / sim.layout.cols) * 12 + 5;

    sim.input(0, sim.px);
    sim.bx = x * AFP; sim.by = (y + 12) * AFP; sim.vx = 0; sim.vy = -1536;
    run(sim, 2);
    assert.equal(sim.broken, 1);
    assert.equal(sim.reactions, 0);
});

// --- Puzzle ---------------------------------------------------------------------------------------

const ORDER = [2, 0, 1, 4, 3, 5, 8, 6, 7];

function puzzle(withOrder) {
    return new PuzzleSim(1, withOrder ? { rows: 3, cols: 3, order: ORDER } : { rows: 3, cols: 3 });
}

test('Puzzle: кусочек на своём месте — очко; цель — все девять', function () {
    const sim = puzzle(true);
    sim.input(0, 0 * SLOT_BITS + ORDER[0]);
    assert.equal(sim.reactions, 1);
    assert.deepEqual([sim.acts, sim.good], [1, 1]);

    for (let k = 1; k < 9; k++) sim.input(0, k * SLOT_BITS + ORDER[k]);
    assert.equal(sim.peak, 9);
    assert.equal(sim.filled(), 9);
});

test('Puzzle: в занятую ячейку — обмен; со стола — вытесненный уходит на стол', function () {
    const sim = puzzle(true);
    sim.input(0, 0 * SLOT_BITS + 4);
    sim.input(0, 1 * SLOT_BITS + 5);

    sim.input(0, 0 * SLOT_BITS + 5);
    assert.deepEqual([sim.slots[4], sim.slots[5]], [1, 0], 'кусочки с поля меняются местами');

    sim.input(0, 2 * SLOT_BITS + 4);
    assert.equal(sim.slots[4], 2);
    assert.equal(sim.where[1], -1, 'вытесненный ушёл на стол');
});

test('Puzzle: убрать на стол; мусорные ходы не ломают состояние', function () {
    const sim = puzzle(true);
    sim.input(0, 3 * SLOT_BITS + ORDER[3]);
    sim.input(1, 3);
    assert.equal(sim.where[3], -1);
    assert.equal(sim.reactions, 0);
    assert.equal(sim.peak, 1, 'лучший результат помнится');

    sim.input(0, 9 * SLOT_BITS + 0);
    sim.input(0, 0 * SLOT_BITS + 9);
    sim.input(0, -1);
    sim.input(1, 42);
    assert.equal(sim.filled(), 0);
});

test('Puzzle: без перестановки (браузер) правильность не считается', function () {
    const sim = puzzle(false);
    for (let k = 0; k < 9; k++) sim.input(0, k * SLOT_BITS + ORDER[k]);
    assert.equal(sim.filled(), 9, 'занято всё — можно отправлять');
    assert.deepEqual([sim.reactions, sim.acts], [0, 0], 'а верно ли — решает сервер');
});
