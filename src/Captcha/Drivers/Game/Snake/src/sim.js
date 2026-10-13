import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

export const DX = [0, 1, 0, -1];
export const DY = [-1, 0, 1, 0];

/** Шаг змейки — раз в STEP тиков: 24 тика в секунду / 5 = 4,8 клетки в секунду. */
export const STEP = 5;

/** Расстояние по одной оси на поле, свёрнутом в тор размера n. */
export function wrapDist(a, b, n) {
    const d = Math.abs(a - b);
    return Math.min(d, n - d);
}

/**
 * Поле cols × rows (params; сервер считает их из размера холста) без стен: выход за край —
 * с противоположной стороны, смерть только о себя. Коды ввода: 0..3 — направление (вверх,
 * вправо, вниз, влево). Реакция — съеденное яблоко; оно появляется не ближе
 * ⌊(cols + rows) / 4⌋ клеток от головы. Осмысленное действие — поворот, после которого голова
 * ближе к яблоку, чем если ехать прямо.
 */
export class SnakeSim extends Sim {

    get cooldown() { return 24; }

    setup() {
        this.cols = this.params.cols | 0 || 16;
        this.rows = this.params.rows | 0 || 9;
    }

    restart() {
        const x = Math.floor(this.cols / 2), y = Math.floor(this.rows / 2);
        this.body = [[x, y], [x - 1, y], [x - 2, y]];
        this.dir   = 1;
        this.next  = 1;
        this.clock = 0;
        this.place();
    }

    /** Расстояние от головы до клетки по тору. */
    away(x, y) {
        return wrapDist(x, this.body[0][0], this.cols) + wrapDist(y, this.body[0][1], this.rows);
    }

    /** Яблоко — в случайной свободной клетке не ближе ⌊(cols + rows) / 4⌋; нет таких — в самой дальней. */
    place() {
        let far = 0;

        for (let y = 0; y < this.rows; y++)
            for (let x = 0; x < this.cols; x++)
                if (!this.hits(x, y, this.body.length)) far = Math.max(far, this.away(x, y));

        const min = Math.min(far, idiv(this.cols + this.rows, 4));
        let count = 0;

        for (let y = 0; y < this.rows; y++)
            for (let x = 0; x < this.cols; x++)
                if (!this.hits(x, y, this.body.length) && this.away(x, y) >= min) count++;

        let k = this.rng.int(count);

        for (let y = 0; y < this.rows; y++)
            for (let x = 0; x < this.cols; x++) {
                if (this.hits(x, y, this.body.length) || this.away(x, y) < min) continue;
                if (k === 0) { this.ax = x; this.ay = y; return; }
                k--;
            }
    }

    hits(x, y, length) {
        for (let i = 0; i < length; i++)
            if (this.body[i][0] === x && this.body[i][1] === y) return true;
        return false;
    }

    /** Расстояние от клетки рядом с головой (в направлении d) до яблока. */
    reach(d) {
        const x = (this.body[0][0] + DX[d] + this.cols) % this.cols;
        const y = (this.body[0][1] + DY[d] + this.rows) % this.rows;
        return wrapDist(x, this.ax, this.cols) + wrapDist(y, this.ay, this.rows);
    }

    handle(code) {
        if (code < 0 || code > 3 || (code + 2) % 4 === this.dir || code === this.next) return;

        this.intent(this.reach(code) < this.reach(this.next));
        this.next = code;
    }

    update() {
        if (++this.clock % STEP !== 0) return;

        this.dir = this.next;

        const x   = (this.body[0][0] + DX[this.dir] + this.cols) % this.cols;
        const y   = (this.body[0][1] + DY[this.dir] + this.rows) % this.rows;
        const eat = x === this.ax && y === this.ay;

        if (this.hits(x, y, eat ? this.body.length : this.body.length - 1)) {
            this.lose();
            return;
        }

        this.body.unshift([x, y]);

        if (eat) {
            this.score();
            this.place();
        } else {
            this.body.pop();
        }
    }

    dump() {
        const out = [this.cols, this.rows, this.clock, this.dir, this.next, this.ax, this.ay, this.body.length];
        for (const cell of this.body) out.push(cell[0], cell[1]);
        return out;
    }
}
