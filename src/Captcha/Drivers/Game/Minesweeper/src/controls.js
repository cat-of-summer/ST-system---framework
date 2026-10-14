import { HUD } from '../../assets/engine/src/driver.js';

/** Сколько держать палец, чтобы вместо открытия был флажок или открытие соседей числа, мс. */
export const HOLD_MS = 400;

/** Поле в игровой зоне: клетка s, левый верхний угол (x, y), рамка вокруг — на четверть клетки. */
export function geometry(W, H, cols, rows) {
    const s = Math.floor(Math.min(W / (cols + 0.5), (H - HUD) / (rows + 0.5)));
    return { s: s, x: Math.round((W - s * cols) / 2), y: Math.round(HUD + (H - HUD - s * rows) / 2) };
}

/**
 * Ввод сапёра. Мышь — без зажатия: левая кнопка открывает клетку (по открытому числу — его соседей),
 * правая ставит флажок (по открытому числу — тоже открывает соседей), средняя — соседей.
 * Палец — тап и долгое нажатие: тап открывает клетку (по числу — соседей), удержание HOLD_MS
 * ставит флажок (по числу — открывает соседей) сразу, не дожидаясь, пока палец оторвут.
 *
 * ui — то, что нужно отрисовке: клетка под курсором, курсор клавиатуры, нажатая клетка, откуда
 * пошёл последний ход.
 */
export function minesControls(api) {
    const cols = api.cfg.params.cols | 0 || 9;
    const rows = api.cfg.params.rows | 0 || 6;
    const g    = geometry(api.W, api.H, cols, rows);

    const ui = {
        g: g, cols: cols, rows: rows,
        hover: -1, cursor: Math.floor(rows / 2) * cols + Math.floor(cols / 2), keyboard: false,
        hold: null, origin: -1, originAt: 0,
    };

    function cellAt(x, y) {
        const c = Math.floor((x - g.x) / g.s), r = Math.floor((y - g.y) / g.s);
        return c >= 0 && c < cols && r >= 0 && r < rows ? r * cols + c : -1;
    }

    function act(code, cell) {
        ui.origin   = cell;
        ui.originAt = performance.now();
        api.record(code, cell);
    }

    /** Основное действие по клетке: закрытую — открыть, открытое число — открыть соседей. */
    function primary(cell) {
        act(api.sim().open[cell] ? 2 : 0, cell);
    }

    /** Второе действие: на закрытой — флажок, на открытом числе — тоже открыть соседей. */
    function secondary(cell) {
        act(api.sim().open[cell] ? 2 : 1, cell);
    }

    function cancel() {
        if (ui.hold && ui.hold.timer) clearTimeout(ui.hold.timer);
        ui.hold = null;
    }

    return {
        ui: ui,
        cellAt: cellAt,

        press: function (x, y, button, kind) {
            const c = cellAt(x, y);
            ui.keyboard = false;
            cancel();
            if (c < 0) return;

            if (kind === 'mouse') {
                if (button === 2) { secondary(c); return; }
                if (button === 1) { act(2, c); return; }
                if (button !== 0) return;
                // Клик: ход — на отпускании, над той же клеткой; зажатие ничего не добавляет.
                ui.hold = { cell: c, x: x, y: y, t0: performance.now(), fired: false, timer: null, mouse: true };
                return;
            }

            const h = { cell: c, x: x, y: y, t0: performance.now(), fired: false, timer: null, mouse: false };
            h.timer = setTimeout(function () {
                if (ui.hold !== h) return;
                h.fired = true;
                h.timer = null;
                secondary(c);
                try { if (navigator.vibrate) navigator.vibrate(15); } catch (_) {}
            }, HOLD_MS);
            ui.hold = h;
        },

        move: function (x, y) {
            ui.hover = cellAt(x, y);
            // Палец или мышь уехали на другую клетку — это не нажатие.
            if (ui.hold && !ui.hold.fired && ui.hover !== ui.hold.cell) cancel();
        },

        up: function (x, y, ok) {
            const h = ui.hold;
            cancel();
            if (!h || h.fired || !ok || cellAt(x, y) !== h.cell) return;

            primary(h.cell);
        },

        leave: function () { ui.hover = -1; },

        key: function (key, down) {
            const move = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
            const flagKey = key === 'f' || key === 'F' || key === 'а' || key === 'А';

            if (!(key in move) && key !== ' ' && key !== 'Enter' && !flagKey) return false;
            if (!down) return true;

            ui.keyboard = true;

            if (key in move) {
                const c = ui.cursor % cols + move[key][0], r = Math.floor(ui.cursor / cols) + move[key][1];
                if (c >= 0 && c < cols && r >= 0 && r < rows) ui.cursor = r * cols + c;
            } else if (flagKey) {
                secondary(ui.cursor);
            } else {
                primary(ui.cursor);
            }
            return true;
        },

        release: cancel,
    };
}
