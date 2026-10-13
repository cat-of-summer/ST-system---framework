import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Rng, idiv } from '../engine/src/rng.js';
import { Sim, START, replay, goalMet, taskScore, collectMet } from '../engine/src/sim.js';
import { crc32 } from '../engine/src/digest.js';

/** Пробная игра: 1 — очко, 2 — смерть, 3 — осмысленное действие, 4 — бессмысленное. */
class Probe extends Sim {
    get cooldown() { return 3; }
    restart() { this.lives = (this.lives || 0) + 1; this.steps = 0; }
    handle(code) {
        if (code === 1) this.score();
        if (code === 2) this.lose();
        if (code === 3) this.intent(true);
        if (code === 4) this.intent(false);
    }
    update() { this.steps++; }
    dump() { return [this.lives, this.steps]; }
}

test('Rng: xorshift32, как в PHP', function () {
    const rng = new Rng(1);
    assert.equal(rng.next(), 270369);
    assert.equal(rng.next(), 67634689);
    assert.equal(rng.next(), 2647435461);
    assert.equal(new Rng(0).s, 0x9E3779B9, 'нулевой seed заменяется константой');
    assert.equal(new Rng(0xDEADBEEF).s, 0xDEADBEEF, 'seed больше 2^31 не теряет знак');

    const int = new Rng(7);
    for (let i = 0; i < 1000; i++) {
        const v = int.int(6);
        assert.ok(v >= 0 && v < 6);
    }
});

test('idiv усекает к нулю, как intdiv', function () {
    assert.equal(idiv(7, 2), 3);
    assert.equal(idiv(-7, 2), -3);
    assert.equal(idiv(7, -2), -3);
    assert.equal(idiv(-256 * 3 - 1, 256), -3);
});

test('crc32 совпадает с эталоном', function () {
    assert.equal(crc32(''), 0);
    assert.equal(crc32('123456789'), 0xCBF43926);
});

test('жизнь: idle → игра → смерть → пауза → idle', function () {
    const sim = new Probe(1, {});
    assert.equal(sim.idle, 1);

    sim.advance();
    assert.equal(sim.steps, 0, 'до первого ввода игра стоит');

    sim.input(0);
    assert.equal(sim.idle, 0);
    sim.advance();
    assert.equal(sim.steps, 1);

    sim.input(2);
    assert.equal(sim.dead, 3);
    sim.input(1);
    assert.equal(sim.reactions, 0, 'во время паузы ввод игнорируется');

    sim.advance(); sim.advance();
    assert.equal(sim.lives, 1);
    sim.advance();
    assert.equal(sim.dead, 0);
    assert.equal(sim.lives, 2, 'после паузы — новая жизнь');
    assert.equal(sim.idle, 1, 'и снова ожидание ввода');
});

test('per_life: смерть обнуляет счёт, peak помнит лучший', function () {
    const sim = new Probe(1, {});
    sim.input(1); sim.input(1); sim.input(2);
    assert.equal(sim.reactions, 0);
    assert.equal(sim.peak, 2);
});

test('per_life: false — счёт копится через смерти', function () {
    const sim = new Probe(1, { per_life: false });
    sim.input(1); sim.input(1); sim.input(2);
    for (let i = 0; i < 3; i++) sim.advance();
    sim.input(1);
    assert.equal(sim.reactions, 3);
    assert.equal(sim.peak, 3);
});

test('replay: на тике сначала вводы, потом шаг; остановка на цели', function () {
    const log = [[0, 0], [2, 1], [2, 1], [5, 1]];

    const full = replay(Probe, 1, {}, log, 10, 0);
    assert.equal(full.tick, 10);
    assert.equal(full.peak, 3);
    assert.equal(full.steps, 10, 'первый ввод на тике 0 — шаг 0 уже игровой');

    const stopped = replay(Probe, 1, {}, log, 10, 2);
    assert.equal(stopped.tick, 2, 'цель набрана вводами тика 2 — до его шага');
    assert.equal(stopped.peak, 2);
});

test('goalMet: цель и доля осмысленных действий', function () {
    const sim = new Probe(1, {});
    sim.input(1); sim.input(1);
    assert.ok(goalMet(sim, 2, 60), 'без действий доля не мешает');

    sim.input(3); sim.input(4); sim.input(4);
    assert.ok(!goalMet(sim, 2, 60), '1 из 3 — меньше 60 %');

    sim.input(3); sim.input(3);
    assert.ok(goalMet(sim, 2, 60), '3 из 5 — ровно 60 %');
    assert.ok(!goalMet(sim, 3, 60), 'цель не набрана');
});

test('taskScore: цель — 1; без цели — объём данных × осмысленность и прогресс', function () {
    const collect = { time: 1, actions: 4 };
    const quality = [0.5, 1];

    const goal = new Probe(1, {});
    goal.input(3); goal.input(1); goal.input(1);
    assert.deepEqual(taskScore(goal, 2, 60, collect, quality, 10), [1, 'goal']);

    const none = new Probe(1, {});
    assert.deepEqual(taskScore(none, 2, 60, collect, quality, 10), [0, 'progress'], 'без действий — ноль');

    const half = new Probe(1, {});
    half.input(3); half.input(3); half.input(3); half.input(4);
    for (let i = 0; i < 10; i++) half.advance();
    // объём 1 × (0.75 · (0.75 − 0.5) / 0.5 + 0.25 · 0) = 0.375
    assert.deepEqual(taskScore(half, 2, 60, collect, quality, 10), [0.375, 'progress']);

    half.input(1);
    // прогресс 1 из 2: 0.375 + 0.25 · 0.5
    assert.deepEqual(taskScore(half, 2, 60, collect, quality, 10), [0.5, 'progress']);

    const short = new Probe(1, {});
    short.input(3); short.input(3);
    for (let i = 0; i < 5; i++) short.advance();
    // объём (2/4) · (5/10) = 0.25, осмысленность 1
    assert.deepEqual(taskScore(short, 2, 60, collect, quality, 10), [0.1875, 'progress']);
});

test('collectMet: данных достаточно — время и число действий, без оценки качества', function () {
    const collect = { time: 1, actions: 3 };
    const sim     = new Probe(1, {});
    sim.input(4); sim.input(4); sim.input(4);

    assert.ok(!collectMet(sim, collect, 10), 'не сыграно секунды');
    for (let i = 0; i < 10; i++) sim.advance();
    assert.ok(collectMet(sim, collect, 10), 'все три бессмысленные — данных всё равно хватает');
    assert.ok(!collectMet(sim, null, 10), 'без порога — только по времени партии');
});

test('START: выводит из ожидания, в игру не уходит и действием не считается', function () {
    const sim = new Probe(1, {});
    sim.input(START);
    assert.equal(sim.idle, 0);
    assert.equal(sim.acts, 0);

    sim.advance();
    assert.equal(sim.steps, 1, 'игра идёт');

    sim.input(2);
    for (let i = 0; i < 3; i++) sim.advance();
    assert.equal(sim.idle, 1, 'после смерти снова ждёт');
    sim.input(START);
    assert.equal(sim.idle, 0, 'перезапуск');
});

test('state(): база, счётчики и состояние игры', function () {
    const sim = new Probe(5, {});
    sim.input(3);
    assert.deepEqual(sim.state().slice(0, 7), [0, 0, 0, 0, 0, 1, 1]);
    assert.equal(sim.state()[7], sim.rng.s);
    assert.deepEqual(sim.state().slice(8), [1, 0]);
});
