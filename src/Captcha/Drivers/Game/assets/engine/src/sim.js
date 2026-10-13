import { Rng } from './rng.js';

/**
 * Код ввода «начать»: клик по кнопке старта или перезапуска. Выводит игру из ожидания, но в игру
 * не уходит и действием не считается. То же, что Simulation::START в PHP.
 */
export const START = 15;

/**
 * Клиентская копия игры. Зеркало Game/Simulation.php: обе версии обязаны совпадать до бита —
 * только целые числа, деление через idiv, случайность только из Rng.
 *
 * Жизнь: idle (ждёт первого ввода) → игра → lose() → dead тиков паузы → restart() → idle.
 * При params.per_life (по умолчанию) смерть обнуляет reactions, зачёт идёт по peak — лучшему
 * результату за одну жизнь; без него счёт копится через смерти.
 *
 * acts/good — запасной зачёт: сколько было действий, по которым видно намерение (intent()),
 * и сколько из них были ответом на ситуацию.
 *
 * Наследники не объявляют полей класса: инициализаторы полей отработали бы после super()
 * и затёрли бы то, что заполнили setup() и restart().
 */
export class Sim {

    constructor(seed, params) {
        this.rng       = new Rng(seed);
        this.params    = params || {};
        this.perLife   = this.params.per_life === undefined || this.params.per_life ? 1 : 0;
        this.tick      = 0;
        this.reactions = 0;
        this.peak      = 0;
        this.dead      = 0;
        this.idle      = 1;
        this.acts      = 0;
        this.good      = 0;

        this.setup();
        this.restart();
    }

    /** Один раз на партию: то, что переживает смерть (лабиринт, карта). */
    setup() {}

    /** Новая жизнь. */
    restart() {}

    start() {}

    handle(code, value) {}

    update() {}

    /** Состояние игры целыми числами — для digest(). */
    dump() { return []; }

    /** Пауза после смерти, в тиках. */
    get cooldown() { return 20; }

    input(code, value) {
        if (this.dead > 0) return;

        if (this.idle) {
            this.idle = 0;
            this.start();
        }

        if (code !== START) this.handle(code, value | 0);
    }

    advance() {
        if (this.dead > 0) {
            if (--this.dead === 0) {
                this.restart();
                this.idle = 1;
            }
        } else if (!this.idle) {
            this.update();
        }

        this.tick++;
    }

    lose() {
        if (this.perLife) this.reactions = 0;
        this.dead = this.cooldown;
    }

    score() {
        this.reactions++;
        if (this.reactions > this.peak) this.peak = this.reactions;
    }

    /** Действие, по которому видно намерение; good — было ли оно ответом на ситуацию. */
    intent(good) {
        this.acts++;
        if (good) this.good++;
    }

    state() {
        return [this.tick, this.reactions, this.peak, this.dead, this.idle, this.acts, this.good, this.rng.s]
            .concat(this.dump());
    }
}

/**
 * Цель: набрано goal реакций, и не меньше ratio процентов действий были осмысленными — набранное
 * вслепую не засчитывается. То же, что GameCaptchaDriver::goalMet() в PHP.
 */
export function goalMet(sim, goal, ratio) {
    return sim.peak >= goal && sim.good * 100 >= ratio * sim.acts;
}

/**
 * Оценка игры 0..1 и путь, как у сервера (GameCaptchaDriver::gameScore()): цель набрана с долей
 * осмысленных не ниже ratio процентов — [1, 'goal']; иначе 'progress': объём данных ×
 * (0.75 · осмысленность + 0.25 · прогресс к цели). quality — [доля случайных, доля человека].
 * Браузер её не считает — по ней генератор фикстур подбирает пороги.
 */
export function taskScore(sim, goal, ratio, collect, quality, tps) {
    if (goalMet(sim, goal, ratio)) return [1, 'goal'];
    if (sim.acts === 0) return [0, 'progress'];

    const amount = collect
        ? Math.min(1, sim.acts / collect.actions) * Math.min(1, sim.tick / (collect.time * tps))
        : 1;
    const sense    = Math.max(0, Math.min(1, (sim.good / sim.acts - quality[0]) / Math.max(0.01, quality[1] - quality[0])));
    const progress = goal > 0 ? Math.min(1, sim.peak / goal) : 0;

    return [Math.round(amount * (0.75 * sense + 0.25 * progress) * 10000) / 10000, 'progress'];
}

/**
 * Данных достаточно: сыграно не меньше time секунд и действий с намерением не меньше actions.
 * Здесь браузер ставит галочку и отдаёт лог — хорошо ли сыграно, решает сервер. collect —
 * {time, actions} или null (тогда игра идёт до max_time).
 */
export function collectMet(sim, collect, tps) {
    return !!collect && sim.tick >= collect.time * tps && sim.acts >= collect.actions;
}

/** Прогон лога — тот же порядок, что Simulation::replay() в PHP. */
export function replay(SimClass, seed, params, log, ticks, goal) {
    const sim = new SimClass(seed, params);
    let i = 0;

    for (let tick = 0; ; tick++) {
        while (i < log.length && log[i][0] === tick) {
            sim.input(log[i][1], log[i][2] || 0);
            i++;
        }

        if ((goal > 0 && sim.peak >= goal) || tick >= ticks) break;

        sim.advance();

        if (goal > 0 && sim.peak >= goal) break;
    }

    return sim;
}
