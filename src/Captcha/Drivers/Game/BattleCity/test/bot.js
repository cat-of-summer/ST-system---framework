import { Rng } from '../../assets/engine/src/rng.js';
import { DX, DY } from '../src/sim.js';

/** Стреляет, когда враг на линии огня; иначе подбирается к ближайшему врагу, иногда наугад. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x2B2B2B2B);

    function inSight(sim, dir) {
        let x = sim.player[0], y = sim.player[1];

        for (;;) {
            x += DX[dir];
            y += DY[dir];
            if (x < 0 || y < 0 || x >= sim.cols || y >= sim.rows) return false;
            if (sim.map[y * sim.cols + x] === 2) return false;
            for (const e of sim.enemies) if (e[3] && e[0] === x && e[1] === y) return true;
        }
    }

    return function (sim) {
        if (sim.idle) return [[rng.int(4)]];
        if (rng.int(3) !== 0) return [];

        for (let d = 0; d < 4; d++)
            if (inSight(sim, d)) return d === sim.player[2] ? [[4]] : [[d], [4]];

        if (rng.int(6) === 0) return [[rng.int(4)]];
        if (rng.int(10) === 0) return [[4]];

        const p = sim.player;
        let target = null, best = 1e9;

        for (const e of sim.enemies) {
            if (!e[3]) continue;
            const dist = Math.abs(e[0] - p[0]) + Math.abs(e[1] - p[1]);
            if (dist < best) { best = dist; target = e; }
        }

        if (!target) return [];

        const dx = target[0] - p[0], dy = target[1] - p[1];
        const dir = Math.abs(dx) < Math.abs(dy) && dx !== 0 ? (dx > 0 ? 1 : 3) : (dy !== 0 ? (dy > 0 ? 2 : 0) : (dx > 0 ? 1 : 3));
        const nx = p[0] + DX[dir], ny = p[1] + DY[dir];

        if (nx >= 0 && ny >= 0 && nx < sim.cols && ny < sim.rows && sim.map[ny * sim.cols + nx] === 1) return [[dir], [4]];
        return dir !== p[2] || !p[3] ? [[dir]] : [];
    };
}
