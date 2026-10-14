import { START } from '../../assets/engine/src/sim.js';
import { Rng } from '../../assets/engine/src/rng.js';
import { UP, DOWN, LEFT, RIGHT } from '../src/sim.js';

/**
 * Едет, как человек на клавиатуре: газ держит почти всегда (перед поворотом на скорости отпускает,
 * перед крутым — тормозит; упёршись в отбойник, сдаёт назад), руль — к точке трассы впереди, с мёртвой зоной и реакцией в несколько тиков; изредка
 * отпускает газ или дёргает руль не туда.
 */
export function bot(seed) {
    const rng = new Rng(seed ^ 0x6D6D6D6D);
    let mask = 0, wait = 0, back = 0, stuck = 0;

    return function (sim) {
        if (sim.idle) { mask = 0; return [[START]]; }
        // Упёрся в отбойник и почти стоит — сдаёт назад с вывернутым рулём, как человек.
        stuck = sim.scrape && Math.abs(sim.vf) < 150 ? stuck + 1 : 0;
        if (stuck > 20 && !back) back = 40;
        if (back > 0) {
            back--;
            const rev = DOWN | (sim.aim() > 0 ? LEFT : RIGHT);
            if (rev === mask) return [];
            mask = rev;
            return [[0, mask]];
        }

        if (--wait > 0) return [];
        wait = 3 + rng.int(4);

        // Руль держит, пока трасса уходит в сторону; в крутом повороте на скорости сбрасывает газ.
        const d = sim.aim();
        let next = rng.int(30) === 0 || (Math.abs(d) > 12 && sim.vf > 1000) ? 0 : UP;
        // Крутой поворот на скорости — тормозит, как человек перед виражом.
        if (Math.abs(d) > 20 && sim.vf > 800) next = DOWN;
        if (d < -3) next |= LEFT;
        else if (d > 3) next |= RIGHT;
        if (rng.int(40) === 0) next ^= LEFT;

        if (next === mask) return [];
        mask = next;
        return [[0, mask]];
    };
}
