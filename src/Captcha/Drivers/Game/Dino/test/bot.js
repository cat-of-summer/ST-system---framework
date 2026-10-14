import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { DinoSim, DX, DW, FP, GROUND } from '../src/sim.js';

/** Прыгает, когда до препятствия осталось несколько тиков; иногда прыгает зря или опаздывает. */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);

    return function (sim) {
        if (sim.idle) return [[START]];
        if (sim.y < GROUND * FP) return [];
        if (rng.int(400) === 0) return [[0]];

        const o = sim.ahead();
        if (!o) return [];

        const ticks = (DinoSim.box(o)[0] - DX - DW) * FP / sim.speed;
        return ticks >= 5 && ticks <= 12 && rng.int(6) !== 0 ? [[0]] : [];
    };
}
