import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { T, FP, PW } from '../src/sim.js';

/** Бежит вправо и прыгает у края обрыва, перед шипом и ступенью; иногда ошибается. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x5C5C5C5C);

    return function (sim) {
        if (sim.idle) return [[START]];
        if (rng.int(80) === 0) return [[rng.int(2) ? 3 : 1]];
        if (sim.dir !== 1 && rng.int(4) === 0) return [[1]];
        if (!sim.floor) return [];

        // Передний край героя и клетка перед ним.
        const front = Math.floor(sim.x / FP) + PW;
        const next  = Math.floor((front + 4) / T);
        const here  = sim.column();

        if (next >= sim.base() + sim.cols || next === here) return [];

        const danger = sim.g[next] === 0 || sim.spikes[next] || sim.g[next] > sim.g[here];
        return danger && next * T - front <= 6 && rng.int(8) !== 0 ? [[4]] : [];
    };
}
