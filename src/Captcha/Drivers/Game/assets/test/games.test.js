import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SnakeSim, STEP } from '../../Snake/src/sim.js';
import { FlappySim, FP as FFP, BX, R as FR, PW as FPW, H as FH, GROUND } from '../../Flappy/src/sim.js';
import { ArkanoidSim, FP as AFP, PY, PW as APW, R as AR } from '../../Arkanoid/src/sim.js';
import { TetrisSim, COLS, ROWS } from '../../Tetris/src/sim.js';
import { PacManSim, DX, DY } from '../../PacMan/src/sim.js';
import { BattleCitySim, BRICK, STEEL, PLAYER_PERIOD } from '../../BattleCity/src/sim.js';
import { PuzzleSim, SLOT_BITS } from '../../Puzzle/src/sim.js';
import { DinoSim, FP as DFP, GROUND as DGROUND, DX as DDX, DW as DDW, KINDS } from '../../Dino/src/sim.js';
import { InvadersSim, SHIP_Y as ISHIP_Y, SW as ISW, SX as ISX } from '../../Invaders/src/sim.js';
import { PlatformerSim, T as PT, FP as PFP, PW as PPW, SCREENS } from '../../Platformer/src/sim.js';
import { bot as platformerBot } from '../../Platformer/test/bot.js';
import { START } from '../engine/src/sim.js';

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

// --- DinoRun --------------------------------------------------------------------------------------

/** Динозавр на земле, перед ним одно препятствие вида kind в ticks тиках; новых не появляется. */
function dino(kind, ticks) {
    const sim = new DinoSim(1, {});
    sim.input(START);
    sim.dist = 1e9;
    sim.obs  = [[(DDX + DDW) * DFP + ticks * sim.speed, kind, 0]];
    return sim;
}

test('DinoRun: прыжок поднимает и возвращает на землю; в воздухе ввод не берётся', function () {
    const sim = new DinoSim(1, {});
    sim.input(START);
    sim.dist = 1e9;

    sim.input(0);
    run(sim, 1);
    assert.ok(sim.y < DGROUND * DFP, 'взлетел');

    sim.input(0);
    assert.equal(sim.acts, 1, 'второй прыжок в воздухе не считается');

    run(sim, 40);
    assert.deepEqual([sim.y, sim.vy], [DGROUND * DFP, 0], 'приземлился');
});

test('DinoRun: перепрыгнутое препятствие — очко, касание — смерть', function () {
    const stay = dino(0, 10);
    run(stay, 12);
    assert.ok(stay.dead > 0, 'без прыжка врезался');

    const jump = dino(0, 10);
    jump.input(0);
    run(jump, 40);
    assert.equal(jump.dead, 0);
    assert.equal(jump.reactions, 1);
});

test('DinoRun: птица низко — пройти можно только прыжком', function () {
    const sim = dino(4, 10);
    run(sim, 14);
    assert.ok(sim.dead > 0);
});

test('DinoRun: карта ускоряется, но не быстрее предела', function () {
    const sim = new DinoSim(1, {});
    sim.input(START);
    const speed0 = sim.speed;

    for (let i = 0; i < 3000; i++) { sim.obs = []; sim.dist = 1e9; sim.advance(); }
    assert.ok(sim.speed > speed0);
    assert.equal(sim.speed, 2048);
});

test('DinoRun: прыжок, который перелетает препятствие, — осмысленный; в пустоту или рано — нет', function () {
    const good = dino(1, 8);
    good.input(0);
    assert.deepEqual([good.acts, good.good], [1, 1]);

    const early = dino(1, 40);
    early.input(0);
    assert.deepEqual([early.acts, early.good], [1, 0]);

    const empty = dino(0, 0);
    empty.obs = [];
    empty.input(0);
    assert.deepEqual([empty.acts, empty.good], [1, 0]);
});

test('DinoRun: первые 6 с — только кактусы; промежуток всегда больше прыжка', function () {
    for (let seed = 1; seed <= 100; seed++) {
        const sim = new DinoSim(seed, {});
        sim.hits = function () { return false; };
        sim.input(START);

        while (sim.clock < 900) {
            const n = sim.obs.length, last = sim.obs[n - 1];
            sim.advance();
            const o = sim.obs[sim.obs.length - 1];
            if (!o || o === last) continue;

            if (sim.clock <= 180) assert.notEqual(KINDS[o[1]][0], 1, 'seed ' + seed + ': птица раньше 6 с');
            if (last) assert.ok(DinoSim.box(o)[0] - DinoSim.box(last)[2] > sim.speed * 32 / DFP, 'seed ' + seed + ': тесно');
        }
    }
});

// --- Space Invaders -------------------------------------------------------------------------------

test('Space Invaders: выстрел под противником — осмысленный и сбивает его; пуля одна', function () {
    const sim = new InvadersSim(1, {});
    sim.input(START);
    const c = 1, x = sim.fx + c * ISX + 7;
    sim.px = sim.target = x;

    sim.input(1);
    sim.input(1);
    assert.deepEqual([sim.acts, sim.good], [1, 1], 'вторая пуля, пока первая летит, не вылетает');

    run(sim, 30);
    assert.equal(sim.reactions, 1);
    assert.equal(sim.alive[(sim.rows - 1) * sim.cols + c], 0, 'сбит нижний в колонке');
});

test('Space Invaders: выстрел в пустоту — нет; цель ближе к колонке противников — да', function () {
    const sim = new InvadersSim(1, {});
    sim.input(START);
    sim.px = sim.target = ISW;

    sim.input(1);
    assert.deepEqual([sim.acts, sim.good], [1, 0]);

    sim.input(0, sim.w / 2);
    sim.input(0, ISW);
    assert.deepEqual([sim.acts, sim.good], [3, 1]);
});

test('Space Invaders: бомба в корабль — смерть', function () {
    const sim = new InvadersSim(1, {});
    sim.input(START);
    sim.bombs = [[sim.px, ISHIP_Y - 2]];
    run(sim, 2);
    assert.ok(sim.dead > 0);
});

test('Space Invaders: первые 2 с жизни противники не стреляют', function () {
    for (let seed = 1; seed <= 200; seed++) {
        const sim = new InvadersSim(seed, {});
        sim.input(START);
        for (let i = 0; i < 60; i++) {
            sim.advance();
            assert.equal(sim.bombs.length, 0, 'seed ' + seed + ', тик ' + sim.tick);
        }
    }
});

test('Space Invaders: следующая волна больше', function () {
    const sim = new InvadersSim(1, {});
    sim.input(START);
    const size = sim.alive.length;

    sim.alive = sim.alive.map(function () { return 0; });
    run(sim, 1);
    assert.equal(sim.wave, 1);
    assert.ok(sim.alive.length > size);
});

// --- Platformer -----------------------------------------------------------------------------------

/** Ровная земля высотой 2 без препятствий на всех экранах; герой на старте. */
function flat(sim) {
    for (let c = 0; c < sim.len; c++) { sim.g[c] = 2; sim.spikes[c] = 0; }
    sim.restart();
    sim.input(START);
    return sim;
}

test('Platformer: на каждом из трёх экранов — два обрыва, два шипа и ступени; перед препятствием два ровных шага', function () {
    for (const size of [{ cols: 18, rows: 8 }, { cols: 20, rows: 11 }, { cols: 40, rows: 24 }]) {
        for (let seed = 1; seed <= 300; seed++) {
            const sim = new PlatformerSim(seed, size);
            const g = sim.g;
            assert.equal(g.length, SCREENS * (sim.cols - 3) + 3);

            for (let s = 0; s < SCREENS; s++) {
                const base = s * (sim.cols - 3), at = 'seed ' + seed + ', ' + size.cols + '×' + size.rows + ', экран ' + s;
                let pits = 0, spikes = 0, steps = 0;

                for (let c = base; c < base + sim.cols; c++) {
                    assert.ok(g[c] === 0 || (g[c] >= 2 && g[c] <= 4), at + ': высота ' + g[c]);
                    const pit   = g[c] === 0 && g[c - 1] > 0;
                    const spike = sim.spikes[c] === 1;
                    if (pit) pits++;
                    if (spike) spikes++;
                    if (pit || spike)
                        assert.ok(c >= base + 3 && g[c - 1] > 0 && g[c - 1] === g[c - 2] && !sim.spikes[c - 1] && !sim.spikes[c - 2], at + ': разбег перед ' + c);
                    if (c > base && g[c] && g[c - 1]) {
                        assert.ok(Math.abs(g[c] - g[c - 1]) <= 1, at + ': ступень выше одной клетки');
                        if (g[c] !== g[c - 1]) steps++;
                    }
                }

                assert.ok(pits >= 2 && spikes >= 2, at + ': обрывов ' + pits + ', шипов ' + spikes);
                assert.ok(steps >= 1, at + ': без ступеней');
                const coin = base + sim.cols - 2;
                assert.ok(g[base] > 0 && g[base + 1] > 0 && g[base + 2] > 0, at + ': старт ровный');
                assert.ok(g[coin - 1] === g[coin] && g[coin + 1] === g[coin] && !sim.spikes[coin], at + ': у монетки ровно');
            }
        }
    }
});

test('Platformer: монетку можно достать — бот доходит на большинстве карт', function () {
    let reached = 0;
    for (let seed = 1; seed <= 100; seed++) {
        const sim = new PlatformerSim(seed, {});
        const b   = platformerBot(seed);
        while (sim.tick < 900 && !sim.reactions) {
            for (const a of b(sim)) if (!sim.dead) sim.input(a[0], a[1] || 0);
            sim.advance();
        }
        if (sim.reactions) reached++;
    }
    assert.ok(reached >= 90, 'дошёл на ' + reached + ' из 100');
});

test('Platformer: шип и обрыв — смерть', function () {
    const spike = flat(new PlatformerSim(1, {}));
    spike.spikes[5] = 1;
    spike.input(1);
    run(spike, 60);
    assert.ok(spike.dead > 0 || spike.idle, 'наткнулся на шип');

    const pit = flat(new PlatformerSim(1, {}));
    pit.g[5] = pit.g[6] = 0;
    pit.input(1);
    run(pit, 120);
    assert.ok(pit.dead > 0 || pit.idle, 'упал в обрыв');
});

test('Platformer: монетка — очко и следующий экран, герой остаётся на месте; после третьего — снова первый', function () {
    const sim = flat(new PlatformerSim(1, {}));
    sim.input(1);

    const shift = sim.cols - 3;
    for (let s = 1; s <= SCREENS; s++) {
        let guard = 0;
        while (sim.reactions < s && guard++ < 400) sim.advance();
        assert.equal(sim.reactions, s);
        assert.equal(sim.screen, s % SCREENS);
        if (s < SCREENS) assert.equal(sim.column(), s * shift + 1, 'на второй клетке нового экрана');
    }
    assert.equal(sim.x, (PT + 3) * PFP, 'с первого экрана заново');
});

test('Platformer: края экрана — стены; смерть возвращает в начало текущего экрана', function () {
    const sim = flat(new PlatformerSim(1, {}));
    sim.input(3);
    run(sim, 30);
    assert.ok(sim.x / PFP < 2, 'левый край — стена');

    sim.screen = 1;
    sim.restart();
    sim.input(3);
    run(sim, 30);
    const wall = (sim.cols - 3) * PT;
    assert.ok(sim.x / PFP >= wall && sim.x / PFP < wall + 2, 'левый край второго экрана — стена');

    sim.lose();
    run(sim, sim.dead);
    assert.equal(sim.column(), sim.cols - 3 + 1);
});

test('Platformer: прыжок перепрыгивает обрыв в две клетки и забирается на ступень', function () {
    const pit = flat(new PlatformerSim(1, {}));
    pit.g[4] = pit.g[5] = 0;
    pit.input(1);
    while (pit.x / PFP + PPW < 4 * PT - 4) pit.advance();
    pit.input(4);
    run(pit, 60);
    assert.equal(pit.dead, 0);
    assert.ok(pit.column() > 6, 'за обрывом');

    const step = flat(new PlatformerSim(1, {}));
    for (let c = 5; c < step.len; c++) step.g[c] = 3;
    step.input(1);
    while (step.x / PFP + PPW < 5 * PT - 4) step.advance();
    step.input(4);
    run(step, 30);
    assert.ok(step.column() >= 5 && step.floor, 'на ступени');
});

test('Platformer: вправо — осмысленно, влево — нет, повтор не считается; прыжок — только у опасности', function () {
    const sim = flat(new PlatformerSim(1, {}));
    sim.input(1);
    sim.input(1);
    sim.input(3);
    sim.input(5);
    assert.deepEqual([sim.acts, sim.good], [2, 1]);

    sim.input(4);
    assert.deepEqual([sim.acts, sim.good], [3, 1], 'прыжок на ровном месте');

    const edge = flat(new PlatformerSim(1, {}));
    edge.g[4] = 0;
    edge.input(1);
    while (edge.x / PFP + PPW < 4 * PT - 10) edge.advance();
    edge.input(4);
    assert.deepEqual([edge.acts, edge.good], [2, 2], 'прыжок на бегу у края обрыва');
});

test('Перезапуск: DinoRun, Space Invaders и платформер начинают жизнь с чистого поля', function () {
    const d = new DinoSim(1, {});
    die(d);
    assert.deepEqual([d.obs.length, d.y, d.speed], [0, DGROUND * DFP, 1024]);

    const inv = new InvadersSim(1, {});
    die(inv);
    assert.deepEqual([inv.wave, inv.bombs.length, inv.alive.indexOf(0)], [0, 0, -1]);

    const p = new PlatformerSim(1, {});
    const g = p.g.slice();
    die(p);
    assert.deepEqual([p.x, p.dir, p.floor], [(PT + 3) * PFP, 0, 1]);
    assert.deepEqual(p.g, g, 'карта та же');
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

// Лёгкий уровень: ячейки 0..5 уже заняты своими кусочками, вставить остаются три.
const FIXED = [0, 1, 2, 3, 4, 5].map(function (s) { return [ORDER.indexOf(s), s]; });

test('Puzzle: закреплённые кусочки стоят на местах с начала и считаются в цель', function () {
    const sim = new PuzzleSim(1, { rows: 3, cols: 3, order: ORDER, fixed: FIXED });
    assert.deepEqual([sim.reactions, sim.peak, sim.filled()], [6, 6, 6]);

    for (let s = 6; s < 9; s++) sim.input(0, ORDER.indexOf(s) * SLOT_BITS + s);
    assert.equal(sim.peak, 9, 'собран целиком');
    assert.deepEqual([sim.acts, sim.good], [3, 3], 'действия — только свои ходы');
});

test('Puzzle: закреплённый не снять, не переставить и не вытеснить', function () {
    const sim  = new PuzzleSim(1, { rows: 3, cols: 3, order: ORDER, fixed: FIXED });
    const k0   = ORDER.indexOf(0);
    const free = ORDER.indexOf(8);
    const was  = sim.slots.slice();

    sim.input(1, k0);
    sim.input(0, k0 * SLOT_BITS + 7);
    sim.input(0, free * SLOT_BITS + 0);
    assert.deepEqual(sim.slots, was);
    assert.equal(sim.acts, 0, 'запрещённые ходы — не действия');

    // В браузере перестановки нет, а закреплённые всё равно на местах.
    const client = new PuzzleSim(1, { rows: 3, cols: 3, fixed: FIXED });
    assert.equal(client.filled(), 6);
});
