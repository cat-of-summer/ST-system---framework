import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { SHIP_Y, SW } from '../src/sim.js';

/** Уходит от бомб, подводит корабль под ближайшую колонку и стреляет; иногда ошибается. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x1A1A1A1A);

    return function (sim) {
        if (sim.idle) return [[START]];
        if (rng.int(3) !== 0) return [];
        if (rng.int(40) === 0) return [[0, SW + rng.int(sim.w - 2 * SW)]];

        // Бомба рядом — в сторону от неё.
        for (const b of sim.bombs)
            if (Math.abs(b[0] - sim.px) < 16 && SHIP_Y - b[1] < 60) {
                const x = b[0] < sim.px ? Math.min(sim.w - SW, sim.px + 30) : Math.max(SW, sim.px - 30);
                return x !== sim.target ? [[0, x]] : [];
            }

        if (sim.pby < 0 && sim.aimed() && rng.int(4) !== 0) return [[1]];

        const nx = sim.nearest();
        if (nx < 0) return [];

        const x = Math.max(SW / 2, Math.min(sim.w - SW / 2, nx + rng.int(5) - 2));
        return Math.abs(x - sim.target) > 4 ? [[0, x]] : [];
    };
}
