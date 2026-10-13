const DIRS = { up: 0, right: 1, down: 2, left: 3 };

/**
 * Стек зажатых стрелок: нажатие кладёт направление наверх — поворот и шаг, пока держат — танк
 * едет; отпускание верхней возвращает предыдущую зажатую, а если зажатых нет — стоп. Отпущенная
 * «не та» кнопка танк не поворачивает и не останавливает. Выстрел — большая кнопка (пробел).
 */
export function battleCityControls(api) {
    let held = [];

    return {
        button: function (name, down, repeat) {
            if (name === 'big') {
                if (down && !repeat) api.record(4);
                return true;
            }

            if (!(name in DIRS)) return false;
            if (repeat) return true;

            const dir = DIRS[name];

            if (down) {
                held = held.filter(function (d) { return d !== dir; });
                held.push(dir);
                api.record(dir);
                return true;
            }

            const top = held[held.length - 1] === dir;
            held = held.filter(function (d) { return d !== dir; });
            if (top) api.record(held.length ? held[held.length - 1] : 5);

            return true;
        },
        release: function () {
            if (!held.length) return;
            held = [];
            api.record(5);
        },
    };
}
