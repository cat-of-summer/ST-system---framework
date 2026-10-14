import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

/** Длина последовательности в первом раунде; дальше — на шаг больше каждый раунд. */
export const FIRST = 4;

/** Фазы раунда: пауза перед показом, показ, ввод, пауза после верно повторённого раунда. */
export const WAIT = 0, SHOW = 1, INPUT = 2, DONE = 3;

/** Паузы в тиках (20 в секунду): перед показом, после раунда, на ввод одного шага, вспышка нажатия. */
export const PAUSE   = 16;
export const SUCCESS = 20;
export const TIMEOUT = 100;
export const FLASH   = 6;

/** Сколько тиков горит шаг показа и сколько темно после него — быстрее с каждым раундом. */
export function onTicks(round)  { return Math.max(5, 11 - round); }
export function offTicks(round) { return Math.max(3, 6 - idiv(round, 2)); }

/**
 * Simon: круг из buttons секторов (3…6). Симуляция сама показывает последовательность — сектор за
 * сектором, — затем ждёт, пока её повторят. Каждый раунд — новая случайная последовательность от
 * rng: в первом FIRST шагов, в каждом следующем на шаг больше; показ с раундом ускоряется.
 *
 * Код ввода 0, value — номер сектора. Нажатия вне фазы ввода не принимаются и действием не
 * считаются. Верный шаг — реакция и осмысленное действие; ошибка или TIMEOUT тиков без нажатия —
 * смерть, новая жизнь — новая последовательность. round — раунды, повторённые за жизнь: партия
 * закончена, когда их rounds (params.rounds).
 */
export class SimonSim extends Sim {

    get cooldown() { return 24; }

    setup() {
        this.buttons = Math.max(3, Math.min(6, this.params.buttons | 0 || 4));
        this.rounds  = Math.max(1, this.params.rounds | 0 || 2);
    }

    restart() {
        this.round  = 0;
        this.deal();
        this.phase  = WAIT;
        this.timer  = PAUSE;
        this.step   = 0;
        this.pos    = 0;
        this.flash  = -1;
        this.flashT = 0;
        this.wrong  = -1;
    }

    /** Новая случайная последовательность раунда: FIRST шагов в первом, на шаг больше в каждом следующем. */
    deal() {
        this.seq = [];
        for (let i = 0; i < FIRST + this.round; i++) this.seq.push(this.rng.int(this.buttons));
    }

    /** Сектор, который горит на показе, или −1. */
    lit() {
        if (this.phase !== SHOW) return -1;
        return this.timer > offTicks(this.round) ? this.seq[this.step] : -1;
    }

    handle(code, value) {
        if (code !== 0 || this.phase !== INPUT || value < 0 || value >= this.buttons) return;

        this.flash  = value;
        this.flashT = FLASH;

        if (value !== this.seq[this.pos]) {
            this.intent(false);
            this.wrong = value;
            this.lose();
            return;
        }

        this.intent(true);
        this.score();
        this.pos++;
        this.timer = TIMEOUT;

        if (this.pos === this.seq.length) {
            this.round++;
            this.phase = DONE;
            this.timer = SUCCESS;
        }
    }

    update() {
        if (this.flashT > 0 && --this.flashT === 0) this.flash = -1;

        if (this.phase === WAIT) {
            if (--this.timer === 0) {
                this.phase = SHOW;
                this.step  = 0;
                this.timer = onTicks(this.round) + offTicks(this.round);
            }
        } else if (this.phase === SHOW) {
            if (--this.timer === 0) {
                if (++this.step === this.seq.length) {
                    this.phase = INPUT;
                    this.pos   = 0;
                    this.timer = TIMEOUT;
                } else {
                    this.timer = onTicks(this.round) + offTicks(this.round);
                }
            }
        } else if (this.phase === INPUT) {
            if (--this.timer === 0) this.lose();
        } else if (--this.timer === 0) {
            this.deal();
            this.phase = WAIT;
            this.timer = PAUSE;
        }
    }

    dump() {
        return [this.buttons, this.rounds, this.round, this.phase, this.timer, this.step, this.pos,
            this.flash, this.flashT, this.wrong, this.seq.length].concat(this.seq);
    }
}
