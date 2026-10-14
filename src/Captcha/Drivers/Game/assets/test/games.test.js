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
import { MinesweeperSim, WIN_PAUSE } from '../../Minesweeper/src/sim.js';
import { MemorySim, PREVIEW as M_PREVIEW, PLAY as M_PLAY, MISS as M_MISS, WON as M_WON, DOWN as M_DOWN, UP as M_UP, FOUND as M_FOUND, MISS_TICKS, WIN_PAUSE as M_WIN } from '../../Memory/src/sim.js';
import { FruitNinjaSim, FP as NFP, H as NH, G as NG, PACK, MAXSEG, LIVES as NLIVES, STILL } from '../../FruitNinja/src/sim.js';
import { PinballSim, FP as PBFP, FLIPPERS as PBFLIP, BALL0, CHARGE as PBCHARGE, BUMPER_ZONE, BUMPER_GAP, BUMP_POINTS, WINDOW as PBWINDOW } from '../../Pinball/src/sim.js';
import { RacingSim, FP as RFP, N as RN, ROAD as RROAD, WALL as RWALL, CHECKS as RCHECKS, UP as RUP, LEFT as RLEFT, RIGHT as RRIGHT, DOWN as RDOWN } from '../../Racing/src/sim.js';
import { bot as racingBot } from '../../Racing/test/bot.js';
import { SimonSim, FIRST, WAIT, SHOW, INPUT, DONE, PAUSE, SUCCESS, TIMEOUT, onTicks, offTicks } from '../../Simon/src/sim.js';
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

// --- Minesweeper ----------------------------------------------------------------------------------

/** Сапёр 9×6 с 8 минами, первый клик — в центр. */
function mines(seed) {
    const sim = new MinesweeperSim(seed || 1, { cols: 9, rows: 6, mines: 8 });
    sim.input(START);
    sim.input(0, 2 * 9 + 4);
    return sim;
}

function cellsWhere(sim, test) {
    const out = [];
    for (let c = 0; c < sim.n; c++) if (test(c)) out.push(c);
    return out;
}

test('Minesweeper: первый клик безопасен, мины не в квадрате 3×3 вокруг него, ноль открывается заливкой', function () {
    for (const seed of [1, 2, 3, 99]) {
        const sim = mines(seed);
        assert.equal(cellsWhere(sim, function (c) { return sim.mine[c]; }).length, 8);
        for (const c of [12, 13, 14, 21, 22, 23, 30, 31, 32]) assert.equal(sim.mine[c], 0, 'клетка ' + c);
        assert.ok(sim.opened > 1, 'заливка открыла соседей');
        assert.deepEqual([sim.acts, sim.good, sim.reactions], [1, 1, 0], 'первый клик осмыслен, но очка не даёт');
    }

    const a = mines(7), b = mines(7);
    assert.deepEqual(a.mine, b.mine, 'расклад — от seed');
});

test('Minesweeper: безопасная клетка у открытых — очко и осмысленно; наугад вдали — нет', function () {
    const sim = mines(1);
    const near = cellsWhere(sim, function (c) { return !sim.open[c] && !sim.mine[c] && sim.touches(c); });

    sim.input(0, near[0]);
    assert.deepEqual([sim.reactions, sim.acts, sim.good], [1, 2, 2]);

    const far = cellsWhere(sim, function (c) { return !sim.open[c] && !sim.mine[c] && !sim.touches(c); });
    if (far.length) {
        sim.input(0, far[0]);
        assert.deepEqual([sim.reactions, sim.acts, sim.good], [2, 3, 2], 'безопасно, но наугад');
    }
});

test('Minesweeper: мина — смерть, новая жизнь — новое поле', function () {
    const sim = mines(1);
    const m = cellsWhere(sim, function (c) { return sim.mine[c]; })[0];
    const before = sim.mine.slice();

    sim.input(0, m);
    assert.ok(sim.dead > 0);
    assert.equal(sim.boom, m);
    run(sim, sim.dead);
    assert.equal(sim.idle, 1);
    assert.deepEqual([sim.placed, sim.opened, sim.boom], [0, 0, -1], 'поле чистое, мины лягут по первому клику');

    sim.input(START);
    sim.input(0, 2 * 9 + 4);
    assert.notDeepEqual(sim.mine, before, 'другой расклад');
});

test('Minesweeper: флажок на мину у открытых — осмысленно, на пустую — нет; флажок не открывается', function () {
    const sim = mines(1);
    const m = cellsWhere(sim, function (c) { return sim.mine[c] && sim.touches(c); })[0];
    const s = cellsWhere(sim, function (c) { return !sim.open[c] && !sim.mine[c] && sim.touches(c); })[0];

    sim.input(1, m);
    sim.input(1, s);
    assert.deepEqual([sim.acts, sim.good], [3, 2]);

    sim.input(0, s);
    assert.equal(sim.open[s], 0, 'под флажком не открыть');
    sim.input(1, s);
    assert.equal(sim.acts, 3, 'снять флажок — не действие');
});

test('Minesweeper: число с флажками вокруг открывает соседей; ошибка во флажках — взрыв', function () {
    const sim = mines(3);
    // Открытое число, все мины вокруг которого помечены флажками.
    const c = cellsWhere(sim, function (x) {
        return sim.open[x] && sim.number(x) > 0 && sim.around(x).some(function (a) { return !sim.open[a] && !sim.mine[a]; });
    })[0];
    assert.ok(c !== undefined, 'есть число с закрытыми безопасными соседями');

    for (const a of sim.around(c)) if (sim.mine[a]) sim.input(1, a);
    const acts = sim.acts, good = sim.good;
    sim.input(2, c);
    assert.ok(sim.around(c).every(function (a) { return sim.open[a] || sim.flag[a]; }));
    assert.deepEqual([sim.acts - acts, sim.good - good, sim.reactions], [1, 1, 1]);

    // Флажок не на той клетке: у единицы флажок на пустой, под закрытым соседом — мина.
    const wrong = mines(1);
    const one = cellsWhere(wrong, function (x) { return wrong.open[x] && wrong.number(x) === 1; })
        .find(function (x) { return wrong.around(x).some(function (a) { return !wrong.open[a] && !wrong.mine[a]; }); });
    const m = wrong.around(one).filter(function (a) { return wrong.mine[a]; });
    wrong.input(1, wrong.around(one).filter(function (a) { return !wrong.open[a] && !wrong.mine[a]; })[0]);
    const bad = wrong.acts - wrong.good;
    wrong.input(2, one);
    assert.ok(wrong.dead > 0, 'открыл мину');
    assert.equal(wrong.boom, m[0]);
    assert.equal(wrong.acts - wrong.good, bad + 1, 'ход с ошибкой — не осмыслен');
});

test('Minesweeper: открытое поле — cleared, пауза и новое поле; ввод в паузе не принимается', function () {
    const sim = mines(1);
    for (let c = 0; c < sim.n; c++) if (!sim.mine[c] && !sim.open[c]) sim.input(0, c);

    assert.equal(sim.cleared, 1);
    assert.equal(sim.won, WIN_PAUSE);
    const acts = sim.acts;
    sim.input(0, 0);
    assert.equal(sim.acts, acts);

    run(sim, WIN_PAUSE);
    assert.deepEqual([sim.won, sim.placed, sim.opened], [0, 0, 0]);
    assert.ok(sim.reactions > 0, 'очки жизни не сгорают на новом поле');
});

// --- Simon ----------------------------------------------------------------------------------------

/** Simon, запущенный и доведённый до фазы ввода. */
function simonInput(seed, params) {
    const sim = new SimonSim(seed || 1, Object.assign({ buttons: 4, rounds: 2 }, params));
    sim.input(START);
    while (sim.phase !== INPUT) sim.advance();
    return sim;
}

test('Simon: показ — каждый шаг горит onTicks и гаснет на offTicks, затем ввод', function () {
    const sim = new SimonSim(1, { buttons: 4, rounds: 2 });
    assert.equal(sim.seq.length, FIRST);
    sim.input(START);

    run(sim, PAUSE - 1);
    assert.equal(sim.phase, WAIT);
    sim.advance();
    assert.equal(sim.phase, SHOW);

    const seen = [];
    while (sim.phase === SHOW) {
        if (sim.lit() >= 0 && seen[seen.length - 1] !== sim.lit() + 's' + sim.step) seen.push(sim.lit() + 's' + sim.step);
        sim.advance();
    }
    assert.deepEqual(seen, sim.seq.map(function (b, i) { return b + 's' + i; }), 'горят шаги по порядку');
    assert.equal(sim.phase, INPUT);
    assert.equal(sim.timer, TIMEOUT);
    assert.equal(onTicks(0) > offTicks(0) && onTicks(6) < onTicks(0), true, 'показ ускоряется с раундом');
});

test('Simon: нажатия на показе не принимаются и действием не считаются', function () {
    const sim = new SimonSim(1, { buttons: 4 });
    sim.input(START);
    run(sim, PAUSE + 2);
    sim.input(0, sim.seq[0]);
    assert.deepEqual([sim.acts, sim.reactions, sim.flash], [0, 0, -1]);
});

test('Simon: верный раунд — очко за шаг, затем пауза и новая случайная последовательность на шаг длиннее', function () {
    const sim = simonInput(1);
    const seq = sim.seq.slice();

    for (const b of seq) sim.input(0, b);
    assert.deepEqual([sim.reactions, sim.acts, sim.good, sim.round, sim.phase], [FIRST, FIRST, FIRST, 1, DONE]);

    run(sim, SUCCESS);
    assert.equal(sim.phase, WAIT);
    assert.equal(sim.seq.length, FIRST + 1);
    assert.ok(sim.seq.every(function (b) { return b >= 0 && b < 4; }));

    // Новая последовательность, а не прежняя с шагом в конце: на нескольких seed начало расходится.
    let differs = 0;
    for (const seed of [1, 2, 3, 4, 5, 6, 7, 8]) {
        const s = simonInput(seed);
        const first = s.seq.slice();
        for (const b of first) s.input(0, b);
        run(s, SUCCESS);
        if (s.seq.slice(0, FIRST).join() !== first.join()) differs++;
    }
    assert.ok(differs >= 6, 'начало новой последовательности почти всегда другое: ' + differs + ' из 8');
});

test('Simon: ошибка — смерть, новая жизнь — новая последовательность из четырёх', function () {
    const sim = simonInput(1);
    const seq = sim.seq.slice();

    sim.input(0, sim.seq[0]);
    sim.input(0, (sim.seq[1] + 1) % 4);
    assert.ok(sim.dead > 0);
    assert.equal(sim.wrong, (seq[1] + 1) % 4);
    assert.deepEqual([sim.acts, sim.good, sim.reactions, sim.peak], [2, 1, 0, 1]);

    run(sim, sim.dead);
    assert.equal(sim.idle, 1);
    assert.deepEqual([sim.seq.length, sim.round, sim.phase], [FIRST, 0, WAIT]);
    assert.notDeepEqual(sim.seq, seq);
});

test('Simon: молчание дольше TIMEOUT на вводе — смерть; каждый верный шаг заводит таймер заново', function () {
    const sim = simonInput(1);
    run(sim, TIMEOUT - 10);
    sim.input(0, sim.seq[0]);
    run(sim, TIMEOUT - 1);
    assert.equal(sim.dead, 0, 'таймер заведён заново');
    sim.advance();
    assert.ok(sim.dead > 0);
});

test('Simon: buttons — от 3 до 6 секторов; последовательность — только из них', function () {
    for (const [asked, got] of [[2, 3], [3, 3], [6, 6], [9, 6]]) {
        const sim = new SimonSim(5, { buttons: asked });
        assert.equal(sim.buttons, got);
        assert.ok(sim.seq.every(function (b) { return b >= 0 && b < got; }));
    }

    const sim = simonInput(1, { buttons: 3 });
    sim.input(0, 3);
    assert.equal(sim.acts, 0, 'несуществующий сектор — не нажатие');
});

// --- Memory ---------------------------------------------------------------------------------------

/** Memory 8 пар, 4 жизни, показ 50 тиков — запущенный и доведённый до игры. */
function memory(seed) {
    const sim = new MemorySim(seed || 1, { pairs: 8, lives: 4, show: 50 });
    sim.input(START);
    run(sim, 50);
    return sim;
}

/** Номера двух карточек пары p. */
function pairOf(sim, p) {
    const out = [];
    for (let i = 0; i < sim.n; i++) if (sim.deck[i] === p) out.push(i);
    return out;
}

test('Memory: до старта карточки закрыты, после — открыты на show тиков, затем закрываются', function () {
    const sim = new MemorySim(1, { pairs: 8, lives: 4, show: 50 });
    assert.ok(sim.face.every(function (f) { return f === M_DOWN; }), 'до старта лиц не видно');
    assert.equal(sim.deck.length, 16);
    for (let p = 0; p < 8; p++) assert.equal(pairOf(sim, p).length, 2, 'пара ' + p);

    sim.input(START);
    assert.ok(sim.face.every(function (f) { return f === M_UP; }));
    sim.input(0, 0);
    assert.equal(sim.first, -1, 'на показе карточки не открываются');

    run(sim, 49);
    assert.equal(sim.phase, M_PREVIEW);
    sim.advance();
    assert.equal(sim.phase, M_PLAY);
    assert.ok(sim.face.every(function (f) { return f === M_DOWN; }));
});

test('Memory: пара совпала — очко и осмысленно; не совпала — жизнь, промах виден, затем закрывается', function () {
    const sim = memory(1);
    const a = pairOf(sim, 0), b = pairOf(sim, 1);

    sim.input(0, a[0]);
    sim.input(0, a[1]);
    assert.deepEqual([sim.face[a[0]], sim.face[a[1]], sim.reactions, sim.acts, sim.good], [M_FOUND, M_FOUND, 1, 1, 1]);

    sim.input(0, b[0]);
    sim.input(0, pairOf(sim, 2)[0]);
    assert.deepEqual([sim.phase, sim.lives, sim.acts, sim.good], [M_MISS, 3, 2, 1]);
    run(sim, MISS_TICKS);
    assert.equal(sim.phase, M_PLAY);
    assert.equal(sim.face[b[0]], M_DOWN, 'промах закрылся');
});

test('Memory: клик во время промаха закрывает его сразу и открывает новую карточку', function () {
    const sim = memory(1);
    const a = pairOf(sim, 0), b = pairOf(sim, 1);

    sim.input(0, a[0]);
    sim.input(0, b[0]);
    sim.input(0, b[1]);
    assert.deepEqual([sim.phase, sim.first, sim.face[a[0]], sim.face[b[0]], sim.face[b[1]]], [M_PLAY, b[1], M_DOWN, M_DOWN, M_UP]);
});

test('Memory: открытую или найденную карточку второй раз не открыть, и это не действие', function () {
    const sim = memory(1);
    const a = pairOf(sim, 0);

    sim.input(0, a[0]);
    sim.input(0, a[0]);
    assert.deepEqual([sim.first, sim.acts], [a[0], 0]);
    sim.input(0, a[1]);
    sim.input(0, a[1]);
    assert.deepEqual([sim.first, sim.acts], [-1, 1]);
});

test('Memory: жизни кончились — смерть, новая жизнь — новый расклад, жизни заново', function () {
    const sim = memory(1);
    const deck = sim.deck.slice();

    for (let k = 0; k < 4; k++) {
        sim.input(0, pairOf(sim, k)[0]);
        sim.input(0, pairOf(sim, (k + 1) % 8)[0]);
    }
    assert.ok(sim.dead > 0);
    assert.equal(sim.lives, 0);

    run(sim, sim.dead);
    assert.deepEqual([sim.idle, sim.lives, sim.phase, sim.found], [1, 4, M_PREVIEW, 0]);
    assert.notDeepEqual(sim.deck, deck);
});

test('Memory: все пары — solved, пауза, новый расклад сразу с показом', function () {
    const sim = memory(3);
    for (let p = 0; p < 8; p++) {
        const a = pairOf(sim, p);
        sim.input(0, a[0]);
        sim.input(0, a[1]);
    }
    assert.deepEqual([sim.solved, sim.phase, sim.peak], [1, M_WON, 8]);

    run(sim, M_WIN);
    assert.equal(sim.phase, M_PREVIEW);
    assert.ok(sim.face.every(function (f) { return f === M_UP; }), 'новый расклад сразу показан');
    assert.equal(sim.reactions, 8, 'очки жизни не сгорают');
});

// --- Fruit Ninja ----------------------------------------------------------------------------------

/** Fruit Ninja с одной фигурой радиуса 10 в (x, y), неподвижной. */
function fruit(x, y) {
    const sim = new FruitNinjaSim(1, { w: 267 });
    sim.input(START);
    sim.next = 1000;
    sim.shapes = [[0, x * NFP, y * NFP, 0, 0, 2, 0, 10, 0, 0]];
    return sim;
}

function blade(sim, points) {
    for (const p of points) sim.input(0, p[0] * PACK + p[1]);
}

test('Fruit Ninja: взмах сквозь фигуру режет её — очко и осмысленный взмах', function () {
    const sim = fruit(100, 75);
    blade(sim, [[60, 75], [80, 75], [100, 75], [120, 75]]);
    assert.deepEqual([sim.shapes.length, sim.reactions], [0, 1]);

    run(sim, STILL);
    assert.deepEqual([sim.acts, sim.good], [1, 1], 'взмах окончен, когда клинок замер');
});

test('Fruit Ninja: отрезок без прошлого направления не режет — одиночный скачок клинка ничего не задевает', function () {
    const sim = fruit(100, 75);
    blade(sim, [[85, 75], [115, 75]]);
    assert.equal(sim.shapes.length, 1);
    blade(sim, [[135, 75]]);
    assert.equal(sim.shapes.length, 1, 'второй отрезок прошёл уже мимо');
});

test('Fruit Ninja: перескок длиннее MAXSEG и медленное ведение не режут', function () {
    const far = fruit(100, 75);
    blade(far, [[0, 75], [10, 75], [10 + MAXSEG + 1, 75], [140, 75]]);
    assert.equal(far.shapes.length, 1, 'скачок через фигуру');

    const slow = fruit(100, 75);
    const path = [];
    for (let x = 90; x <= 110; x += 2) path.push([x, 75]);
    blade(slow, path);
    assert.equal(slow.shapes.length, 1, 'клинок еле ползёт');
});

test('Fruit Ninja: резкий поворот клинка (больше 60°) не режет, плавная дуга — режет', function () {
    const zig = fruit(100, 75);
    blade(zig, [[60, 60], [90, 90], [110, 60]]);
    assert.equal(zig.shapes.length, 1, 'отрезок сквозь фигуру, но под прямым углом к прошлому');

    const arc = fruit(100, 75);
    blade(arc, [[50, 95], [75, 85], [100, 75], [125, 70]]);
    assert.equal(arc.shapes.length, 0);
});

test('Fruit Ninja: быстрый взмах мышью (до MAXSEG за тик) режет; круг попадания — по контуру фигуры', function () {
    const fast = fruit(100, 75);
    blade(fast, [[0, 75], [MAXSEG - 30, 75], [2 * MAXSEG - 60, 75]]);
    assert.equal(fast.shapes.length, 0, 'отрезок ' + (MAXSEG - 30) + ' единиц за тик');

    const tip = fruit(100, 75);
    blade(tip, [[40, 87], [80, 87], [120, 87], [160, 87]]);
    assert.equal(tip.shapes.length, 0, 'задет край на 12 единиц от центра при радиусе 10');
});

test('Fruit Ninja: взмах рядом с фигурой мимо — действие без смысла; через пустое поле — не действие', function () {
    const sim = fruit(100, 75);
    blade(sim, [[40, 105], [70, 105], [100, 105], [130, 105]]);
    run(sim, STILL);
    assert.deepEqual([sim.acts, sim.good, sim.shapes.length], [1, 0, 1], 'прошёл в 30 единицах — рядом, но мимо');

    blade(sim, [[40, 10], [80, 10], [120, 10]]);
    sim.input(1);
    assert.deepEqual([sim.acts, sim.good], [1, 0], 'над пустым полем — не действие');
});

test('Fruit Ninja: несколько фигур одним взмахом — каждая очко, взмах один', function () {
    const sim = fruit(100, 75);
    sim.shapes.push([1, 150 * NFP, 75 * NFP, 0, 0, 0, 1, 10, 0, 0]);
    blade(sim, [[50, 75], [80, 75], [110, 75], [140, 75], [170, 75]]);
    sim.input(1);
    assert.deepEqual([sim.reactions, sim.acts, sim.good], [2, 1, 1]);
});

test('Fruit Ninja: фигуры вылетают снизу и падают обратно; упущено LIVES — смерть, новая жизнь — счёт с нуля', function () {
    const sim = new FruitNinjaSim(1, { w: 267 });
    sim.input(START);

    let top = Infinity;
    while (!sim.shapes.length) sim.advance();
    const id = sim.shapes[0][0];
    while (sim.shapes.some(function (s) { return s[0] === id; })) {
        top = Math.min(top, sim.shapes.find(function (s) { return s[0] === id; })[2]);
        sim.advance();
    }
    assert.ok(top < NH * NFP && top > 0, 'фигура поднялась в поле и не вылетела вверх');
    assert.ok(NG >= 40, 'ускорение заметное: взлёт около секунды, а не плавное парение');
    assert.ok(sim.misses >= 1);

    while (!sim.dead) sim.advance();
    assert.equal(sim.misses, NLIVES);
    run(sim, sim.dead);
    assert.deepEqual([sim.idle, sim.misses, sim.shapes.length], [1, 0, 0]);
});

// --- Pinball --------------------------------------------------------------------------------------

/** Пинбол, запущенный; мяч ставится в (x, y) единиц мира со скоростью (vx, vy) FP за тик. */
function pinball(x, y, vx, vy) {
    const sim = new PinballSim(1, {});
    sim.input(START);
    if (x !== undefined) {
        sim.bx = x * PBFP; sim.by = y * PBFP; sim.vx = vx || 0; sim.vy = vy || 0;
    }
    return sim;
}

test('Pinball: мяч лежит на пускателе; заряд копится, пока держат, отпустили — мяч уходит из желоба в поле', function () {
    const sim = pinball();
    run(sim, 30);
    assert.ok(sim.inLane(), 'лежит в желобе');
    assert.ok(sim.by > (BALL0[1] - 4) * PBFP, 'на пружине');

    sim.input(4);
    run(sim, 60);
    assert.equal(sim.charge, PBCHARGE, 'заряд упёрся в предел');

    sim.input(5);
    let top = Infinity;
    for (let t = 0; t < 120; t++) { sim.advance(); top = Math.min(top, sim.by); }
    assert.ok(top < 40 * PBFP, 'долетел до верха стола');
    assert.ok(sim.bx < 144 * PBFP, 'и ушёл из желоба в поле');
});

test('Pinball: слабый пуск — мяч возвращается на пускатель; отпустить без взвода — ничего', function () {
    const sim = pinball();
    run(sim, 10);
    sim.input(5);
    run(sim, 30);
    assert.ok(sim.inLane());

    sim.input(4);
    sim.advance();
    sim.input(5);
    run(sim, 240);
    assert.ok(sim.inLane(), 'не хватило силы выйти из желоба');
});

test('Pinball: бамперы — от seed, 3 или 4, в своей зоне, мяч проходит между любыми двумя', function () {
    const layouts = new Set();
    for (let seed = 1; seed <= 40; seed++) {
        const bs = new PinballSim(seed, {}).bumpers;
        assert.ok(bs.length >= 3 && bs.length <= 4, 'seed ' + seed + ': ' + bs.length);
        for (const b of bs) {
            assert.ok(b[0] >= BUMPER_ZONE[0] && b[0] < BUMPER_ZONE[2] && b[1] >= BUMPER_ZONE[1] && b[1] < BUMPER_ZONE[3]);
            assert.ok(b[2] >= 8 && b[2] <= 10);
            assert.ok(b[0] + b[2] + 8 <= 144, 'до желоба проходит мяч');
        }
        for (let i = 0; i < bs.length; i++)
            for (let j = 0; j < i; j++)
                assert.ok(Math.hypot(bs[i][0] - bs[j][0], bs[i][1] - bs[j][1]) >= bs[i][2] + bs[j][2] + BUMPER_GAP);
        layouts.add(JSON.stringify(bs));
    }
    assert.ok(layouts.size >= 38, 'раскладки разные');

    const one = new PinballSim(9, {}).bumpers;
    const sim = new PinballSim(9, {});
    sim.input(START);
    sim.lose();
    run(sim, sim.dead);
    assert.deepEqual(sim.bumpers, one, 'раскладка переживает смерть');
});

test('Pinball: бампер отталкивает мяч и даёт очки', function () {
    const b = new PinballSim(1, {}).bumpers[0];
    const sim = pinball(b[0], b[1] - b[2] - 6, 0, 600);
    run(sim, 6);
    assert.equal(sim.points, BUMP_POINTS);
    assert.ok(sim.vy < 0, 'отскочил вверх');
    assert.ok(sim.flash[0] > 0, 'вспышка для вида');
});

test('Pinball: поднятый флиппер отбивает падающий на него мяч — реакция и осмысленный взмах; лежачий не отбивает', function () {
    const p = PBFLIP[0];
    const hit = pinball(p[0] + 16, p[1] - 6, 0, 300);
    hit.input(0);
    run(hit, 4);
    assert.equal(hit.reactions, 1);
    assert.ok(hit.vy < -300, 'полетел вверх быстро');
    assert.deepEqual([hit.acts, hit.good], [1, 1], 'флиппер коснулся мяча — по делу');

    const rest = pinball(p[0] + 16, p[1] - 8, 0, 200);
    run(rest, 10);
    assert.equal(rest.reactions, 0, 'опущенный флиппер — просто горка');
});

test('Pinball: мяч внизу у своего флиппера — нажатие осмысленно сразу; у чужого — только если коснётся', function () {
    const p = PBFLIP[1];
    const near = pinball(p[0] - 14, 172, 0, 300);
    near.input(2);
    assert.deepEqual([near.acts, near.good], [1, 1], 'мяч внизу справа, правый флиппер');

    const other = pinball(30, 170, 0, 0);
    other.input(2);
    assert.equal(other.acts, 0, 'мяч слева — ждём, коснётся ли');
    run(other, PBWINDOW);
    assert.deepEqual([other.acts, other.good], [1, 0], 'не коснулся — мимо');

    const twice = pinball(30, 170, 0, 0);
    twice.input(2);
    twice.input(3);
    twice.input(2);
    assert.deepEqual([twice.acts, twice.good], [1, 0], 'нажат снова до касания — прошлый взмах мимо');
});

test('Pinball: мяч выше — взмах осмыслен, если поднятый флиппер коснётся его; на пускателе — не действие', function () {
    const p = PBFLIP[0];
    const early = pinball(p[0] + 16, 140, 0, 700);
    early.input(0);
    assert.equal(early.acts, 0, 'мяч ещё выше нижней части');
    run(early, PBWINDOW);
    assert.deepEqual([early.acts, early.good], [1, 1], 'подлетел и коснулся — нажал заранее, по делу');

    const top = pinball(72, 40, 0, 0);
    top.input(2);
    run(top, PBWINDOW + 2);
    assert.deepEqual([top.acts, top.good], [1, 0], 'мяч наверху — мимо');

    const lane = pinball();
    lane.input(0);
    lane.input(1);
    run(lane, PBWINDOW + 2);
    assert.equal(lane.acts, 0, 'мяч на пускателе — не действие');
});

test('Pinball: мяч в сливе — смерть, новый мяч на пускателе', function () {

    const drain = pinball(72, 215, 0, 400);
    for (let t = 0; t < 60 && !drain.dead; t++) drain.advance();
    assert.ok(drain.dead > 0, 'между флипперами — в слив');
    run(drain, drain.dead);
    assert.ok(drain.idle && drain.inLane(), 'новый мяч на пускателе');
});

test('Pinball: пуск с разбросом от seed — разные выдачи летят по-разному', function () {
    const ys = new Set();
    for (const seed of [1, 2, 3, 4, 5]) {
        const sim = new PinballSim(seed, {});
        sim.input(START);
        run(sim, 5);
        sim.input(4);
        run(sim, 40);
        sim.input(5);
        run(sim, 90);
        ys.add(sim.bx + ':' + sim.by);
    }
    assert.ok(ys.size >= 4);
});

test('Pinball: мяч вылетел из желоба — вход закрывает перегородка, назад на пускатель он не попадает; новый мяч — открыта', function () {
    const sim = pinball();
    sim.input(4);
    run(sim, 40);
    sim.input(5);
    while (!sim.closed) sim.advance();
    assert.ok(sim.by < 40 * PBFP, 'закрыто, когда мяч уже над перегородкой');

    // Мяч брошен вниз прямо над входом в желоб — скатывается в поле.
    sim.bx = 150 * PBFP; sim.by = 30 * PBFP; sim.vx = 0; sim.vy = 300;
    run(sim, 90);
    assert.ok(sim.bx < 144 * PBFP, 'в поле, а не в желобе');

    sim.lose();
    run(sim, sim.dead);
    assert.equal(sim.closed, 0, 'новый мяч — желоб открыт');
});

// --- Racing ---------------------------------------------------------------------------------------

/** Проехать гонку ботом, пока не выполнено until(sim) или не вышло ticks тиков. */
function drive(seed, until, ticks) {
    const sim = new RacingSim(seed, { laps: 1 }), b = racingBot(seed);
    for (let t = 0; t < ticks && !until(sim); t++) {
        for (const a of b(sim)) sim.input(a[0], a[1] || 0);
        sim.advance();
    }
    return sim;
}

test('Racing: трасса от seed — замкнутая, по часовой стрелке, витки не сходятся, у каждой выдачи своя', function () {
    const shapes = new Set();
    for (let seed = 1; seed <= 30; seed++) {
        const t = new RacingSim(seed, {}).track;
        assert.equal(t.length, RN);
        let area = 0;
        for (let i = 0; i < RN; i++) {
            const p = t[i], q = t[(i + 1) % RN];
            area += p[0] * q[1] - q[0] * p[1];
            const d = Math.hypot(q[0] - p[0], q[1] - p[1]);
            assert.ok(d >= 1 && d < 80, 'seed ' + seed + ': отрезок ' + i + ' длиной ' + d.toFixed(1));
        }
        assert.ok(area > 0, 'seed ' + seed + ': по часовой стрелке (ось y вниз)');
        shapes.add(JSON.stringify(t));
    }
    assert.equal(shapes.size, 30);
    assert.deepEqual(new RacingSim(5, {}).track, new RacingSim(5, {}).track);

    for (let seed = 1; seed <= 30; seed++) {
        const sim = new RacingSim(seed, {});
        assert.ok(sim.track[0][0] < 600, 'seed ' + seed + ': старт в левой части');
        const h = sim.h >> 3;
        assert.ok(h > 128, 'seed ' + seed + ': со старта — вверх (курс 129…255), курс ' + h);
    }

    for (let seed = 1; seed <= 100; seed++)
        assert.ok(RacingSim.valid(new RacingSim(seed, {}).track), 'seed ' + seed + ': трасса годная');
});

test('Racing: чекпоинты по порядку — реакции; круг — время в lapTimes и лучший круг', function () {
    const sim = drive(1, function (s) { return s.laps >= 1; }, 30 * 60);
    assert.equal(sim.laps, 1);
    assert.equal(sim.reactions, RCHECKS);
    assert.equal(sim.lapTimes.length, 1);
    assert.equal(sim.best, sim.lapTimes[0]);
    assert.ok(sim.best > 30 * 8 && sim.best < 30 * 40, 'круг за ' + (sim.best / 30).toFixed(1) + ' с');
    assert.equal(sim.lapAt, sim.clock, 'секундомер нового круга — с нуля');
});

test('Racing: задним ходом чекпоинты не засчитываются', function () {
    const sim = new RacingSim(1, {});
    sim.input(START);
    sim.input(0, RDOWN);
    run(sim, 300);
    assert.equal(sim.reactions, 0);
    assert.ok(sim.vf < 0, 'едет назад');
});

/**
 * Машина на огромном кольце (почти прямая, отбойников рядом нет): курс вдоль трассы, скорость
 * 1400; кнопки keys держатся ticks тиков.
 */
function cruise(keys, ticks) {
    const sim = new RacingSim(1, {});
    sim.track = [];
    for (let i = 0; i < RN; i++) {
        const a = i / RN * Math.PI * 2;
        sim.track.push([Math.round(3500 + Math.cos(a) * 3000), Math.round(3500 + Math.sin(a) * 3000)]);
    }
    sim.input(START);
    sim.x = sim.track[0][0] * RFP; sim.y = sim.track[0][1] * RFP;
    sim.seg = 0; sim.h = 512; sim.vx = 0; sim.vy = 1400;
    sim.input(0, RUP | keys);
    run(sim, ticks);
    return sim;
}

test('Racing: руль нарастает, пока держат, — лёгкое нажатие доворачивает, долгое — закладывает вираж', function () {
    const tap = cruise(RRIGHT, 2), hold = cruise(RRIGHT, 12);
    assert.ok(tap.steer > 0 && tap.steer < hold.steer, 'руль ' + tap.steer + ' против ' + hold.steer);
    assert.ok(hold.h - 512 > (tap.h - 512) * 5, 'поворот на ' + (hold.h - 512) + ' против ' + (tap.h - 512));

    tap.input(0, RUP);
    run(tap, 3);
    assert.equal(tap.steer, 0, 'отпустили — руль вернулся');
});

test('Racing: поворот гасит скорость, на скорости в вираже — занос, он гасит ещё', function () {
    const straight = cruise(0, 12), turn = cruise(RLEFT, 12);
    assert.equal(turn.scrape + straight.scrape, 0, 'до отбойника не доехали');
    assert.ok(turn.vf < straight.vf - 30, 'в повороте ' + turn.vf + ' против ' + straight.vf);

    let drifted = false;
    const sim = cruise(RLEFT, 1);
    for (let t = 0; t < 14; t++) { sim.advance(); if (sim.drift) drifted = true; }
    assert.ok(drifted, 'полный руль на полной скорости — занос');
});

test('Racing: отбойник — без отскока, скорость в стенку гаснет, упор с газом — остановка', function () {
    const sim = new RacingSim(1, {});
    sim.input(START);
    const p = sim.track[10], q = sim.track[11];
    const nx = -(q[1] - p[1]), ny = q[0] - p[0], len = Math.hypot(nx, ny);
    sim.x = Math.round((p[0] + nx / len * (RROAD + 8)) * RFP);
    sim.y = Math.round((p[1] + ny / len * (RROAD + 8)) * RFP);
    sim.seg = 10;
    sim.h = Math.round(Math.atan2(ny, nx) / (Math.PI * 2) * 2048 + 2048) % 2048;
    sim.input(0, RUP);

    let hit = -1, maxGrass = 0;
    for (let t = 0; t < 150; t++) {
        sim.advance();
        if (sim.grass()) maxGrass = Math.max(maxGrass, sim.vf);
        if (sim.scrape && hit < 0) hit = t;
    }
    assert.ok(hit >= 0, 'доехал до отбойника');
    assert.ok(maxGrass <= 620, 'на траве не разогнаться: ' + maxGrass);
    assert.ok(Math.hypot(sim.vx, sim.vy) < 120, 'упёрся газом в стенку — стоит: ' + Math.hypot(sim.vx, sim.vy).toFixed(0));
    assert.ok(sim.nearest()[1] <= ((RWALL - 5) * RFP) ** 2 + 1, 'по эту сторону отбойника, вплотную');
});

test('Racing: руль в сторону поворота трассы — осмысленно, против — нет; газ — не действие', function () {
    const sim = drive(2, function (s) { return Math.abs(s.aim()) >= 8 && s.vf > 600; }, 30 * 20);
    sim.input(0, RUP);
    const d = sim.aim();
    const acts = sim.acts, good = sim.good;
    sim.input(0, RUP | (d < 0 ? RLEFT : RRIGHT));
    assert.deepEqual([sim.acts - acts, sim.good - good], [1, 1], 'туда, куда уходит трасса');
    sim.input(0, RUP);
    sim.input(0, RUP | (d < 0 ? RRIGHT : RLEFT));
    assert.deepEqual([sim.acts - acts, sim.good - good], [2, 1], 'в обратную сторону');
    sim.input(0, 0);
    sim.input(0, RUP);
    assert.equal(sim.acts - acts, 2, 'газ — не действие');
});
