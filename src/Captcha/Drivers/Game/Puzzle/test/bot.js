import { Rng } from '../../assets/engine/src/rng.js';
import { SLOT_BITS } from '../src/sim.js';

/**
 * Ставит кусочки на места, иногда ошибается ячейкой или снимает кусочек обратно. Знает
 * перестановку (params.order) — как серверная копия; в браузере её нет.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);

    return function (sim) {
        if (rng.int(4) !== 0) return [];

        const loose = [];
        for (let k = 0; k < sim.n; k++)
            if (sim.where[k] < 0 || sim.order[k] !== sim.where[k]) loose.push(k);

        if (!loose.length) return [];

        const k = loose[rng.int(loose.length)];

        if (rng.int(8) === 0) return [[0, k * SLOT_BITS + rng.int(sim.n)]];
        if (rng.int(12) === 0 && sim.where[k] >= 0) return [[1, k]];

        return [[0, k * SLOT_BITS + sim.order[k]]];
    };
}
