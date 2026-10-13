import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

export const DX = [0, 1, 0, -1];
export const DY = [-1, 0, 1, 0];

const DANGER = 3;

/**
 * Лабиринт cols × rows (оба нечётные, из params) строится из зерна: комнаты на нечётных клетках,
 * проходы между ними — обход в глубину, потом тупики пробиваются насквозь и добавляется
 * несколько петель, чтобы от призраков было куда уйти. Пакман стартует внизу по центру,
 * два призрака — в верхних углах. Коды ввода: 0..3 — куда свернуть при первой возможности.
 * Реакция — съеденная вишня; новая появляется не ближе ⌊(cols + rows) / 3⌋ клеток от Пакмана.
 * Осмысленное действие — поворот туда, где путь до вишни по лабиринту короче.
 */
export class PacManSim extends Sim {

    get cooldown() { return 10; }

    setup() {
        this.cols = this.params.cols | 0 || 19;
        this.rows = this.params.rows | 0 || 11;
        this.maze();

        this.sx     = idiv(this.cols, 2) | 1;
        this.sy     = this.rows - 2;
        this.starts = [[1, 1], [this.cols - 2, 1]];

        this.map = this.walls.map(function (w) { return w ? 0 : 1; });
        this.map[this.sy * this.cols + this.sx] = 0;
        for (const s of this.starts) this.map[s[1] * this.cols + s[0]] = 0;

        this.refill();
        this.px = this.sx;
        this.py = this.sy;
        this.place();
    }

    maze() {
        const cols = this.cols, rc = idiv(this.cols - 1, 2), rr = idiv(this.rows - 1, 2);
        const walls = this.walls = [];
        const seen  = [];

        for (let i = 0; i < this.cols * this.rows; i++) walls.push(1);
        for (let i = 0; i < rc * rr; i++) seen.push(0);

        // Проём между комнатой r и её соседом по направлению d.
        function dig(r, d) {
            const x = r % rc, y = (r - x) / rc;
            walls[(2 * y + 1 + DY[d]) * cols + 2 * x + 1 + DX[d]] = 0;
        }

        function next(r, d) {
            const x = r % rc + DX[d], y = (r - r % rc) / rc + DY[d];
            return x >= 0 && y >= 0 && x < rc && y < rr ? y * rc + x : -1;
        }

        for (let r = 0; r < rc * rr; r++) {
            const x = r % rc, y = (r - x) / rc;
            walls[(2 * y + 1) * cols + 2 * x + 1] = 0;
        }

        const stack = [0];
        seen[0] = 1;

        while (stack.length) {
            const r = stack[stack.length - 1];
            const options = [];

            for (let d = 0; d < 4; d++) {
                const n = next(r, d);
                if (n >= 0 && !seen[n]) options.push(d);
            }

            if (!options.length) { stack.pop(); continue; }

            const d = options[this.rng.int(options.length)];
            const n = next(r, d);
            dig(r, d);
            seen[n] = 1;
            stack.push(n);
        }

        for (let r = 0; r < rc * rr; r++) {
            const x = r % rc, y = (r - x) / rc;
            const options = [];
            let exits = 0;

            for (let d = 0; d < 4; d++) {
                if (next(r, d) < 0) continue;
                if (walls[(2 * y + 1 + DY[d]) * cols + 2 * x + 1 + DX[d]]) options.push(d);
                else exits++;
            }

            if (exits === 1 && options.length) dig(r, options[this.rng.int(options.length)]);
        }

        for (let k = idiv(rc * rr, 6); k > 0; k--) {
            const r = this.rng.int(rc * rr);
            const d = this.rng.int(4);
            if (next(r, d) >= 0) dig(r, d);
        }
    }

    refill() {
        this.dots = this.map.slice();
        this.left = 0;
        for (const dot of this.dots) this.left += dot;
    }

    restart() {
        this.px     = this.sx;
        this.py     = this.sy;
        this.dir    = -1;
        this.want   = -1;
        this.clock  = 0;
        this.ghosts = this.starts.map(function (s) { return [s[0], s[1], -1]; });
    }

    open(x, y) {
        return x >= 0 && y >= 0 && x < this.cols && y < this.rows && !this.walls[y * this.cols + x];
    }

    /** Вишня — в случайной клетке не ближе ⌊(cols + rows) / 3⌋ от Пакмана; нет таких — в самой дальней. */
    place() {
        let far = 0;

        for (let i = 0; i < this.walls.length; i++)
            if (!this.walls[i]) far = Math.max(far, this.away(i));

        const min = Math.min(far, idiv(this.cols + this.rows, 3));
        let count = 0;

        for (let i = 0; i < this.walls.length; i++)
            if (!this.walls[i] && this.away(i) >= min) count++;

        let k = this.rng.int(count);

        for (let i = 0; i < this.walls.length; i++) {
            if (this.walls[i] || this.away(i) < min) continue;
            if (k === 0) { this.fx = i % this.cols; this.fy = (i - this.fx) / this.cols; return; }
            k--;
        }
    }

    away(i) {
        const x = i % this.cols, y = (i - x) / this.cols;
        return Math.abs(x - this.px) + Math.abs(y - this.py);
    }

    /** Длина кратчайшего пути от каждой клетки до вишни (обход в ширину); стены и недостижимые — −1. */
    distances() {
        const cols  = this.cols;
        const dist  = [];
        const start = this.fy * cols + this.fx;
        const queue = [start];

        for (let i = 0; i < this.walls.length; i++) dist.push(-1);
        dist[start] = 0;

        for (let q = 0; q < queue.length; q++) {
            const i = queue[q], x = i % cols, y = (i - x) / cols;

            for (let d = 0; d < 4; d++) {
                const nx = x + DX[d], ny = y + DY[d], k = ny * cols + nx;
                if (!this.open(nx, ny) || dist[k] >= 0) continue;
                dist[k] = dist[i] + 1;
                queue.push(k);
            }
        }

        return dist;
    }

    /** Клетка, где можно свернуть в code: та, где Пакман сейчас, или первая такая по ходу; −1 — нигде. */
    turnAt(code) {
        let x = this.px, y = this.py;

        for (let step = 0; step <= this.cols + this.rows; step++) {
            if (this.open(x + DX[code], y + DY[code])) return y * this.cols + x;
            if (this.dir < 0 || !this.open(x + DX[this.dir], y + DY[this.dir])) return -1;
            x += DX[this.dir];
            y += DY[this.dir];
        }

        return -1;
    }

    /** Призрак ближе DANGER клеток по прямой. */
    threatened() {
        for (const g of this.ghosts)
            if (Math.abs(g[0] - this.px) + Math.abs(g[1] - this.py) <= DANGER) return true;
        return false;
    }

    handle(code) {
        if (code < 0 || code > 3 || code === this.want) return;

        // Осмысленно — свернуть туда, где путь до вишни короче: сразу или на ближайшей развилке по ходу.
        // Рядом с призраком повороты не оцениваются: уходить от него — тоже игра.
        const at = this.turnAt(code);

        if (this.threatened()) {
            // не в счёт
        } else if (at < 0) {
            this.intent(false);
        } else {
            const dist = this.distances(), x = at % this.cols, y = (at - x) / this.cols;
            this.intent(dist[(y + DY[code]) * this.cols + x + DX[code]] < dist[at]);
        }

        this.want = code;
    }

    update() {
        this.clock++;

        const ox = this.px;
        const oy = this.py;

        if (this.clock % 2 === 0) {
            if (this.want >= 0 && this.open(this.px + DX[this.want], this.py + DY[this.want])) this.dir = this.want;

            if (this.dir >= 0 && this.open(this.px + DX[this.dir], this.py + DY[this.dir])) {
                this.px += DX[this.dir];
                this.py += DY[this.dir];
            }

            const i = this.py * this.cols + this.px;

            if (this.dots[i]) {
                this.dots[i] = 0;
                if (--this.left === 0) this.refill();
            }

            if (this.px === this.fx && this.py === this.fy) {
                this.score();
                this.place();
            }

            for (const g of this.ghosts)
                if (g[0] === this.px && g[1] === this.py) { this.lose(); return; }
        }

        if (this.clock % 3 === 0)
            for (const g of this.ghosts) {
                const gx = g[0], gy = g[1];

                this.chase(g);

                if ((g[0] === this.px && g[1] === this.py)
                    || (g[0] === ox && g[1] === oy && gx === this.px && gy === this.py)) {
                    this.lose();
                    return;
                }
            }
    }

    chase(g) {
        const options = [];

        for (let d = 0; d < 4; d++) {
            if (g[2] >= 0 && d === (g[2] + 2) % 4) continue;
            if (this.open(g[0] + DX[d], g[1] + DY[d])) options.push(d);
        }

        if (!options.length) {
            if (g[2] < 0) return;
            const back = (g[2] + 2) % 4;
            if (!this.open(g[0] + DX[back], g[1] + DY[back])) return;
            options.push(back);
        }

        let d = options[0];

        if (this.rng.int(3) === 0) {
            let best = 1e9;
            for (const o of options) {
                const dist = Math.abs(g[0] + DX[o] - this.px) + Math.abs(g[1] + DY[o] - this.py);
                if (dist < best) { best = dist; d = o; }
            }
        } else {
            d = options[this.rng.int(options.length)];
        }

        g[0] += DX[d];
        g[1] += DY[d];
        g[2]  = d;
    }

    dump() {
        const out = [this.cols, this.rows, this.px, this.py, this.dir, this.want, this.fx, this.fy, this.clock, this.left];
        for (const g of this.ghosts) out.push(g[0], g[1], g[2]);
        return out.concat(this.dots);
    }
}
