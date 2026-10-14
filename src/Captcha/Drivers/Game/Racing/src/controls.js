import { UP, RIGHT, DOWN, LEFT } from './sim.js';

const BITS = { up: UP, right: RIGHT, down: DOWN, left: LEFT };

/**
 * Ввод гонки: крестовина (стрелки, WASD) — газ, тормоз и руль. В лог уходит маска зажатых кнопок
 * (код 0) при каждой её смене, автоповтор не нужен. Потеря фокуса отпускает всё.
 */
export function racingControls(api) {
    let mask = 0;

    function send(next) {
        if (next === mask) return;
        mask = next;
        api.record(0, mask);
    }

    return {
        mask: function () { return mask; },

        button: function (name, down, repeat) {
            if (!(name in BITS)) return false;
            if (!repeat) send(down ? mask | BITS[name] : mask & ~BITS[name]);
            return true;
        },

        release: function () { send(0); },
    };
}
