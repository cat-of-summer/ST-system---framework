import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { INPUT } from '../src/sim.js';

/**
 * Повторяет показанную последовательность с человеческими паузами 0,3…0,7 с между нажатиями;
 * изредка ошибается сектором. Иногда жмёт во время показа — такие нажатия игра не принимает.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);
    let wait = 0;

    return function (sim) {
        if (sim.idle) return [[START]];

        if (sim.phase !== INPUT) {
            wait = 8 + rng.int(6);
            return rng.int(200) === 0 ? [[0, rng.int(sim.buttons)]] : [];
        }

        if (--wait > 0) return [];
        wait = 6 + rng.int(9);

        return [[0, rng.int(60) === 0 ? rng.int(sim.buttons) : sim.seq[sim.pos]]];
    };
}
