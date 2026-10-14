import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

/** Пауза после открытого поля перед новым, в тиках. */
export const WIN_PAUSE = 15;

/**
 * Сапёр: поле cols × rows, mines мин. Мины раскладываются от rng при первом открытии и обходят
 * клетку 3×3 вокруг первого клика; клетка без соседних мин открывается заливкой.
 *
 * Коды ввода: 0 — открыть клетку (value — номер клетки), 1 — флажок поставить или снять,
 * 2 — открыть соседей числа, вокруг которого стоит столько же флажков.
 *
 * Реакция — безопасный ход, открывший клетки, кроме первого клика (он открывает наугад). Мина —
 * смерть, новая жизнь — новое поле. Открыто всё поле — cleared + 1, через паузу новое поле.
 * Осмысленно: первый клик; открыть безопасную клетку рядом с открытыми; флажок на мину рядом с
 * открытыми; открыть соседей числа без ошибки.
 */
export class MinesweeperSim extends Sim {

    get cooldown() { return 25; }

    setup() {
        this.cols    = this.params.cols | 0 || 9;
        this.rows    = this.params.rows | 0 || 6;
        this.n       = this.cols * this.rows;
        this.count   = Math.min(this.params.mines | 0 || 8, this.n - 9);
        this.cleared = 0;
    }

    restart() {
        this.board();
    }

    board() {
        this.mine   = [];
        this.open   = [];
        this.flag   = [];
        for (let i = 0; i < this.n; i++) {
            this.mine.push(0);
            this.open.push(0);
            this.flag.push(0);
        }
        this.placed = 0;
        this.opened = 0;
        this.boom   = -1;
        this.won    = 0;
    }

    /** Соседи клетки c (до восьми), по строкам сверху вниз. */
    around(c) {
        const out = [], r = idiv(c, this.cols), k = c % this.cols;
        for (let dr = -1; dr <= 1; dr++)
            for (let dk = -1; dk <= 1; dk++) {
                if (dr === 0 && dk === 0) continue;
                const rr = r + dr, kk = k + dk;
                if (rr >= 0 && rr < this.rows && kk >= 0 && kk < this.cols) out.push(rr * this.cols + kk);
            }
        return out;
    }

    /** Сколько мин вокруг клетки. */
    number(c) {
        let m = 0;
        for (const a of this.around(c)) m += this.mine[a];
        return m;
    }

    /** Рядом есть открытая клетка — ход опирается на то, что видно. */
    touches(c) {
        for (const a of this.around(c)) if (this.open[a]) return true;
        return false;
    }

    /** Мины — частичный Фишер–Йетс по клеткам вне 3×3 вокруг первого клика. */
    place(first) {
        const fr = idiv(first, this.cols), fk = first % this.cols, list = [];

        for (let c = 0; c < this.n; c++) {
            const r = idiv(c, this.cols), k = c % this.cols;
            if (Math.abs(r - fr) > 1 || Math.abs(k - fk) > 1) list.push(c);
        }

        for (let i = 0; i < this.count; i++) {
            const j = i + this.rng.int(list.length - i);
            const t = list[i]; list[i] = list[j]; list[j] = t;
            this.mine[list[i]] = 1;
        }

        this.placed = 1;
    }

    handle(code, value) {
        if (this.won || value < 0 || value >= this.n) return;

        if (code === 0) this.reveal(value);
        else if (code === 1) this.toggle(value);
        else if (code === 2) this.chord(value);
    }

    reveal(c) {
        if (this.open[c] || this.flag[c]) return;

        if (!this.placed) {
            this.place(c);
            this.intent(true);
            this.flood(c);
            return;
        }

        this.intent(!this.mine[c] && this.touches(c));

        if (this.mine[c]) { this.explode(c); return; }

        this.flood(c);
        this.score();
        this.check();
    }

    toggle(c) {
        if (this.open[c] || !this.placed) return;

        this.flag[c] = this.flag[c] ? 0 : 1;
        if (this.flag[c]) this.intent(this.mine[c] === 1 && this.touches(c));
    }

    chord(c) {
        if (!this.open[c]) return;

        const near = this.around(c);
        let flags = 0, hidden = 0;
        for (const a of near) {
            flags += this.flag[a];
            if (!this.open[a] && !this.flag[a]) hidden++;
        }
        if (hidden === 0 || flags !== this.number(c)) return;

        for (const a of near)
            if (!this.open[a] && !this.flag[a] && this.mine[a]) {
                this.intent(false);
                this.explode(a);
                return;
            }

        this.intent(true);
        for (const a of near) if (!this.open[a] && !this.flag[a]) this.flood(a);
        this.score();
        this.check();
    }

    explode(c) {
        this.boom = c;
        this.lose();
    }

    /** Открыть клетку и, если вокруг нет мин, всех соседей — стеком, без рекурсии. */
    flood(c) {
        const stack = [c];

        while (stack.length) {
            const x = stack.pop();
            if (this.open[x] || this.flag[x]) continue;

            this.open[x] = 1;
            this.opened++;

            if (this.number(x) === 0)
                for (const a of this.around(x)) if (!this.open[a] && !this.flag[a]) stack.push(a);
        }
    }

    check() {
        if (this.opened === this.n - this.count) {
            this.won = WIN_PAUSE;
            this.cleared++;
        }
    }

    update() {
        if (this.won && --this.won === 0) this.board();
    }

    dump() {
        return [this.placed, this.opened, this.boom, this.won, this.cleared].concat(this.mine, this.open, this.flag);
    }
}
