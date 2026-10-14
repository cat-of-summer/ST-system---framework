/**
 * Ввод пинбола: ◀ — левый флиппер, ▶ — правый, большая кнопка — пускатель. На каждое нажатие и
 * отпускание — свой код (0/1, 2/3, 4/5), автоповтор не нужен: флиппер поднят, пока кнопку держат,
 * заряд пускателя копится, пока её держат. Потеря фокуса и смерть отпускают всё зажатое.
 */
export function pinballControls(api) {
    const held  = { left: false, right: false, big: false };
    const codes = { left: 0, right: 2, big: 4 };

    return {
        held: held,

        button: function (name, down, repeat) {
            if (!(name in codes)) return false;
            if (repeat || held[name] === down) return true;

            held[name] = down;
            api.record(codes[name] + (down ? 0 : 1));
            return true;
        },

        release: function () {
            for (const name in held)
                if (held[name]) {
                    held[name] = false;
                    api.record(codes[name] + 1);
                }
        },
    };
}
