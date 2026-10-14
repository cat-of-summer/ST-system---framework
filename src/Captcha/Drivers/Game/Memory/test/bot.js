import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { PLAY, MISS, DOWN } from '../src/sim.js';

/**
 * Открывает карточку наугад, а вторую — её пару, если «помнит» (три раза из четырёх), иначе тоже
 * наугад. Между кликами — 0,5…0,9 с, как у человека. Расклад знает: так играет тот, кто запомнил
 * показ.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);
    let wait = 0;

    return function (sim) {
        if (sim.idle) return [[START]];
        if (sim.phase !== PLAY && sim.phase !== MISS) { wait = 5; return []; }
        if (--wait > 0) return [];
        wait = 5 + rng.int(5);

        const down = [];
        for (let i = 0; i < sim.n; i++) if (sim.face[i] === DOWN || (sim.phase === MISS && (i === sim.missA || i === sim.missB))) down.push(i);
        if (!down.length) return [];

        if (sim.first >= 0 && rng.int(4) !== 0)
            for (const i of down) if (i !== sim.first && sim.deck[i] === sim.deck[sim.first]) return [[0, i]];

        const pick = down.filter(function (i) { return i !== sim.first; });
        return [[0, pick[rng.int(pick.length)]]];
    };
}
