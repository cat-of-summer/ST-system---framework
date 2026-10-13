import { test } from 'node:test';
import assert from 'node:assert/strict';
import { arkanoidControls } from '../../Arkanoid/src/controls.js';
import { battleCityControls } from '../../BattleCity/src/controls.js';
import { PW } from '../../Arkanoid/src/sim.js';

// Мир арканоида под холст 320 × 200: 498 единиц в ширину, масштаб 180 / 280.
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

// --- Arkanoid -------------------------------------------------------------------------------------

test('Arkanoid: нажатие — к краю, отпускание — стоп там, где ракетка', function () {
    const sim = { px: 120, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    assert.equal(c.key('ArrowLeft', true, false), true);
    sim.px = 90;
    c.key('ArrowLeft', false, false);

    assert.deepEqual(api.sent, [[0, PW / 2], [0, 90]]);
});

test('Arkanoid: автоповтор клавиатуры ничего не шлёт', function () {
    const api = fakeApi({ px: 120, w: W });
    const c   = arkanoidControls(api);

    c.key('ArrowRight', true, false);
    c.key('ArrowRight', true, true);
    c.key('ArrowRight', true, true);

    assert.deepEqual(api.sent, [[0, W - PW / 2]]);
});

test('Arkanoid: две стрелки — ведёт последняя, отпустил её — снова первая', function () {
    const sim = { px: 120, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    c.key('ArrowLeft', true, false);
    c.key('ArrowRight', true, false);
    c.key('ArrowRight', false, false);

    assert.deepEqual(api.sent, [[0, PW / 2], [0, W - PW / 2], [0, PW / 2]]);
});

test('Arkanoid: потеря фокуса отпускает зажатые стрелки', function () {
    const sim = { px: 70, w: W };
    const api = fakeApi(sim);
    const c   = arkanoidControls(api);

    c.release();
    assert.deepEqual(api.sent, [], 'ничего не зажато — ничего не шлёт');

    c.key('a', true, false);
    c.release();
    assert.deepEqual(api.sent, [[0, PW / 2], [0, 70]]);
});

test('Arkanoid: палец ведёт ракетку раз в тик, пробел запускает', function () {
    const api = fakeApi({ px: 120, w: W });
    const c   = arkanoidControls(api);

    c.move(500);
    c.key(' ', true, false);
    assert.deepEqual(api.sent, [['queue', 0, W - PW / 2], [0, 120]]);
    assert.equal(c.key('x', true, false), false, 'чужие клавиши не перехватываются');
});

test('Arkanoid: касание переводится из координат холста в координаты мира', function () {
    const api = fakeApi({ px: 120, w: W });
    const c   = arkanoidControls(api);

    c.press(90);
    c.move(160);
    assert.deepEqual(api.sent, [[0, 140], ['queue', 0, 249]]);
});

// --- Battle City ----------------------------------------------------------------------------------

test('Battle City: отпущенная не верхняя стрелка танк не трогает', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.key('ArrowUp', true, false);
    c.key('ArrowLeft', true, false);
    c.key('ArrowUp', false, false);
    c.key('ArrowLeft', false, false);

    assert.deepEqual(api.sent, [[0], [3], [5]]);
});

test('Battle City: отпустил верхнюю — танк едет по предыдущей зажатой', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.key('ArrowUp', true, false);
    c.key('ArrowRight', true, false);
    c.key('ArrowRight', false, false);

    assert.deepEqual(api.sent, [[0], [1], [0]]);
});

test('Battle City: автоповтор не шлёт, пробел стреляет, тап стреляет', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.key('ArrowDown', true, false);
    c.key('ArrowDown', true, true);
    c.key(' ', true, false);
    c.key(' ', true, true);
    c.tap();

    assert.deepEqual(api.sent, [[2], [4], [4]]);
});

test('Battle City: повторное нажатие той же стрелки поднимает её наверх', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.key('ArrowUp', true, false);
    c.key('ArrowLeft', true, false);
    c.key('ArrowUp', true, false);
    c.key('ArrowUp', false, false);

    assert.deepEqual(api.sent, [[0], [3], [0], [3]]);
});

test('Battle City: потеря фокуса — стоп', function () {
    const api = fakeApi({});
    const c   = battleCityControls(api);

    c.release();
    c.key('d', true, false);
    c.release();

    assert.deepEqual(api.sent, [[1], [5]]);
});
