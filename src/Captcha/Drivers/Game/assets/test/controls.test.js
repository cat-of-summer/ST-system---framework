import { test } from 'node:test';
import assert from 'node:assert/strict';
import { arkanoidControls } from '../../Arkanoid/src/controls.js';
import { battleCityControls } from '../../BattleCity/src/controls.js';
import { PW } from '../../Arkanoid/src/sim.js';
import { keymap } from '../engine/src/keys.js';

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
