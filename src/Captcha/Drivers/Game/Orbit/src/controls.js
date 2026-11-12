import { HUD } from '../../assets/engine/src/driver.js';
import { MAX_PULL, MIN_PULL, PULL_Q, packPull } from './sim.js';

/** Где мир на холсте: масштаб (пикселей холста на единицу) и сдвиг, мир по центру игровой зоны. */
export function frame(sim, W, H) {
    const s = Math.min(W / sim.w, (H - HUD) / sim.h);
    return { s: s, x: (W - sim.w * s) / 2, y: HUD + (H - HUD - sim.h * s) / 2 };
}

/**
 * Ввод рогатки: нажатие в любой точке поля — якорь, ракета летит от пальца к якорю. Код 0 —
 * начали тянуть (игра замедляется), 1 — отпустили, value — натяжение в половинах единицы мира
 * (packPull), 2 — отмена (pointercancel, потеря фокуса, смерть). Положение пальца между нажатием
 * и отпусканием в лог не идёт — только в ui.aim для вида.
 */
export function orbitControls(api) {
    const ui = { aim: null };

    /** Натяжение в единицах мира, не длиннее MAX_PULL: [x, y, длина], и оно же в коде ввода. */
    function pull(aim) {
        const s = frame(api.sim(), api.W, api.H).s;
        let x = (aim.ax - aim.x) / s, y = (aim.ay - aim.y) / s;
        const len = Math.hypot(x, y);
        if (len > MAX_PULL) { x *= MAX_PULL / len; y *= MAX_PULL / len; }

        const qx = Math.max(-PULL_Q, Math.min(PULL_Q, Math.round(x / 2)));
        const qy = Math.max(-PULL_Q, Math.min(PULL_Q, Math.round(y / 2)));
        return { x: x, y: y, len: Math.min(len, MAX_PULL), qx: qx, qy: qy, live: len >= MIN_PULL };
    }

    function cancel() {
        if (!ui.aim) return;
        ui.aim = null;
        if (api.started()) api.record(2);
    }

    return {
        ui: ui,
        pull: pull,

        press: function (x, y, button, kind) {
            if (kind === 'mouse' && button !== 0) return;
            ui.aim = { ax: x, ay: y, x: x, y: y };
            api.record(0);
        },

        move: function (x, y) {
            if (!ui.aim) return;
            ui.aim.x = x;
            ui.aim.y = y;
        },

        up: function (x, y, ok) {
            if (!ui.aim) return;
            if (!ok) { cancel(); return; }

            ui.aim.x = x;
            ui.aim.y = y;
            const p = pull(ui.aim);
            ui.aim = null;
            api.record(1, packPull(p.qx, p.qy));
        },

        release: cancel,
    };
}
