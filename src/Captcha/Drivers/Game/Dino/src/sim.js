import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

// Мир высотой H единиц; ширина — params.w (сервер считает её из пропорций холста). Высота
// динозавра и скорость — в фиксированной точке ×256.
export const H      = 150;
export const FP     = 256;
export const GROUND = H - 20;
export const DX     = 30;
export const DW     = 18;
export const DH     = 20;

const GRAVITY = 128;
const JUMP    = 1920;
const SPEED0  = 1024;
const ACCEL   = 64;
const EVERY   = 60;
const MAXSPD  = 2048;
const AIR     = 32;

/**
 * Препятствия: [вид, ширина, высота]; 0 — кактус (стоит на земле), 1 — птица (низко над землёй).
 * Кактус шириной от 24 — группа стволов по 12 единиц, каждый второй ниже на 4.
 */
export const KINDS = [[0, 12, 24], [0, 24, 28], [0, 12, 32], [0, 10, 20], [1, 18, 12]];
export const BIRD_LIFT = 6;

/**
 * Хитбокс динозавра — части спрайта, а не весь его прямоугольник: голова, туловище, ноги
 * [x0, y0, x1, y1] от левого верхнего угла спрайта. Пустые углы (над хвостом, под мордой) и
 * кончик хвоста не задевают.
 */
export const PARTS = [[10, 1, 17, 6], [2, 7, 13, 15], [4, 15, 10, 20]];

/**
 * Динозавр бежит, карта ускоряется, препятствия — от seed с промежутками, которые на текущей
 * скорости всегда можно перепрыгнуть. Код ввода 0 — прыжок (только с земли). Реакция — пройденное
 * препятствие. Осмысленный прыжок — тот, что перелетает ближайшее препятствие: смоделированный
 * прыжок с ним не сталкивается и заканчивается уже за ним.
 *
 * Препятствие: [x ×256 — левый край, вид, пройдено].
 */
export class DinoSim extends Sim {

    setup() {
        this.w = this.params.w | 0 || 267;
    }

    restart() {
        this.y     = GROUND * FP;
        this.vy    = 0;
        this.speed = SPEED0;
        this.clock = 0;
        this.obs   = [];
        this.dist  = idiv(this.w * FP, 2);
    }

    /** Промежуток до следующего препятствия: прыжок на текущей скорости с запасом плюс разброс. */
    gap() {
        return this.speed * (AIR + 20) + (60 + this.rng.int(120)) * FP;
    }

    spawn() {
        const kind = this.rng.int(this.clock > EVERY * 3 ? KINDS.length : KINDS.length - 1);
        this.obs.push([this.w * FP + 8 * FP, kind, 0]);
        this.dist = this.gap();
    }

    /** Коробка препятствия в единицах мира: [x0, y0, x1, y1]. */
    static box(o) {
        const k  = KINDS[o[1]];
        const x0 = idiv(o[0], FP);
        const y1 = k[0] === 1 ? GROUND - BIRD_LIFT : GROUND;
        return [x0, y1 - k[2], x0 + k[1], y1];
    }

    /**
     * Хитбоксы препятствия: у каждого ствола кактуса — ствол и пояс рук, у птицы — туловище без
     * крыльев. Рисунок (src/index.js) строится по тем же размерам.
     */
    static boxes(o) {
        const b = DinoSim.box(o), k = KINDS[o[1]];
        if (k[0] === 1) return [[b[0] + 1, b[3] - 8, b[2] - 3, b[3] - 2]];

        const out = [], n = Math.max(1, idiv(k[1], 12)), sw = idiv(k[1], n);
        for (let i = 0; i < n; i++) {
            const x = b[0] + i * sw, sh = k[2] - (i % 2) * 4, top = b[3] - sh, tx = x + idiv(sw - 4, 2);
            out.push([tx, top, tx + 4, b[3]]);
            out.push([x + 1, top + idiv(sh * 3, 10) + 2, x + sw - 1, top + idiv(sh * 6, 10)]);
        }
        return out;
    }

    hits(y, o) {
        const top = idiv(y, FP) - DH;
        for (const b of DinoSim.boxes(o))
            for (const p of PARTS)
                if (DX + p[2] > b[0] && DX + p[0] < b[2] && top + p[3] > b[1] && top + p[1] < b[3]) return true;
        return false;
    }

    /** Ближайшее препятствие, ещё не оставшееся позади. */
    ahead() {
        for (const o of this.obs) if (DinoSim.box(o)[2] > DX) return o;
        return null;
    }

    /** Осмысленный прыжок: смоделировать его на текущей скорости — перелетает ли ближайшее препятствие. */
    clears() {
        const o = this.ahead();
        if (!o) return false;

        let y = this.y, vy = -JUMP, x = o[0];
        const probe = [x, o[1], 0];

        for (let t = 0; t < AIR + 4; t++) {
            vy += GRAVITY;
            y  += vy;
            x  -= this.speed;
            probe[0] = x;

            if (y >= GROUND * FP) return DinoSim.box(probe)[2] <= DX;
            if (this.hits(y, probe)) return false;
        }

        return DinoSim.box(probe)[2] <= DX;
    }

    handle(code) {
        if (code !== 0 || this.y < GROUND * FP) return;

        this.intent(this.clears());
        this.vy = -JUMP;
    }

    update() {
        if (++this.clock % EVERY === 0 && this.speed < MAXSPD) this.speed += ACCEL;

        if (this.y < GROUND * FP || this.vy < 0) {
            this.vy += GRAVITY;
            this.y  += this.vy;
            if (this.y >= GROUND * FP) { this.y = GROUND * FP; this.vy = 0; }
        }

        this.dist -= this.speed;
        if (this.dist <= 0) this.spawn();

        for (const o of this.obs) {
            o[0] -= this.speed;

            if (!o[2] && DinoSim.box(o)[2] <= DX) {
                o[2] = 1;
                this.score();
            }
        }

        while (this.obs.length && DinoSim.box(this.obs[0])[2] < -10) this.obs.shift();

        for (const o of this.obs)
            if (this.hits(this.y, o)) { this.lose(); return; }
    }

    dump() {
        const out = [this.w, this.y, this.vy, this.speed, this.clock, this.dist, this.obs.length];
        for (const o of this.obs) out.push(o[0], o[1], o[2]);
        return out;
    }
}
