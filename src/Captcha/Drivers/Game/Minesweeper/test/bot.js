import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';

/**
 * Открывает безопасные клетки рядом с открытыми и иногда ставит флажок на мину — раз в секунду
 * с небольшим. Знает мины (как серверная копия): так играет человек, который считает цифры.
 * Изредка ошибается и кликает наугад.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);

    return function (sim) {
        if (sim.idle) return [[START]];
        if (sim.won || rng.int(10) !== 0) return [];
        if (!sim.placed) return [[0, rng.int(sim.n)]];

        if (rng.int(30) === 0) return [[0, rng.int(sim.n)]];

        const safe = [], mines = [];
        for (let c = 0; c < sim.n; c++) {
            if (sim.open[c] || sim.flag[c] || !sim.touches(c)) continue;
            (sim.mine[c] ? mines : safe).push(c);
        }

        if (mines.length && rng.int(4) === 0) return [[1, mines[rng.int(mines.length)]]];
        if (safe.length) return [[0, safe[rng.int(safe.length)]]];
        return [];
    };
}
