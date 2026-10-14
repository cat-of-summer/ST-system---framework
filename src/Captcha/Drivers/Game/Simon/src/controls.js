import { HUD } from '../../assets/engine/src/driver.js';

/**
 * Круг в игровой зоне: центр (x, y), внешний радиус R, радиус ступицы r и зазор между секторами.
 * Сектор i — по центру угла −90° + i · 360° / n: при четырёх — верх, право, низ, лево.
 */
export function geometry(W, H, n) {
    const R = Math.max(30, Math.min(W / 2, (H - HUD) / 2) - 10);
    return { x: W / 2, y: HUD + (H - HUD) / 2, R: R, r: R * 0.38, gap: Math.max(2, R * 0.035), n: n };
}

/** Угол середины сектора i, радианы. */
export function sectorAngle(g, i) {
    return -Math.PI / 2 + i * Math.PI * 2 / g.n;
}

/** Сектор под точкой: между ступицей и внешним краем (с запасом для пальца), иначе −1. */
export function sectorAt(g, x, y) {
    const dx = x - g.x, dy = y - g.y, d = Math.hypot(dx, dy);
    if (d < g.r || d > g.R + 12) return -1;

    const step = Math.PI * 2 / g.n;
    const a    = Math.atan2(dy, dx) + Math.PI / 2 + step / 2;
    return Math.floor(((a % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2) / step) % g.n;
}

// Стрелка — направление на круге; ей соответствует ближайший к нему сектор.
const ARROWS = { ArrowUp: -Math.PI / 2, ArrowRight: 0, ArrowDown: Math.PI / 2, ArrowLeft: Math.PI };

/**
 * Ввод Simon: клик или касание сектора, цифры 1…n, стрелки — сектор в их стороне (при четырёх
 * секторах — ровно верх, право, низ, лево). Нажатие уходит в лог сразу; принять его или нет,
 * решает симуляция (на показе нажатия не принимаются).
 *
 * ui — для отрисовки: сектор под курсором, последнее нажатие экрана, пользуется ли игрок клавиатурой.
 */
export function simonControls(api) {
    const n  = Math.max(3, Math.min(6, api.cfg.params.buttons | 0 || 4));
    const g  = geometry(api.W, api.H, n);
    const ui = { g: g, hover: -1, keyboard: false, pressed: -1, pressAt: 0 };

    function push(i) {
        ui.pressed = i;
        ui.pressAt = performance.now();
        api.record(0, i);
    }

    return {
        ui: ui,

        press: function (x, y, button) {
            ui.keyboard = false;
            const i = sectorAt(g, x, y);
            if (i >= 0 && !button) push(i);
        },

        move: function (x, y) { ui.hover = sectorAt(g, x, y); },
        leave: function () { ui.hover = -1; },

        key: function (key, down, repeat) {
            let i = -1;

            if (key.length === 1 && key >= '1' && key <= String(n)) i = key.charCodeAt(0) - 49;
            else if (key in ARROWS) {
                let best = Infinity;
                for (let k = 0; k < n; k++) {
                    const d = Math.abs(Math.atan2(Math.sin(sectorAngle(g, k) - ARROWS[key]), Math.cos(sectorAngle(g, k) - ARROWS[key])));
                    if (d < best - 1e-9) { best = d; i = k; }
                }
            }

            if (i < 0) return false;
            if (down && !repeat) {
                ui.keyboard = true;
                push(i);
            }
            return true;
        },
    };
}
