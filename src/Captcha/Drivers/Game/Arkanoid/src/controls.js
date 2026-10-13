import { PW } from './sim.js';

/**
 * Кнопки ◀ ▶ (стрелки на клавиатуре) — по событию на нажатие и отпускание, автоповтор не нужен:
 * нажатие ведёт ракетку к краю поля на полной скорости, отпускание останавливает её там, где она
 * сейчас. Любое нажатие на старте жизни запускает мяч.
 */
export function arkanoidControls(api) {
    const held = { left: false, right: false };
    let last = 0;

    function aim(x) {
        return Math.round(Math.max(PW / 2, Math.min(api.sim().w - PW / 2, x)));
    }

    function steer() {
        const both = held.left && held.right;
        const dir  = both ? last : (held.left ? -1 : (held.right ? 1 : 0));
        const x    = dir < 0 ? PW / 2 : (dir > 0 ? api.sim().w - PW / 2 : api.sim().px);

        api.record(0, aim(x));
    }

    return {
        button: function (name, down, repeat) {
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
