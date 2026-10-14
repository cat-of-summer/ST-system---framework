import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { FP, FLIPPERS } from '../src/sim.js';

/**
 * Играет, как человек: мяч на пускателе — взводит его на 0,5…0,8 с и отпускает; мяч летит вниз к
 * флипперу и уже рядом — поднимает этот флиппер на 0,2…0,3 с. Реагирует с запозданием в несколько
 * тиков, иногда опаздывает или жмёт зря.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);
    let pull = 0, hold = [0, 0], wait = 0;

    return function (sim) {
        if (sim.idle) { pull = 0; hold = [0, 0]; return [[START]]; }

        const out = [];

        // Пускатель.
        if (sim.inLane() && Math.abs(sim.vy) < 40 && !sim.pull && !pull && --wait <= 0) {
            pull = 30 + rng.int(18);
            out.push([4]);
        }
        if (pull > 0 && --pull === 0) { out.push([5]); wait = 20; }

        for (let f = 0; f < 2; f++) {
            if (hold[f] > 0) {
                if (--hold[f] === 0) out.push([f * 2 + 1]);
                continue;
            }
            const p = FLIPPERS[f];
            const x = sim.bx / FP, y = sim.by / FP;
            const near = Math.abs(y - (p[1] - 6)) < 16 && Math.abs(x - (p[0] + (f ? -14 : 14))) < 18 && sim.vy > 0;
            if ((near && rng.int(3) !== 0) || rng.int(900) === 0) {
                hold[f] = 12 + rng.int(8);
                out.push([f * 2]);
            }
        }

        return out;
    };
}
