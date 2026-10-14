import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

// Мир высотой H единиц; ширина — params.w (сервер считает её из пропорций холста). Всё в целых
// единицах мира.
export const H      = 200;
export const SHIP_Y = H - 20;
export const SW     = 22;
export const SH     = 12;
export const EW     = 14;
export const EH     = 10;
export const SX     = 22;
export const SY     = 18;
export const TOP    = 18;

const PSPEED = 5;
const SHOT   = 9;
const BOMB   = 4;
const STEP   = 4;
const DROP   = 8;
const BOMBS  = 3;
const CALM   = 60;
const NEAR   = 60;

/**
 * Корабль внизу, противники волнами сверху: строй идёт вбок, у края спускается на шаг; каждая
 * волна больше и быстрее, изредка нижний в колонке бросает бомбу (первые 2 с жизни — нет).
 * Коды ввода: 0 — куда вести корабль (value — x центра), 1 — выстрел (одна своя пуля в полёте).
 * Реакция — сбитый противник. Смерть — бомба в корабль или строй дошёл до корабля.
 *
 * Осмысленно: выстрел, когда над кораблём есть противник; новая цель корабля ближе к ближайшей
 * колонке противников. Рядом с бомбой манёвры не оцениваются — уклонение тоже игра.
 */
export class InvadersSim extends Sim {

    setup() {
        this.w = this.params.w | 0 || 356;
    }

    restart() {
        this.px     = idiv(this.w, 2);
        this.target = this.px;
        this.pbx    = 0;
        this.pby    = -1;
        this.bombs  = [];
        this.wave   = 0;
        this.clock  = 0;
        this.formation();
    }

    /** Новая волна: колонок и рядов больше с каждой волной, строй по центру сверху. */
    formation() {
        const most = Math.max(3, idiv(this.w - 40, SX));
        this.cols  = Math.min(most, 4 + this.wave);
        this.rows  = Math.min(4, 2 + idiv(this.wave, 2));
        this.alive = [];
        for (let i = 0; i < this.cols * this.rows; i++) this.alive.push(1);
        this.fx   = idiv(this.w - (this.cols - 1) * SX - EW, 2);
        this.fy   = TOP;
        this.fdir = 1;
    }

    period() {
        let left = 0;
        for (const a of this.alive) left += a;
        return Math.max(3, 16 - this.wave * 2 - idiv((this.alive.length - left) * 8, this.alive.length));
    }

    /** Есть ли живой противник в колонке c. */
    column(c) {
        for (let r = this.rows - 1; r >= 0; r--) if (this.alive[r * this.cols + c]) return r;
        return -1;
    }

    /** x центра ближайшей к кораблю колонки с живыми противниками или −1. */
    nearest() {
        let best = -1, dist = 1e9;

        for (let c = 0; c < this.cols; c++) {
            if (this.column(c) < 0) continue;
            const cx = this.fx + c * SX + idiv(EW, 2);
            const d  = Math.abs(cx - this.px);
            if (d < dist) { dist = d; best = cx; }
        }

        return best;
    }

    /** Противник над кораблём: выстрел пройдёт через его колонку. */
    aimed() {
        for (let c = 0; c < this.cols; c++) {
            if (this.column(c) < 0) continue;
            const x0 = this.fx + c * SX;
            if (this.px + 2 > x0 && this.px - 2 < x0 + EW) return true;
        }
        return false;
    }

    threatened() {
        for (const b of this.bombs)
            if (Math.abs(b[0] - this.px) < 16 && SHIP_Y - b[1] < NEAR) return true;
        return false;
    }

    handle(code, value) {
        if (code === 0) {
            const target = Math.max(idiv(SW, 2), Math.min(this.w - idiv(SW, 2), value));

            if (target !== this.target && !this.threatened()) {
                const nx = this.nearest();
                if (nx >= 0) {
                    const before = Math.abs(this.target - nx), after = Math.abs(target - nx);
                    if (before !== after) this.intent(after < before);
                }
            }

            this.target = target;
        } else if (code === 1 && this.pby < 0) {
            this.intent(this.aimed());
            this.pbx = this.px;
            this.pby = SHIP_Y - 2;
        }
    }

    update() {
        this.clock++;

        const d = this.target - this.px;
        this.px += d > PSPEED ? PSPEED : (d < -PSPEED ? -PSPEED : d);

        if (this.pby >= 0) {
            this.pby -= SHOT;

            for (let i = 0; i < this.alive.length; i++) {
                if (!this.alive[i]) continue;
                const x0 = this.fx + (i % this.cols) * SX, y0 = this.fy + idiv(i, this.cols) * SY;
                if (this.pbx >= x0 && this.pbx < x0 + EW && this.pby < y0 + EH && this.pby + SHOT > y0) {
                    this.alive[i] = 0;
                    this.pby = -1;
                    this.score();
                    break;
                }
            }

            if (this.pby < 0) this.pby = -1;
        }

        if (this.clock % this.period() === 0) this.march();

        const kept = [];
        for (const b of this.bombs) {
            b[1] += BOMB;
            if (b[1] > H) continue;
            if (b[0] >= this.px - idiv(SW, 2) && b[0] < this.px + idiv(SW, 2) && b[1] >= SHIP_Y && b[1] < SHIP_Y + SH) {
                this.bombs = [];
                this.lose();
                return;
            }
            kept.push(b);
        }
        this.bombs = kept;

        if (this.clock > CALM && this.bombs.length < BOMBS && this.rng.int(Math.max(10, 24 - this.wave * 3)) === 0) this.bomb();

        for (let i = 0; i < this.alive.length; i++)
            if (this.alive[i] && this.fy + idiv(i, this.cols) * SY + EH >= SHIP_Y) { this.lose(); return; }

        if (this.alive.indexOf(1) < 0) {
            this.wave++;
            this.formation();
        }
    }

    /** Строй идёт вбок, у края спускается на шаг и разворачивается. */
    march() {
        let lo = this.cols, hi = -1;
        for (let c = 0; c < this.cols; c++)
            if (this.column(c) >= 0) { if (c < lo) lo = c; if (c > hi) hi = c; }
        if (hi < 0) return;

        const left = this.fx + lo * SX, right = this.fx + hi * SX + EW;

        if ((this.fdir > 0 && right + STEP > this.w - 4) || (this.fdir < 0 && left - STEP < 4)) {
            this.fy  += DROP;
            this.fdir = -this.fdir;
        } else {
            this.fx += this.fdir * STEP;
        }
    }

    /** Бомба от нижнего противника случайной колонки с живыми. */
    bomb() {
        let count = 0;
        for (let c = 0; c < this.cols; c++) if (this.column(c) >= 0) count++;
        if (!count) return;

        let k = this.rng.int(count);
        for (let c = 0; c < this.cols; c++) {
            const r = this.column(c);
            if (r < 0) continue;
            if (k-- === 0) {
                this.bombs.push([this.fx + c * SX + idiv(EW, 2), this.fy + r * SY + EH]);
                return;
            }
        }
    }

    dump() {
        const out = [this.w, this.px, this.target, this.pbx, this.pby, this.wave, this.cols, this.rows,
            this.fx, this.fy, this.fdir, this.clock, this.bombs.length];
        for (const b of this.bombs) out.push(b[0], b[1]);
        return out.concat(this.alive);
    }
}
