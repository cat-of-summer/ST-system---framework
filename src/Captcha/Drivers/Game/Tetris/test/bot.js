import { Rng } from '../../assets/engine/src/rng.js';
import { SHAPES, COLS, ROWS } from '../src/sim.js';

/** Оценивает все положения текущей фигуры (линии, дыры, высота) и ведёт её туда; иногда ошибается. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x1F1F1F1F);

    function evaluate(sim, rot, x) {
        const fits = function (y) {
            for (const c of SHAPES[sim.type][rot]) {
                const cx = x + c[0], cy = y + c[1];
                if (cx < 0 || cx >= COLS || cy >= ROWS) return false;
                if (cy >= 0 && sim.grid[cy * COLS + cx]) return false;
            }
            return true;
        };

        if (!fits(0)) return null;

        let y = 0;
        while (fits(y + 1)) y++;

        const grid = sim.grid.slice();
        for (const c of SHAPES[sim.type][rot]) grid[(y + c[1]) * COLS + x + c[0]] = 1;

        let lines = 0, holes = 0, height = 0;

        for (let r = 0; r < ROWS; r++) {
            let full = true;
            for (let cx = 0; cx < COLS; cx++) if (!grid[r * COLS + cx]) full = false;
            if (full) lines++;
        }

        for (let cx = 0; cx < COLS; cx++) {
            let seen = false;
            for (let r = 0; r < ROWS; r++) {
                if (grid[r * COLS + cx]) { if (!seen) height = Math.max(height, ROWS - r); seen = true; }
                else if (seen) holes++;
            }
        }

        return lines * 20 - holes * 6 - height;
    }

    return function (sim) {
        if (sim.idle) return [[2]];
        if (rng.int(12) === 0) return [[rng.int(5)]];
        if (rng.int(2) === 0) return [];

        let best = null;

        for (let rot = 0; rot < 4; rot++)
            for (let x = -2; x < COLS; x++) {
                const score = evaluate(sim, rot, x);
                if (score !== null && (!best || score > best.score)) best = { score, rot, x };
            }

        if (!best) return [[4]];
        if (best.rot !== sim.rot) return [[0]];
        if (best.x > sim.x) return [[1]];
        if (best.x < sim.x) return [[3]];
        return [[4]];
    };
}
