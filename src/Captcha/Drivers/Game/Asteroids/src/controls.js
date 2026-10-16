import { FP, YMIN, YMAX } from './sim.js';

/**
 * ▲ ▼ (стрелки) — по событию на нажатие и отпускание: нажатие ведёт ракету к краю, отпускание
 * останавливает её там, где она сейчас. Большая кнопка (пробел) — огонь: нажата — включён,
 * отпущена — выключен; пока держат, ракета стреляет очередью.
 */
export function asteroidsControls(api) {
    const held = { up: false, down: false };
    let last = 0;

    function steer() {
        const sim  = api.sim();
        const both = held.up && held.down;
        const dir  = both ? last : (held.up ? -1 : (held.down ? 1 : 0));
        const y    = dir < 0 ? YMIN : (dir > 0 ? YMAX : Math.round(sim.y / FP));

        api.record(0, y);
    }

    /** По состоянию игры, а не кнопки: после смерти огонь выключен, и новое нажатие его включает. */
    function fire(on) {
        if (on === !!api.sim().firing) return;
        api.record(on ? 1 : 2);
    }

    return {
        button: function (name, down, repeat) {
            if (name === 'big') {
                if (!repeat) fire(down);
                return true;
            }

            if (name !== 'up' && name !== 'down') return false;
            if (repeat) return true;

            held[name] = down;
            if (down) last = name === 'up' ? -1 : 1;

            steer();
            return true;
        },
        release: function () {
            fire(false);
            if (!held.up && !held.down) return;
            held.up = held.down = false;
            steer();
        },
    };
}
