import { HUD } from '../../assets/engine/src/driver.js';
import { H, PACK } from './sim.js';

/** Сколько миллисекунд хвоста клинка рисуется. */
export const TRAIL_MS = 140;

/**
 * Ввод Fruit Ninja. Клинок — точка в мире, одна позиция за тик (api.queue): на десктопе его ведёт
 * курсор без нажатия, уход курсора с поля поднимает клинок; на телефоне — палец: касание ставит
 * клинок, ведение режет, отрыв поднимает. Нажатия мыши ничего не добавляют — курсор и так режет.
 *
 * ui.trail — точки пути на экране с временем, для следа клинка: он рисуется по кадрам, а не по тикам.
 */
export function fruitControls(api) {
    const scale = (api.H - HUD) / H;
    const ui    = { trail: [], touching: false };

    /** Точка холста → value кода 0: x · PACK + y в единицах мира. */
    function pack(x, y) {
        const w  = api.sim().w;
        const wx = Math.max(0, Math.min(w, Math.round(x / scale)));
        const wy = Math.max(0, Math.min(H, Math.round((y - HUD) / scale)));
        return wx * PACK + wy;
    }

    function trail(x, y) {
        ui.trail.push({ x: x, y: y, t: performance.now() });
        if (ui.trail.length > 40) ui.trail.shift();
    }

    function lift() {
        ui.trail = [];
        ui.touching = false;
        if (api.started()) api.record(1);
    }

    return {
        ui: ui,
        pack: pack,

        press: function (x, y, button, kind) {
            if (kind === 'mouse') return;
            ui.touching = true;
            ui.trail = [];
            trail(x, y);
            api.record(0, pack(x, y));
        },

        move: function (x, y) {
            trail(x, y);
            if (api.started()) api.queue(0, pack(x, y));
        },

        up: function (x, y) {
            if (!ui.touching) return;
            // Последняя точка — сразу, затем отрыв: отложенная позиция до отрыва не дожидается.
            api.record(0, pack(x, y));
            lift();
        },

        leave: lift,

        release: function () {
            if (ui.touching || ui.trail.length) lift();
        },
    };
}
