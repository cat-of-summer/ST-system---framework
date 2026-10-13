import { Rng } from '../../assets/engine/src/rng.js';
import { FP, PW } from '../src/sim.js';

/** Ведёт ракетку под падающий мяч с небольшой ошибкой; иногда отвлекается — чтобы мяч падал. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x77777777);
    let lazy  = 0;

    return function (sim) {
        if (sim.idle) return [[0, sim.px + rng.int(21) - 10]];

        if (lazy > 0) { lazy--; return []; }
        if (rng.int(200) === 0) { lazy = 40; return []; }
        if (sim.vy <= 0 || rng.int(3) !== 0) return [];

        const x = Math.max(PW / 2, Math.min(sim.w - PW / 2, Math.round(sim.bx / FP) + rng.int(11) - 5));
        return Math.abs(x - sim.target) > 6 ? [[0, x]] : [];
    };
}
