import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { AsteroidsSim, FP, YMIN, YMAX } from '../src/sim.js';

/**
 * Уходит из полосы удара в ту сторону, где места больше, без угрозы — встаёт на линию ближайшего
 * астероида и держит огонь, пока он на линии. Реагирует не каждый тик и иногда ошибается.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x5A5A5A5A);

    return function (sim) {
        if (sim.idle) return [[START]];
        if (rng.int(3) !== 0) return [];
        if (rng.int(60) === 0) return [[0, YMIN + rng.int(YMAX - YMIN)]];

        const out = [];
        const t   = sim.threat();
        let y     = -1;

        if (t) {
            const band = AsteroidsSim.band(t) + 4 * FP;
            if (Math.abs(sim.target - t.py) < band) {
                const up = t.py - band, down = t.py + band;
                y = up >= YMIN * FP && (t.py > (YMIN + YMAX) * FP / 2 || down > YMAX * FP) ? up : down;
                y = Math.round(Math.max(YMIN, Math.min(YMAX, y / FP)));
            }
        } else {
            const a = sim.ahead();
            if (a && Math.abs(a.y - sim.target) > 3 * FP) y = Math.round(a.y / FP) + rng.int(5) - 2;
        }

        if (y >= 0 && y * FP !== sim.target) out.push([0, y]);

        const aimed = sim.aimed();
        if (aimed && !sim.firing && rng.int(3) !== 0) out.push([1]);
        else if (!aimed && sim.firing) out.push([2]);

        return out;
    };
}
