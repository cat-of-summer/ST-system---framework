/**
 * Клавиатура наследует геймпад: у каждой кнопки свои клавиши (латиница, кириллица на тех же
 * местах, стрелки), и в раскладку попадают только кнопки, которые у игры есть.
 */
export const BUTTON_KEYS = {
    up:    ['ArrowUp', 'w', 'W', 'ц', 'Ц'],
    right: ['ArrowRight', 'd', 'D', 'в', 'В'],
    down:  ['ArrowDown', 's', 'S', 'ы', 'Ы'],
    left:  ['ArrowLeft', 'a', 'A', 'ф', 'Ф'],
    big:   [' ', 'Enter'],
};

/** Кнопки геймпада игры: крестовина (полная или часть) и большая кнопка. */
export function padButtons(pad) {
    const out = (pad && pad.dpad ? pad.dpad : []).slice();
    if (pad && pad.big) out.push('big');
    return out;
}

/**
 * Раскладка «клавиша → кнопка» для геймпада pad. keys — переопределение игры:
 * { клавиша: кнопка } добавляет или переназначает клавишу, { клавиша: null } убирает её.
 */
export function keymap(pad, keys) {
    const map = {};

    for (const button of padButtons(pad))
        for (const key of BUTTON_KEYS[button] || []) map[key] = button;

    for (const key in keys || {}) {
        if (keys[key] === null) delete map[key];
        else map[key] = keys[key];
    }

    return map;
}
