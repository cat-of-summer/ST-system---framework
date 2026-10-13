/**
 * Фикстуры детерминизма JS ↔ PHP: бот играет JS-симуляцией, лог и итоговый digest пишутся
 * в tests/Fixtures/Captcha/Game/<игра>.json, а PHPUnit прогоняет тот же лог PHP-симуляцией и
 * сверяет digest. Разошлись — значит, порт на PHP отличается от sim.js.
 *
 * Кроме партий на сверку:
 *   pass          — партия до цели (полный цикл putCaptcha → check), не дольше max_time;
 *   progress_pass — бот играет, как браузер, до сбора данных (collect); task — его оценка игры
 *                   при недостижимой цели (goal 50): осмысленная игра без очков;
 *   random        — случайные нажатия до сбора данных или max_time: при обычном поведении
 *                   на странице не проходят.
 *
 * Запуск: npm test (в src/Captcha/Drivers/Game/assets).
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { replay, goalMet, taskScore, collectMet } from '../engine/src/sim.js';
import { Rng } from '../engine/src/rng.js';
import { digest } from '../engine/src/digest.js';
import { GAMES, collectFor, qualityFor, goalRatioFor, maxTimeFor, paramsFor, publicParamsFor, decide } from './games.js';

// Поведенческий скоринг обычного человека для проверок генератора.
const HUMAN = 0.6;

const here = dirname(fileURLToPath(import.meta.url));
const out  = resolve(here, '../../../../../../tests/Fixtures/Captcha/Game');

const SEEDS = [1, 12345, 0xDEADBEEF, 777777];

/**
 * Партия так, как её записал бы клиент: вводы на текущем тике, затем шаг. Останавливается на
 * цели (goal > 0), на сборе данных (collect — как браузер) или через ticks тиков.
 */
function play(SimClass, strategy, seed, params, ticks, goal, ratio, collect, tps) {
    const sim = new SimClass(seed, params);
    const log = [];

    for (;;) {
        for (const action of strategy(sim)) {
            if (sim.dead > 0) break;
            log.push(action[1] ? [sim.tick, action[0], action[1]] : [sim.tick, action[0]]);
            sim.input(action[0], action[1] || 0);
        }

        if ((goal > 0 && goalMet(sim, goal, ratio)) || sim.tick >= ticks) break;

        sim.advance();

        if (goal > 0 && goalMet(sim, goal, ratio)) break;
        if (collect && collectMet(sim, collect, tps)) break;
    }

    return { sim, log };
}

/** Случайные нажатия: каждый тик с вероятностью ~1/6 — случайный код из набора игры. */
function noise(codes, values, every) {
    return function (seed) {
        const rng = new Rng(seed ^ 0x13579BDF);
        return function () {
            if (rng.int(every) !== 0) return [];
            const code = codes[rng.int(codes.length)];
            return [values ? [code, values[0] + rng.int(values[1] - values[0])] : [code]];
        };
    };
}

mkdirSync(out, { recursive: true });

const only = process.argv.slice(2);

for (const [name, game] of Object.entries(GAMES)) {
    if (only.length && !only.includes(name)) continue;

    const SimClass = (await import('../../' + game.dir + '/src/sim.js'))[game.cls];
    const { bot }  = await import('../../' + game.dir + '/test/bot.js');
    const tps      = game.tps;
    const maxTicks = tps * maxTimeFor(name);
    const ratio    = goalRatioFor(name);
    const collect  = collectFor(name);
    const quality  = qualityFor(name);
    const paramsOf = function (seed, extra) {
        return Object.assign(paramsFor(name, seed), extra || {});
    };
    const cases = [];

    // Последняя партия — со счётом через смерти (per_life: false).
    const runs = SEEDS.map(function (seed) { return [seed, paramsOf(seed)]; })
        .concat([[4242, paramsOf(4242, { per_life: false })]]);

    for (const [seed, params] of runs) {
        const { sim, log } = play(SimClass, bot(seed), seed, params, maxTicks, 0, ratio, null, tps);
        const again        = replay(SimClass, seed, params, log, maxTicks, 0);

        if (digest(again) !== digest(sim))
            throw new Error(name + ': replay расходится с живой партией (seed ' + seed + ')');

        cases.push({ seed, params, ticks: maxTicks, log, peak: sim.peak, acts: sim.acts, good: sim.good, digest: digest(sim) });
    }

    const seed   = SEEDS[0];
    const params = paramsOf(seed);

    // Партия до цели — успеть за max_time.
    const win = play(SimClass, bot(seed), seed, params, maxTicks, game.goal, ratio, null, tps);
    if (!goalMet(win.sim, game.goal, ratio))
        throw new Error(name + ': бот не набрал ' + game.goal + ' за ' + maxTicks + ' тиков');

    // Партия, как в браузере: до сбора данных. Без очков (цель 50) осмысленная игра проходит.
    // Бот шумный: берётся первый seed, где он сыграл осмысленно; долю проходов на сотнях seed
    // проверяет отдельный замер.
    let progress = null;
    if (collect) {
        for (const pseed of SEEDS) {
            const pparams = paramsOf(pseed);
            const p       = play(SimClass, bot(pseed), pseed, pparams, maxTicks, 0, ratio, collect, tps);
            const task    = taskScore(p.sim, 50, ratio, collect, quality, tps)[0];
            if (decide(task, HUMAN)) { progress = p; progress.task = task; progress.seed = pseed; progress.params = pparams; break; }
        }
        if (!progress) throw new Error(name + ': осмысленная игра без очков не проходит ни на одном seed');
    }

    // Случайные нажатия, как их отдал бы браузер: при обычном поведении на странице не проходят.
    // Проверяются два темпа — редкие нажатия и частые.
    let rnd = null;
    for (const every of [6, 2]) {
        const r    = play(SimClass, noise(game.codes, game.values, every)(seed), seed, params, maxTicks, 0, ratio, collect, tps);
        const task = taskScore(r.sim, game.goal, ratio, collect, quality, tps)[0];
        const pass = game.required ? task === 1 : decide(task, HUMAN);
        if (pass)
            throw new Error(name + ': случайные нажатия (раз в ' + every + ' тиков) прошли: '
                + JSON.stringify({ task: task, peak: r.sim.peak, acts: r.sim.acts, good: r.sim.good, tick: r.sim.tick }));
        if (!rnd) { rnd = r; rnd.task = task; }
    }

    const fixture = {
        name, tps, goal: game.goal, goal_ratio: ratio, collect, quality, required: !!game.required, max_time: maxTimeFor(name), cases,
        public_params: publicParamsFor(name),
        pass:   { seed, params, ticks: win.sim.tick, log: win.log, goal: game.goal },
        random: { seed, params, ticks: rnd.sim.tick, log: rnd.log, acts: rnd.sim.acts, good: rnd.sim.good, peak: rnd.sim.peak, task: rnd.task },
    };

    if (progress) fixture.progress_pass = { seed: progress.seed, params: progress.params, ticks: progress.sim.tick, log: progress.log, acts: progress.sim.acts, good: progress.sim.good, peak: progress.sim.peak, task: progress.task };

    writeFileSync(resolve(out, name + '.json'), JSON.stringify(fixture) + '\n');

    const sec = function (t) { return (t / tps).toFixed(1) + 'с'; };
    console.log(name.padEnd(11),
        'peaks', cases.map(function (c) { return c.peak; }).join(','),
        '| goal', sec(win.sim.tick),
        progress ? '| без очков ' + sec(progress.sim.tick) + ' ' + progress.sim.good + '/' + progress.sim.acts + ' T ' + progress.task : '| без очков —',
        '| random', rnd.sim.good + '/' + rnd.sim.acts, 'T', rnd.task, sec(rnd.sim.tick));
}
