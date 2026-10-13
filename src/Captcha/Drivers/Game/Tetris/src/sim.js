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
 * 2 — вниз на клетку, 3 — влево, 4 — сбросить. Реакция — убранная линия.
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
    }

    /** Пустые клетки, над которыми в том же столбце есть занятые. */
    holes() {
        let holes = 0;

        for (let x = 0; x < COLS; x++) {
            let covered = false;
            for (let y = 0; y < ROWS; y++) {
                if (this.grid[y * COLS + x]) covered = true;
                else if (covered) holes++;
            }
        }

        return holes;
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
        if (code < 0 || code > 4) return;

        this.moved = 1;

        if (code === 0) {
            const rot = (this.rot + 1) % 4;
            for (const k of KICKS)
                if (!this.collide(this.x + k, this.y, rot)) {
                    this.x  += k;
                    this.rot = rot;
                    return;
                }
        } else if (code === 1 || code === 3) {
            const dx = code === 1 ? 1 : -1;
            if (!this.collide(this.x + dx, this.y, this.rot)) this.x += dx;
        } else if (code === 2) {
            if (!this.collide(this.x, this.y + 1, this.rot)) {
                this.y++;
                this.clock = 0;
            }
        } else if (code === 4) {
            while (!this.collide(this.x, this.y + 1, this.rot)) this.y++;
            this.lock();
        }
    }

    update() {
        if (++this.clock < GRAV) return;
        this.clock = 0;

        if (!this.collide(this.x, this.y + 1, this.rot)) this.y++;
        else this.lock();
    }

    lock() {
        const before = this.holes();

        for (const c of SHAPES[this.type][this.rot])
            this.grid[(this.y + c[1]) * COLS + this.x + c[0]] = this.type + 1;

        // Осмысленно — фигуру, которую двигали, положить без новых дыр.
        if (this.moved) this.intent(this.holes() <= before);

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
