import { Rng } from '../../assets/engine/src/rng.js';
import { DX, DY, STEP, wrapDist } from '../src/sim.js';

/** Жадный бот: к яблоку по тору, не врезаясь в себя; иногда ошибается — чтобы были смерти. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x5A5A5A5A);

    return function (sim) {
        if (sim.idle) return [[rng.int(4)]];
        // Решает раз за шаг змейки, как человек, а не каждый тик.
        if (sim.tick % STEP !== 0) return [];
        if (rng.int(15) === 0) return [[rng.int(4)]];

        const head = sim.body[0];
        let best = -1, bestDist = 1e9, ahead = 1e9;

        for (let d = 0; d < 4; d++) {
            if ((d + 2) % 4 === sim.dir) continue;
            const x = (head[0] + DX[d] + sim.cols) % sim.cols, y = (head[1] + DY[d] + sim.rows) % sim.rows;
            if (sim.body.some(function (c) { return c[0] === x && c[1] === y; })) continue;
            const dist = wrapDist(x, sim.ax, sim.cols) + wrapDist(y, sim.ay, sim.rows);
            if (d === sim.next) ahead = dist;
            if (dist < bestDist) { bestDist = dist; best = d; }
        }

        // Сворачивает, только если так ближе, чем ехать дальше.
        return best >= 0 && best !== sim.next && bestDist < ahead ? [[best]] : [];
    };
}
