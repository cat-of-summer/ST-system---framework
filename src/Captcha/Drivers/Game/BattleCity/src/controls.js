const DIRS = {
    ArrowUp: 0, w: 0, W: 0, 'ц': 0, 'Ц': 0,
    ArrowRight: 1, d: 1, D: 1, 'в': 1, 'В': 1,
    ArrowDown: 2, s: 2, S: 2, 'ы': 2, 'Ы': 2,
    ArrowLeft: 3, a: 3, A: 3, 'ф': 3, 'Ф': 3,
};

const FIRE = { ' ': 1, Enter: 1 };

/**
 * Стек зажатых стрелок: нажатие кладёт направление наверх и ведёт танк туда; отпускание верхней
 * возвращает предыдущую зажатую, а если зажатых нет — стоп. Отпущенная «не та» клавиша танк не
 * поворачивает и не останавливает. Выстрел — пробел, на телефоне ещё и тап по полю.
 */
export function battleCityControls(api) {
    let held = [];

    return {
        key: function (key, down, repeat) {
            if (key in FIRE) {
                if (down && !repeat) api.record(4);
                return true;
            }

            if (!(key in DIRS)) return false;
            if (repeat) return true;

            const dir = DIRS[key];

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
        swipe: function (dir) { api.record(dir); },
        tap: function () { api.record(4); },
        release: function () {
            if (!held.length) return;
            held = [];
            api.record(5);
        },
    };
}
