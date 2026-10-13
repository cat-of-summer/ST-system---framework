import { Rng } from '../../assets/engine/src/rng.js';
import { DX, DY } from '../src/sim.js';

/** Идёт к вишне кратчайшим путём (BFS), сторонясь соседних с призраком клеток; иногда сворачивает наугад. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x0F0F0F0F);

    function firstStep(sim) {
        const N = sim.cols;
        const prev  = new Array(sim.walls.length).fill(-2);
        const start = sim.py * N + sim.px;
        const queue = [start];
        const risky = new Set();

        for (const g of sim.ghosts) {
            risky.add(g[1] * N + g[0]);
            for (let d = 0; d < 4; d++) risky.add((g[1] + DY[d]) * N + g[0] + DX[d]);
        }

        prev[start] = -1;

        while (queue.length) {
            const i = queue.shift();
            if (i === sim.fy * N + sim.fx) {
                let j = i;
                while (prev[j] !== start && prev[j] !== -1) j = prev[j];
                const x = j % N, y = Math.floor(j / N);
                for (let d = 0; d < 4; d++) if (sim.px + DX[d] === x && sim.py + DY[d] === y) return d;
                return -1;
            }

            const x = i % N, y = Math.floor(i / N);
            for (let d = 0; d < 4; d++) {
                const nx = x + DX[d], ny = y + DY[d], k = ny * N + nx;
                if (!sim.open(nx, ny) || prev[k] !== -2 || risky.has(k)) continue;
                prev[k] = i;
                queue.push(k);
            }
        }

        return -1;
    }

    return function (sim) {
        if (sim.idle) { const d = firstStep(sim); return [[d >= 0 ? d : rng.int(4)]]; }
        if (rng.int(40) === 0) return [[rng.int(4)]];
        if (rng.int(2) === 0) return [];

        const d = firstStep(sim);
        return d >= 0 && d !== sim.want ? [[d]] : [];
    };
}
