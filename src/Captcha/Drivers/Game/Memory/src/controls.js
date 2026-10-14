import { HUD } from '../../assets/engine/src/driver.js';

/** Карточка — пропорции игральной карты 63 × 88 мм; зазор — доля ширины карточки. */
export const ASPECT = 63 / 88;
const GAP    = 0.12;

/**
 * Раскладка n карточек в игровой зоне W × (H − HUD): перебор числа столбцов, берётся тот, где
 * карточка крупнее. Неполный последний ряд — по центру. Раскладка — только вид: симуляция знает
 * карточки по номерам.
 *
 * @return {{w: number, h: number, cols: number, rows: number, at: number[][]}} at[i] — левый верхний угол карточки i
 */
export function layout(W, H, n) {
    const zw = W - 12, zh = H - HUD - 12;
    let best = null;

    for (let cols = 2; cols <= n; cols++) {
        const rows = Math.ceil(n / cols);
        // Ширина карточки w: cols·w + (cols−1)·GAP·w ≤ zw и rows·w/ASPECT + (rows−1)·GAP·w ≤ zh.
        const w = Math.min(zw / (cols + (cols - 1) * GAP), zh / (rows / ASPECT + (rows - 1) * GAP));
        if (!best || w > best.w + 0.01) best = { w: w, cols: cols, rows: rows };
    }

    const w = Math.floor(best.w), h = Math.floor(w / ASPECT), g = Math.max(2, Math.round(w * GAP));
    const top = HUD + Math.round((H - HUD - (best.rows * h + (best.rows - 1) * g)) / 2);
    const at = [];

    for (let i = 0; i < n; i++) {
        const r = Math.floor(i / best.cols), c = i % best.cols;
        const inRow = Math.min(best.cols, n - r * best.cols);
        const left = Math.round((W - (inRow * w + (inRow - 1) * g)) / 2);
        at.push([left + c * (w + g), top + r * (h + g)]);
    }

    return { w: w, h: h, cols: best.cols, rows: best.rows, at: at };
}

/**
 * Ввод Memory: клик или касание по карточке открывает её — сразу, по нажатию. С клавиатуры
 * стрелки ведут курсор по раскладке, пробел или Enter открывают карточку под ним. Можно ли её
 * открыть, решает симуляция.
 *
 * ui — для отрисовки: раскладка, карточка под курсором, курсор клавиатуры.
 */
export function memoryControls(api) {
    const pairs = Math.max(2, Math.min(20, api.cfg.params.pairs | 0 || 8));
    const n     = pairs * 2;
    const L     = layout(api.W, api.H, n);
    const ui    = { layout: L, n: n, hover: -1, cursor: 0, keyboard: false, pressAt: 0 };

    function cardAt(x, y) {
        for (let i = 0; i < n; i++) {
            const p = L.at[i];
            if (x >= p[0] && x < p[0] + L.w && y >= p[1] && y < p[1] + L.h) return i;
        }
        return -1;
    }

    function open(i) {
        ui.pressAt = performance.now();
        api.record(0, i);
    }

    return {
        ui: ui,
        cardAt: cardAt,

        press: function (x, y, button) {
            ui.keyboard = false;
            const i = cardAt(x, y);
            if (i >= 0 && !button) open(i);
        },

        move: function (x, y) { ui.hover = cardAt(x, y); },
        leave: function () { ui.hover = -1; },

        key: function (key, down, repeat) {
            const move = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
            if (!(key in move) && key !== ' ' && key !== 'Enter') return false;
            if (!down) return true;

            ui.keyboard = true;

            if (key in move) {
                const r = Math.floor(ui.cursor / L.cols) + move[key][1];
                const c = ui.cursor % L.cols + move[key][0];
                if (r >= 0 && r < L.rows && c >= 0 && c < L.cols) {
                    // В неполном последнем ряду — ближайшая существующая карточка.
                    ui.cursor = Math.min(n - 1, r * L.cols + c);
                }
            } else if (!repeat) {
                open(ui.cursor);
            }
            return true;
        },
    };
}
