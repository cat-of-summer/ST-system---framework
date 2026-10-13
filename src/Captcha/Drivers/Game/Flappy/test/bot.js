import { Rng } from '../../assets/engine/src/rng.js';
import { FP, BX, PW, GAP } from '../src/sim.js';

/** Машет, когда проваливается ниже середины ближайшего зазора; иногда промахивается. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x3C3C3C3C);

    return function (sim) {
        if (sim.idle) return [[0]];

        const next   = sim.pipes.find(function (p) { return p[0] + PW >= BX - 8; });
        const target = next ? next[1] + GAP * 0.62 : 170;
        const y      = sim.y / FP;

        if (rng.int(40) === 0) return [[0]];
        if (y > target && sim.vy > -200 && rng.int(8) !== 0) return [[0]];

        return [];
    };
}
