import { SW } from './sim.js';

/**
 * ◀ ▶ (стрелки) — по событию на нажатие и отпускание: нажатие ведёт корабль к краю, отпускание
 * останавливает его там, где он сейчас. Большая кнопка (пробел) — выстрел.
 */
export function invadersControls(api) {
    const held = { left: false, right: false };
    let last = 0;

    function steer() {
        const sim  = api.sim();
        const both = held.left && held.right;
        const dir  = both ? last : (held.left ? -1 : (held.right ? 1 : 0));
        const x    = dir < 0 ? SW / 2 : (dir > 0 ? sim.w - SW / 2 : sim.px);

        api.record(0, Math.round(x));
    }

    return {
        button: function (name, down, repeat) {
            if (name === 'big') {
                if (down && !repeat) api.record(1);
                return true;
            }

            if (name !== 'left' && name !== 'right') return false;
            if (repeat) return true;

            held[name] = down;
            if (down) last = name === 'left' ? -1 : 1;

            steer();
            return true;
        },
        release: function () {
            if (!held.left && !held.right) return;
            held.left = held.right = false;
            steer();
        },
    };
}
