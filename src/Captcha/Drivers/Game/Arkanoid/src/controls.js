import { HUD } from '../../assets/engine/src/index.js';
import { H, PW } from './sim.js';

const LEFT   = { ArrowLeft: 1, a: 1, A: 1, 'ф': 1, 'Ф': 1 };
const RIGHT  = { ArrowRight: 1, d: 1, D: 1, 'в': 1, 'В': 1 };
const LAUNCH = { ' ': 1, Enter: 1 };

/**
 * Стрелки — по событию на нажатие и отпускание, автоповтор клавиатуры не нужен: нажатие ведёт
 * ракетку к краю поля на полной скорости, отпускание останавливает её там, где она сейчас.
 * Палец ведёт ракетку к точке касания.
 */
export function arkanoidControls(api) {
    const held  = { left: false, right: false };
    const scale = (api.H - HUD) / H;
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
        key: function (key, down, repeat) {
            const dir = key in LEFT ? -1 : (key in RIGHT ? 1 : (key in LAUNCH ? 0 : null));

            if (dir === null) return false;
            if (repeat) return true;

            if (dir === 0) {
                if (down) api.record(0, aim(api.sim().px));
                return true;
            }

            if (dir < 0) held.left = down;
            else held.right = down;

            if (down) last = dir;

            steer();
            return true;
        },
        // Касание приходит в координатах холста, ракетка живёт в координатах мира.
        press: function (x) { api.record(0, aim(x / scale)); },
        move: function (x) { if (api.started()) api.queue(0, aim(x / scale)); },
        release: function () {
            if (!held.left && !held.right) return;
            held.left = held.right = false;
            steer();
        },
    };
}
