import { test } from 'node:test';
import assert from 'node:assert/strict';
import { arkanoidControls } from '../../Arkanoid/src/controls.js';
import { battleCityControls } from '../../BattleCity/src/controls.js';
import { PW } from '../../Arkanoid/src/sim.js';
import { keymap } from '../engine/src/keys.js';
import { minesControls, HOLD_MS } from '../../Minesweeper/src/controls.js';
import { simonControls, geometry as simonGeometry, sectorAngle } from '../../Simon/src/controls.js';
import { memoryControls, layout as memoryLayout, ASPECT as MEMORY_ASPECT } from '../../Memory/src/controls.js';
import { fruitControls } from '../../FruitNinja/src/controls.js';
import { pinballControls } from '../../Pinball/src/controls.js';
import { racingControls } from '../../Racing/src/controls.js';
import { PACK as FRUIT_PACK } from '../../FruitNinja/src/sim.js';
import { HUD } from '../engine/src/driver.js';

// Мир арканоида под холст 320 × 200: 498 единиц в ширину.
const W = 498;

/** Фейковый api драйвера: записывает, что ушло в лог. */
function fakeApi(sim) {
    const sent = [];
    return {
        sent: sent,
        W: 320,
        H: 200,
        sim: function () { return sim; },
        started: function () { return true; },
        record: function (code, value) { sent.push(value === undefined ? [code] : [code, value]); },
        queue: function (code, value) { sent.push(['queue', code, value]); },
    };
}

// --- Клавиатура из геймпада -------------------------------------------------------------------

test('Клавиатура наследует геймпад: только кнопки, которые есть', function () {
    const map = keymap({ dpad: ['left', 'right'] });

    assert.equal(map.ArrowLeft, 'left');
    assert.equal(map['ф'], 'left', 'кириллица на месте A');
    assert.equal(map.d, 'right');
    assert.equal(map.ArrowUp, undefined, 'вверх у арканоида нет');
    assert.equal(map[' '], undefined, 'большой кнопки нет — пробел свободен');
});

test('Клавиатура: большая кнопка — пробел и Enter; игра добавляет и убирает клавиши', function () {
    const map = keymap({ big: true }, { ArrowUp: 'big', Enter: null });

    assert.equal(map[' '], 'big');
    assert.equal(map.ArrowUp, 'big', 'свою клавишу игра добавила');
    assert.equal(map.Enter, undefined, 'и убрала унаследованную');
});

test('Клавиатура: две половинки крестовины (гонка) — стрелки и WASD всех четырёх направлений', function () {
    const map = keymap({ zones: { left: ['up', 'down'], right: ['left', 'right'] } });

    assert.deepEqual([map.ArrowUp, map.s, map.ArrowLeft, map.d, map[' ']], ['up', 'down', 'left', 'right', undefined]);
});

test('Клавиатура: тетрис как на телефоне — вверх сбрасывает, пробел поворачивает', function () {
    const map = keymap({ dpad: ['up', 'right', 'down', 'left'], big: true });

    assert.deepEqual([map.ArrowUp, map.w, map[' '], map.ArrowDown], ['up', 'up', 'big', 'down']);
});

// --- Arkanoid -------------------------------------------------------------------------------------

test('Arkanoid: нажатие — к краю, отпускание — стоп там, где ракетка', function () {
    const sim = { px: 120, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    assert.equal(c.button('left', true, false), true);
    sim.px = 90;
    c.button('left', false, false);

    assert.deepEqual(api.sent, [[0, PW / 2], [0, 90]]);
});

test('Arkanoid: автоповтор ничего не шлёт, чужие кнопки не берутся', function () {
    const api = fakeApi({ px: 120, w: W });
    const c   = arkanoidControls(api);

    c.button('right', true, false);
    c.button('right', true, true);
    c.button('right', true, true);

    assert.deepEqual(api.sent, [[0, W - PW / 2]]);
    assert.equal(c.button('big', true, false), false, 'большой кнопки у арканоида нет');
    assert.equal(c.button('up', true, false), false);
});

test('Arkanoid: две кнопки — ведёт последняя, отпустил её — снова первая', function () {
    const sim = { px: 120, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    c.button('left', true, false);
    c.button('right', true, false);
    c.button('right', false, false);

    assert.deepEqual(api.sent, [[0, PW / 2], [0, W - PW / 2], [0, PW / 2]]);
});

test('Arkanoid: палец перешёл с ◀ на ▶ — ракетка разворачивается', function () {
    const sim = { px: 120, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    // Так приходят мягкие кнопки: старая отпускается, новая нажимается.
    c.button('left', true, false);
    sim.px = 100;
    c.button('left', false, false);
    c.button('right', true, false);

    assert.deepEqual(api.sent, [[0, PW / 2], [0, 100], [0, W - PW / 2]]);
});

test('Arkanoid: потеря фокуса отпускает зажатые кнопки', function () {
    const sim = { px: 70, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    c.release();
    assert.deepEqual(api.sent, [], 'ничего не зажато — ничего не шлёт');

    c.button('left', true, false);
    c.release();
    assert.deepEqual(api.sent, [[0, PW / 2], [0, 70]]);
});

// --- Battle City ----------------------------------------------------------------------------------

test('Battle City: отпущенная не верхняя стрелка танк не трогает', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.button('up', true, false);
    c.button('left', true, false);
    c.button('up', false, false);
    c.button('left', false, false);

    assert.deepEqual(api.sent, [[0], [3], [5]]);
});

test('Battle City: отпустил верхнюю — танк едет по предыдущей зажатой', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.button('up', true, false);
    c.button('right', true, false);
    c.button('right', false, false);

    assert.deepEqual(api.sent, [[0], [1], [0]]);
});

test('Battle City: автоповтор не шлёт, большая кнопка стреляет', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.button('down', true, false);
    c.button('down', true, true);
    c.button('big', true, false);
    c.button('big', true, true);
    c.button('big', false, false);

    assert.deepEqual(api.sent, [[2], [4]]);
});

test('Battle City: повторное нажатие той же стрелки поднимает её наверх', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.button('up', true, false);
    c.button('left', true, false);
    c.button('up', true, false);
    c.button('up', false, false);

    assert.deepEqual(api.sent, [[0], [3], [0], [3]]);
});

test('Battle City: потеря фокуса — стоп', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.release();
    c.button('right', true, false);
    c.release();

    assert.deepEqual(api.sent, [[1], [5]]);
});

// --- Minesweeper ----------------------------------------------------------------------------------

// Клетки поля 9×6: 0 — закрытая, 1 — открытое число.
const HIDDEN = 0, OPEN = 13;

/** Сапёр на холсте 320 × 200 и точка в центре клетки c. */
function mines() {
    const sim = { open: [], n: 54 };
    for (let c = 0; c < 54; c++) sim.open.push(c === OPEN ? 1 : 0);

    const api = Object.assign(fakeApi(sim), { cfg: { params: { cols: 9, rows: 6 } } });
    const c   = minesControls(api);
    const g   = c.ui.g;
    const at  = function (cell) { return [g.x + (cell % 9 + 0.5) * g.s, g.y + (Math.floor(cell / 9) + 0.5) * g.s]; };

    return { api: api, c: c, at: at };
}

function mouse(m, cell, button) {
    const p = m.at(cell);
    m.c.press(p[0], p[1], button, 'mouse');
    m.c.up(p[0], p[1], true);
}

test('Minesweeper, мышь: ЛКМ открывает клетку, по числу — соседей', function () {
    const m = mines();

    mouse(m, HIDDEN, 0);
    mouse(m, OPEN, 0);
    assert.deepEqual(m.api.sent, [[0, HIDDEN], [2, OPEN]]);
});

test('Minesweeper, мышь: ПКМ — флажок, по открытому числу — открыть соседей; средняя — соседи', function () {
    const m = mines();
    const p = m.at(HIDDEN), q = m.at(OPEN);

    m.c.press(p[0], p[1], 2, 'mouse');
    assert.deepEqual(m.api.sent, [[1, HIDDEN]], 'флажок — сразу по нажатию');
    m.c.up(p[0], p[1], true);

    m.c.press(q[0], q[1], 2, 'mouse');
    m.c.up(q[0], q[1], true);
    m.c.press(q[0], q[1], 1, 'mouse');
    assert.deepEqual(m.api.sent, [[1, HIDDEN], [2, OPEN], [2, OPEN]]);
});

test('Minesweeper, мышь: зажатие ЛКМ ничего не делает — ход только на отпускании', function (t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = mines();
    const p = m.at(HIDDEN), q = m.at(OPEN);

    m.c.press(q[0], q[1], 0, 'mouse');
    t.mock.timers.tick(HOLD_MS * 3);
    assert.deepEqual(m.api.sent, [], 'по числу — не открывает соседей сам');
    m.c.up(q[0], q[1], true);

    m.c.press(p[0], p[1], 0, 'mouse');
    t.mock.timers.tick(HOLD_MS * 3);
    assert.deepEqual(m.api.sent, [[2, OPEN]], 'на закрытой — не флажок');
    m.c.up(p[0], p[1], true);
    assert.deepEqual(m.api.sent, [[2, OPEN], [0, HIDDEN]], 'отпустил — открыл');
});

test('Minesweeper, палец: тап открывает клетку, по числу — соседей', function (t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = mines();
    const p = m.at(HIDDEN), q = m.at(OPEN);

    m.c.press(p[0], p[1], 0, 'touch');
    t.mock.timers.tick(HOLD_MS - 50);
    m.c.up(p[0], p[1], true);
    m.c.press(q[0], q[1], 0, 'touch');
    m.c.up(q[0], q[1], true);
    t.mock.timers.tick(HOLD_MS * 2);

    assert.deepEqual(m.api.sent, [[0, HIDDEN], [2, OPEN]], 'отпущенный палец таймер не доводит');
});

test('Minesweeper, палец: удержание — флажок, по числу — соседи, сразу, не отпуская', function (t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = mines();
    const p = m.at(HIDDEN), q = m.at(OPEN);

    m.c.press(p[0], p[1], 0, 'touch');
    t.mock.timers.tick(HOLD_MS - 1);
    assert.deepEqual(m.api.sent, [], 'раньше времени — ничего');
    t.mock.timers.tick(1);
    assert.deepEqual(m.api.sent, [[1, HIDDEN]], 'флажок, палец ещё на экране');
    m.c.up(p[0], p[1], true);
    assert.deepEqual(m.api.sent, [[1, HIDDEN]], 'отпускание после удержания — не тап');

    m.c.press(q[0], q[1], 0, 'touch');
    t.mock.timers.tick(HOLD_MS);
    assert.deepEqual(m.api.sent, [[1, HIDDEN], [2, OPEN]], 'соседи числа — не отпуская');
    m.c.up(q[0], q[1], true);
    assert.equal(m.api.sent.length, 2);
});

test('Minesweeper, палец: увёл на другую клетку — ни тапа, ни удержания', function (t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const m = mines();
    const p = m.at(HIDDEN), q = m.at(HIDDEN + 1);

    m.c.press(p[0], p[1], 0, 'touch');
    m.c.move(q[0], q[1]);
    t.mock.timers.tick(HOLD_MS * 2);
    m.c.up(q[0], q[1], true);

    m.c.press(p[0], p[1], 0, 'pen');
    m.c.release();
    t.mock.timers.tick(HOLD_MS * 2);

    assert.deepEqual(m.api.sent, [], 'и отпускание игры (release) удержание снимает');
});

test('Minesweeper, клавиатура: пробел — открыть или соседи, F — флажок или соседи', function () {
    const m = mines();

    for (const k of ['ArrowUp', 'ArrowUp', 'ArrowUp', 'ArrowLeft', 'ArrowLeft', 'ArrowLeft', 'ArrowLeft']) m.c.key(k, true);
    assert.equal(m.c.ui.cursor, 0);
    m.c.key(' ', true);
    m.c.key('f', true);

    m.c.ui.cursor = OPEN;
    m.c.key('Enter', true);
    m.c.key('F', true);

    assert.deepEqual(m.api.sent, [[0, 0], [1, 0], [2, OPEN], [2, OPEN]]);
    assert.equal(m.c.key('x', true), false, 'чужие клавиши не берёт');
});

// --- Simon ----------------------------------------------------------------------------------------

function simon(buttons) {
    const api = Object.assign(fakeApi({}), { cfg: { params: { buttons: buttons } } });
    const c   = simonControls(api);
    const g   = c.ui.g;
    // Точка в середине сектора i.
    const at  = function (i) {
        const a = sectorAngle(g, i), r = (g.R + g.r) / 2;
        return [g.x + Math.cos(a) * r, g.y + Math.sin(a) * r];
    };
    return { api: api, c: c, g: g, at: at };
}

test('Simon: клик или касание по сектору — его номер; ступица, зазор снаружи и правая кнопка — ничего', function () {
    for (const n of [3, 4, 5, 6]) {
        const s = simon(n);
        for (let i = 0; i < n; i++) {
            const p = s.at(i);
            s.c.press(p[0], p[1], 0, i % 2 ? 'touch' : 'mouse');
        }
        assert.deepEqual(s.api.sent, Array.from({ length: n }, function (_, i) { return [0, i]; }), n + ' секторов');
    }

    const s = simon(4);
    s.c.press(s.g.x, s.g.y, 0, 'mouse');
    s.c.press(s.g.x + s.g.R + 30, s.g.y, 0, 'mouse');
    const p = s.at(1);
    s.c.press(p[0], p[1], 2, 'mouse');
    assert.deepEqual(s.api.sent, []);
});

test('Simon: при четырёх секторах стрелки — верх, право, низ, лево; цифры 1…n; автоповтор — не нажатие', function () {
    const s = simon(4);
    for (const k of ['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft', '1', '4']) s.c.key(k, true, false);
    s.c.key('2', true, true);
    assert.equal(s.c.key('5', true, false), false, 'пятого сектора нет');
    assert.equal(s.c.key('ArrowUp', false, false), true, 'отпускание своей клавиши — взято');
    assert.deepEqual(s.api.sent, [[0, 0], [0, 1], [0, 2], [0, 3], [0, 0], [0, 3]]);

    const six = simon(6);
    six.c.key('6', true, false);
    six.c.key('ArrowUp', true, false);
    assert.deepEqual(six.api.sent, [[0, 5], [0, 0]], 'стрелка — ближайший к её стороне сектор');
});

// --- Memory ---------------------------------------------------------------------------------------

test('Memory: раскладка — карточки 63:88, как игральные, не перекрываются и лежат в игровой зоне', function () {
    for (const [W, H, n] of [[320, 200, 12], [320, 200, 16], [320, 200, 20], [240, 320, 16], [960, 540, 20]]) {
        const L = memoryLayout(W, H, n);
        assert.equal(L.at.length, n);
        assert.equal(L.h, Math.floor(L.w / MEMORY_ASPECT));
        for (let i = 0; i < n; i++) {
            const a = L.at[i];
            assert.ok(a[0] >= 0 && a[1] >= HUD && a[0] + L.w <= W && a[1] + L.h <= H, W + '×' + H + ' карточка ' + i + ' в зоне');
            for (let j = 0; j < i; j++) {
                const b = L.at[j];
                assert.ok(a[0] >= b[0] + L.w || b[0] >= a[0] + L.w || a[1] >= b[1] + L.h || b[1] >= a[1] + L.h, 'карточки ' + i + ' и ' + j);
            }
        }
    }
});

test('Memory: клик или касание по карточке — её номер сразу; мимо и правой кнопкой — ничего', function () {
    const api = Object.assign(fakeApi({}), { cfg: { params: { pairs: 8 } } });
    const c   = memoryControls(api);
    const L   = c.ui.layout;

    for (let i = 0; i < 16; i++) c.press(L.at[i][0] + L.w / 2, L.at[i][1] + L.h / 2, 0, i % 2 ? 'touch' : 'mouse');
    c.press(1, HUD + 1, 0, 'mouse');
    c.press(L.at[0][0] + 2, L.at[0][1] + 2, 2, 'mouse');

    assert.deepEqual(api.sent, Array.from({ length: 16 }, function (_, i) { return [0, i]; }));
});

test('Memory: стрелки ведут курсор по раскладке, пробел и Enter открывают карточку под ним', function () {
    const api = Object.assign(fakeApi({}), { cfg: { params: { pairs: 8 } } });
    const c   = memoryControls(api);
    const cols = c.ui.layout.cols;

    c.key('ArrowRight', true, false);
    c.key('ArrowDown', true, false);
    c.key(' ', true, false);
    c.key('ArrowLeft', true, false);
    c.key('ArrowLeft', true, false);
    c.key('ArrowUp', true, false);
    c.key('ArrowUp', true, false);
    c.key('Enter', true, false);
    c.key('Enter', true, true);

    assert.deepEqual(api.sent, [[0, cols + 1], [0, 0]], 'за край не выходит, автоповтор не открывает');
    assert.equal(c.key('x', true, false), false);
});

// --- Fruit Ninja ----------------------------------------------------------------------------------

/** Клинок на холсте 320 × 200: мир 150 единиц в высоту, 267 в ширину. */
function fruitApi(started) {
    const api = fakeApi({ w: 267 });
    api.started = function () { return started; };
    return api;
}

const S = (200 - HUD) / 150;

test('Fruit Ninja: позиция холста → точка мира x · PACK + y, за краями — на краю', function () {
    const c = fruitControls(fruitApi(true));
    assert.equal(c.pack(100 * S, HUD + 50 * S), 100 * FRUIT_PACK + 50);
    assert.equal(c.pack(-30, 0), 0);
    assert.equal(c.pack(9999, 9999), 267 * FRUIT_PACK + 150);
});

test('Fruit Ninja, мышь: курсор ведёт клинок одной позицией в тик, уход с поля поднимает; нажатия не нужны', function () {
    const api = fruitApi(true);
    const c   = fruitControls(api);

    c.press(10 * S, HUD + 10 * S, 0, 'mouse');
    c.move(20 * S, HUD + 10 * S);
    c.move(30 * S, HUD + 10 * S);
    c.leave();

    assert.deepEqual(api.sent, [['queue', 0, 20 * FRUIT_PACK + 10], ['queue', 0, 30 * FRUIT_PACK + 10], [1]]);
});

test('Fruit Ninja, палец: касание ставит клинок сразу, ведение — в очередь, отрыв — последняя точка и подъём', function () {
    const api = fruitApi(true);
    const c   = fruitControls(api);

    c.press(10 * S, HUD + 10 * S, 0, 'touch');
    c.move(40 * S, HUD + 10 * S);
    c.up(60 * S, HUD + 20 * S, true);

    assert.deepEqual(api.sent, [[0, 10 * FRUIT_PACK + 10], ['queue', 0, 40 * FRUIT_PACK + 10], [0, 60 * FRUIT_PACK + 20], [1]]);
    assert.equal(c.ui.trail.length, 0, 'след сброшен');
});

test('Fruit Ninja: до старта партии курсор над полем ничего не пишет в лог', function () {
    const api = fruitApi(false);
    const c   = fruitControls(api);

    c.move(20 * S, HUD + 10 * S);
    c.leave();
    assert.deepEqual(api.sent, []);
});

// --- Pinball --------------------------------------------------------------------------------------

test('Pinball: ◀ ▶ и большая кнопка — свой код на нажатие и отпускание, без автоповтора', function () {
    const api = fakeApi({});
    const c   = pinballControls(api);

    c.button('left', true, false);
    c.button('left', true, true);
    c.button('right', true, false);
    c.button('left', false, false);
    c.button('big', true, false);
    c.button('big', false, false);
    assert.equal(c.button('up', true, false), false, 'вверх у пинбола нет');

    assert.deepEqual(api.sent, [[0], [2], [1], [4], [5]]);
});

test('Pinball: потеря фокуса отпускает всё зажатое', function () {
    const api = fakeApi({});
    const c   = pinballControls(api);

    c.button('right', true, false);
    c.button('big', true, false);
    c.release();
    c.release();
    assert.deepEqual(api.sent, [[2], [4], [3], [5]]);
});

// --- Racing ---------------------------------------------------------------------------------------

test('Racing: крестовина — маска зажатых кнопок при каждой смене, без автоповтора; потеря фокуса — всё отпущено', function () {
    const api = fakeApi({});
    const c   = racingControls(api);

    c.button('up', true, false);
    c.button('up', true, true);
    c.button('left', true, false);
    c.button('left', false, false);
    c.button('right', true, false);
    assert.equal(c.button('big', true, false), false, 'большой кнопки у гонки нет');
    c.release();
    c.release();

    assert.deepEqual(api.sent, [[0, 1], [0, 9], [0, 1], [0, 3], [0, 0]]);
});
