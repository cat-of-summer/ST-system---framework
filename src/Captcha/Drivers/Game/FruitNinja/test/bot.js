import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { FP, G, H, PACK } from '../src/sim.js';

/**
 * Режет, как человек мышью: подводит клинок к точке перед фигурой (там, где она будет через
 * несколько тиков) и проводит взмах сквозь неё со скоростью 25…55 единиц за тик, как мышью. Иногда
 * промахивается мимо фигуры, между взмахами — пауза. Клинок ведётся одной позицией в тик.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);
    let x = 0, y = 0, path = [], rest = 0, placed = false;

    function line(tx, ty, speed) {
        const out = [], dx = tx - x, dy = ty - y, steps = Math.max(1, Math.ceil(Math.hypot(dx, dy) / speed));
        for (let i = 1; i <= steps; i++) out.push([Math.round(x + dx * i / steps), Math.round(y + dy * i / steps)]);
        return out;
    }

    return function (sim) {
        if (sim.idle) { placed = false; path = []; return [[START]]; }

        if (!placed) {
            x = Math.round(sim.w / 2); y = 100; placed = true;
            return [[0, x * PACK + y]];
        }

        if (!path.length) {
            if (rest > 0) { rest--; return []; }

            const live = sim.shapes.filter(function (s) { return s[2] < (H - 15) * FP; });
            if (!live.length) return [];

            const s  = live[rng.int(live.length)];
            const k  = 5;
            const tx = (s[1] + s[3] * k) / FP, ty = (s[2] + s[4] * k + G * k * (k + 1) / 2) / FP;
            const miss = rng.int(6) === 0 ? 22 : 0;
            const dir = rng.int(2) ? 1 : -1;

            const ax = tx - 30 * dir, ay = ty + 18 + miss, bx = tx + 30 * dir, by = ty - 18 + miss;
            path = line(ax, ay, 40);
            const sx = x, sy = y;
            x = ax; y = ay;
            path = path.concat(line(bx, by, 25 + rng.int(31)));
            x = sx; y = sy;
            rest = 3 + rng.int(8);
        }

        const p = path.shift();
        x = Math.max(0, Math.min(sim.w, p[0]));
        y = Math.max(0, Math.min(H, p[1]));
        return [[0, x * PACK + y]];
    };
}
