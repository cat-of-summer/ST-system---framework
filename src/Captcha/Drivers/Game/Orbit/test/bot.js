import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { ORBIT, PULL_Q, launchVelocity, outcome, packPull } from '../src/sim.js';

/**
 * Стреляет, как человек с подсказкой траектории: на орбите выжидает, начинает тянуть рогатку
 * (игра замедляется), подбирает натяжение — перебором направлений и силы находит выстрелы, которые
 * кончаются орбитой другой планеты, и отпускает один из них. Иногда стреляет наугад; не нашёл
 * выстрела — отменяет прицеливание и ждёт, пока орбита повернёт ракету.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x0B17B17B);
    let wait = 0, aim = -1;

    function shot(sim) {
        if (rng.int(6) === 0) {
            const t = rng.int(360) * Math.PI / 180, p = 20 + rng.int(PULL_Q - 20);
            return packPull(Math.round(Math.cos(t) * p), Math.round(Math.sin(t) * p));
        }

        const good = [];
        for (let a = 0; a < 72; a++) {
            const t = a * Math.PI / 36;
            for (const p of [20, 45, 70, 95, PULL_Q]) {
                const qx = Math.round(Math.cos(t) * p), qy = Math.round(Math.sin(t) * p);
                const v  = launchVelocity(qx, qy);
                if (!v) continue;
                const r = outcome(sim, v[0], v[1]);
                if (r >= 0 && r !== sim.planet) good.push(packPull(qx, qy));
            }
        }
        return good.length ? good[rng.int(good.length)] : -1;
    }

    return function (sim) {
        if (sim.idle) { wait = 10 + rng.int(30); aim = -1; return [[START]]; }
        if (sim.mode !== ORBIT) { aim = -1; return []; }

        if (aim < 0) {
            if (wait-- > 0) return [];
            aim = 8 + rng.int(25);
            return [[0]];
        }

        if (aim-- > 0) return [];

        const value = shot(sim);
        wait = 20 + rng.int(70);
        aim  = -1;
        return value < 0 ? [[2]] : [[1, value]];
    };
}
