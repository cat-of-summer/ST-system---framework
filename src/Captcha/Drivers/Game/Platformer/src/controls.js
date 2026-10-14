/**
 * ◀ ▶ (стрелки) — по событию на нажатие и отпускание: зажали сторону — герой идёт туда (1 / 3),
 * отпустили — идёт в другую зажатую сторону или стоит (5). Большая кнопка — прыжок (4).
 */
export function platformerControls(api) {
    const held = { left: false, right: false };
    let last = null;

    function walk() {
        const name = held[last] ? last : (held.left ? 'left' : (held.right ? 'right' : null));
        api.record(name === 'right' ? 1 : (name === 'left' ? 3 : 5));
    }

    return {
        button: function (name, down, repeat) {
            if (name === 'big') {
                if (down && !repeat) api.record(4);
                return true;
            }

            if (name !== 'left' && name !== 'right') return false;
            if (repeat) return true;

            held[name] = down;
            if (down) last = name;

            walk();
            return true;
        },
        release: function () {
            if (!held.left && !held.right) return;
            held.left = held.right = false;
            walk();
        },
    };
}
