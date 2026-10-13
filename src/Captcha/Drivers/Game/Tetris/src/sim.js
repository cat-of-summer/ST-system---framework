import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

export const COLS = 8;
export const ROWS = 14;

const GRAV  = 10;
const KICKS = [0, -1, 1, -2, 2];

// [размер рамки поворота, клетки в положении 0]: I O T S Z J L
const BASE = [
    [4, [[0, 1], [1, 1], [2, 1], [3, 1]]],
    [2, [[0, 0], [1, 0], [0, 1], [1, 1]]],
    [3, [[1, 0], [0, 1], [1, 1], [2, 1]]],
    [3, [[1, 0], [2, 0], [0, 1], [1, 1]]],
    [3, [[0, 0], [1, 0], [1, 1], [2, 1]]],
    [3, [[0, 0], [0, 1], [1, 1], [2, 1]]],
    [3, [[2, 0], [0, 1], [1, 1], [2, 1]]],
];

/** SHAPES[тип][поворот] — клетки; поворот по часовой: (x, y) → (n-1-y, x). */
export const SHAPES = BASE.map(function (piece) {
    const n = piece[0];
    const rots = [piece[1]];
    for (let r = 1; r < 4; r++)
        rots.push(rots[r - 1].map(function (c) { return [n - 1 - c[1], c[0]]; }));
    return rots;
});

export const GARBAGE = 8;

/**
 * Поле 8×14, внизу ряд мусора с дыркой в 3 клетки. Коды ввода: 0 — поворот, 1 — вправо,
 * 2 — вниз на клетку, 3 — влево; мгновенного сброса нет. Реакция — убранная линия.
 *
 * Осмысленное действие — сдвиг, после которого фигура ближе к одному из лучших мест для неё при
 * её нынешнем повороте (места считает plan() при появлении фигуры: линии, дыры, высота — с
 * допуском 2 очка). Поворот не оценивается: по часовой он почти всегда «ближе» к какому-то месту.
 */
export class TetrisSim extends Sim {

    restart() {
        this.grid = [];
        for (let i = 0; i < COLS * ROWS; i++) this.grid.push(0);

        const gap = this.rng.int(COLS - 2);
        for (let x = 0; x < COLS; x++)
            if (x < gap || x >= gap + 3) this.grid[(ROWS - 1) * COLS + x] = GARBAGE;

        this.next = this.rng.int(7);
        this.spawn();
    }

    spawn() {
        this.type  = this.next;
        this.next  = this.rng.int(7);
        this.rot   = 0;
        this.x     = idiv(COLS - BASE[this.type][0], 2);
        this.y     = 0;
        this.clock = 0;
        this.moved = 0;

        if (this.collide(this.x, this.y, this.rot)) this.lose();
        else this.plan();
    }

    /** Лучшие места для новой фигуры: [поворот, x] с оценкой не ниже лучшей минус 2. */
    plan() {
        const all = [];
        let best = -1e9;

        for (let rot = 0; rot < 4; rot++)
            for (let x = -2; x < COLS; x++) {
                if (this.collide(x, 0, rot)) continue;

                let y = 0;
                while (!this.collide(x, y + 1, rot)) y++;

                const score = this.rate(rot, x, y);
                all.push([rot, x, score]);
                if (score > best) best = score;
            }

        this.goals = [];
        for (const p of all) if (p[2] >= best - 2) this.goals.push([p[0], p[1]]);
    }

    /** Оценка места: 20 за линию, −6 за дыру, −1 за клетку высоты. */
    rate(rot, x, y) {
        const grid = this.grid.slice();
        for (const c of SHAPES[this.type][rot]) grid[(y + c[1]) * COLS + x + c[0]] = 1;

        let lines = 0, holes = 0, height = 0;

        for (let r = 0; r < ROWS; r++) {
            let full = true;
            for (let cx = 0; cx < COLS; cx++) if (!grid[r * COLS + cx]) { full = false; break; }
            if (full) lines++;
        }

        for (let cx = 0; cx < COLS; cx++) {
            let seen = false;
            for (let r = 0; r < ROWS; r++) {
                if (grid[r * COLS + cx]) {
                    if (!seen && ROWS - r > height) height = ROWS - r;
                    seen = true;
                } else if (seen) {
                    holes++;
                }
            }
        }

        return lines * 20 - holes * 6 - height;
    }

    /** Сколько сдвигов до ближайшего лучшего места при нынешнем повороте (нет такого — при любом). */
    away() {
        let same = 99, any = 99;

        for (const g of this.goals) {
            const d = Math.abs(g[1] - this.x);
            if (g[0] === this.rot && d < same) same = d;
            if (d < any) any = d;
        }

        return same < 99 ? same : any;
    }

    collide(x, y, rot) {
        for (const c of SHAPES[this.type][rot]) {
            const cx = x + c[0];
            const cy = y + c[1];
            if (cx < 0 || cx >= COLS || cy >= ROWS) return true;
            if (cy >= 0 && this.grid[cy * COLS + cx]) return true;
        }
        return false;
    }

    handle(code) {
        if (code < 0 || code > 3) return;

        this.moved = 1;

        if (code === 0) {
            const rot = (this.rot + 1) % 4;
            for (const k of KICKS)
                if (!this.collide(this.x + k, this.y, rot)) {
                    this.x  += k;
                    this.rot = rot;
                    break;
                }
        } else if (code === 1 || code === 3) {
            const before = this.away();
            const dx     = code === 1 ? 1 : -1;
            if (!this.collide(this.x + dx, this.y, this.rot)) this.x += dx;

            // Осмысленно — сдвинуть фигуру ближе к одному из лучших мест; между равноценными местами
            // и в стену — не в счёт.
            const after = this.away();
            if (after !== before) this.intent(after < before);
        } else if (code === 2) {
            if (!this.collide(this.x, this.y + 1, this.rot)) {
                this.y++;
                this.clock = 0;
            }
        }
    }

    update() {
        if (++this.clock < GRAV) return;
        this.clock = 0;

        if (!this.collide(this.x, this.y + 1, this.rot)) this.y++;
        else this.lock();
    }

    lock() {
        for (const c of SHAPES[this.type][this.rot])
            this.grid[(this.y + c[1]) * COLS + this.x + c[0]] = this.type + 1;

        for (let row = ROWS - 1; row >= 0;) {
            let full = true;
            for (let x = 0; x < COLS; x++)
                if (!this.grid[row * COLS + x]) { full = false; break; }

            if (!full) { row--; continue; }

            for (let r = row; r > 0; r--)
                for (let x = 0; x < COLS; x++) this.grid[r * COLS + x] = this.grid[(r - 1) * COLS + x];
            for (let x = 0; x < COLS; x++) this.grid[x] = 0;

            this.score();
        }

        this.spawn();
    }

    dump() {
        return [this.type, this.rot, this.x, this.y, this.next, this.clock, this.moved].concat(this.grid);
    }
}
