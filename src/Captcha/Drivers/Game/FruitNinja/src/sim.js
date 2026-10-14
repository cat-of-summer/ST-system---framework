import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

// Мир высотой H единиц; ширина — params.w (сервер считает её из пропорций холста). Фигуры — в
// фиксированной точке ×256.
export const H  = 150;
export const FP = 256;

/** Ускорение свободного падения, FP за тик² (30 тиков в секунду): фигура взлетает за ~1 с. */
export const G = 45;

/** Позиция клинка в коде ввода: value = x · PACK + y, y < PACK. */
export const PACK = 160;

/**
 * Отрезок клинка за тик длиннее MAXSEG — перескок (курсор ушёл и вернулся), он не режет; короче
 * MINSEG — клинок еле движется, тоже не режет. Быстрый взмах мышью на холсте 320 × 200 — до 80
 * единиц за тик.
 */
export const MAXSEG = 120;
export const MINSEG = 3;

/** Упущено столько фигур — смерть. */
export const LIVES = 3;

/** Клинок не двигался столько тиков — взмах окончен. */
export const STILL = 3;

/** Виды фигур: треугольник, квадрат, круг, ромб; цветов — шесть. */
export const KINDS  = 4;
export const COLORS = 6;

/**
 * Fruit Ninja: фигуры залпами вылетают снизу по параболе с ускорением свободного падения, их
 * режут клинком. Коды ввода: 0 — клинок в точке (value = x · PACK + y, единицы мира), 1 — клинок
 * поднят. Отрезок между двумя точками клинка режет фигуру, если задевает её (круг reach() — по
 * нарисованному контуру, в положении на этом тике или на следующем: на экране фигура между ними),
 * не короче MINSEG, не длиннее MAXSEG и продолжает направление прошлого отрезка — расходится с ним
 * не больше чем на 60°. Человек ведёт клинок плавно, а случайные точки скачут во все стороны.
 * Разрезанная фигура — реакция. Упало обратно LIVES фигур — смерть.
 *
 * Взмах — непрерывное движение клинка быстрее MINSEG за тик; кончается, когда клинок замедлился,
 * перескочил, поднят или STILL тиков не двигался. Взмах — действие, если прошёл рядом с фигурой
 * (ближе трёх её кругов) или что-то разрезал; осмыслен, если разрезал. Перевести клинок через
 * пустое поле — не действие.
 *
 * Фигура: [id, x, y, vx, vy, вид, цвет, радиус, вращение, тик появления].
 */
export class FruitNinjaSim extends Sim {

    setup() {
        this.w   = this.params.w | 0 || 267;
        this.ids = 0;
    }

    restart() {
        this.shapes = [];
        this.misses = 0;
        this.next   = 15;
        this.clock  = 0;
        this.down   = 0;
        this.bx     = 0;
        this.by     = 0;
        this.ax     = 0;
        this.ay     = 0;
        this.cuts   = 0;
        this.near   = 0;
        this.pdx    = 0;
        this.pdy    = 0;
        this.still  = 0;
    }

    /** Залп: 1…3 фигуры, чем дольше жизнь, тем чаще по нескольку. */
    wave() {
        const count = 1 + this.rng.int(Math.min(3, 1 + idiv(this.clock, 240)));

        for (let i = 0; i < count; i++) {
            const r  = 10 + this.rng.int(4);
            const x  = idiv(this.w * 15, 100) + this.rng.int(idiv(this.w * 70, 100));
            const vx = (x < idiv(this.w, 2) ? 1 : -1) * this.rng.int(200);
            const vy = -(1220 + this.rng.int(480));
            const kind  = this.rng.int(KINDS);
            const color = this.rng.int(COLORS);
            const spin  = this.rng.int(13) - 6;

            this.shapes.push([this.ids++, x * FP, (H + r) * FP, vx, vy, kind, color, r, spin, this.clock]);
        }

        this.next = 28 + this.rng.int(24) - Math.min(10, idiv(this.clock, 60));
    }

    /** Квадрат расстояния (FP²) от центра фигуры до отрезка (ax, ay)–(bx, by) в единицах мира. */
    static dist2(s, ax, ay, bx, by) {
        const x0 = ax * FP, y0 = ay * FP, dx = (bx - ax) * FP, dy = (by - ay) * FP;
        const len2 = dx * dx + dy * dy;
        let t = (s[1] - x0) * dx + (s[2] - y0) * dy;
        if (t < 0) t = 0;
        if (t > len2) t = len2;

        const px = x0 + (len2 ? idiv(dx * t, len2) : 0) - s[1];
        const py = y0 + (len2 ? idiv(dy * t, len2) : 0) - s[2];
        return px * px + py * py;
    }

    /** Радиус попадания, FP: по нарисованному контуру — углы треугольника и ромба выступают на четверть. */
    static reach(s) {
        return idiv(s[7] * FP * 5, 4);
    }

    /** Квадрат расстояния от отрезка до фигуры — ближайшее из положений на этом тике и на следующем. */
    static near2(s, ax, ay, bx, by) {
        const now  = FruitNinjaSim.dist2(s, ax, ay, bx, by);
        const next = FruitNinjaSim.dist2([s[0], s[1] + s[3], s[2] + s[4] + G], ax, ay, bx, by);
        return Math.min(now, next);
    }

    /** Взмах окончен: был ли он действием и осмысленным. */
    stroke() {
        if (this.near || this.cuts) this.intent(this.cuts > 0);
        this.cuts = this.near = 0;
    }

    /** Направление прошлого отрезка забыто: клинок поднят, перескочил или замер. */
    forget() {
        this.pdx = this.pdy = 0;
    }

    handle(code, value) {
        if (code === 1) {
            this.stroke();
            this.forget();
            this.down = 0;
            return;
        }
        if (code !== 0 || value < 0) return;

        const x = Math.min(this.w, idiv(value, PACK)), y = Math.min(H, value % PACK);

        if (this.down) {
            const dx = x - this.bx, dy = y - this.by, len2 = dx * dx + dy * dy;

            if (len2 > MAXSEG * MAXSEG) {
                this.stroke();
                this.forget();
            } else if (len2 > 0) {
                // Продолжает прошлый отрезок: угол между ними не больше 60° (cos ≥ ½).
                const dot = this.pdx * dx + this.pdy * dy, plen2 = this.pdx * this.pdx + this.pdy * this.pdy;
                const along = plen2 > 0 && dot > 0 && 4 * dot * dot >= plen2 * len2;

                if (len2 < MINSEG * MINSEG) {
                    this.stroke();
                } else {
                    this.ax = this.bx;
                    this.ay = this.by;
                    this.slice(this.bx, this.by, x, y, along);
                }

                this.pdx = dx;
                this.pdy = dy;
            }
        }

        this.bx    = x;
        this.by    = y;
        this.down  = 1;
        this.still = 0;
    }

    /** Фигуры, которые задевает отрезок: режутся, если cut; рядом — отмечается near. */
    slice(ax, ay, bx, by, cut) {
        const keep = [];

        for (const s of this.shapes) {
            const d2 = FruitNinjaSim.near2(s, ax, ay, bx, by), r = FruitNinjaSim.reach(s);

            if (cut && d2 <= r * r) {
                this.cuts++;
                this.score();
                continue;
            }
            if (d2 <= 9 * r * r) this.near = 1;
            keep.push(s);
        }

        this.shapes = keep;
    }

    update() {
        this.clock++;
        if (this.down && ++this.still === STILL) {
            this.stroke();
            this.forget();
        }
        if (--this.next <= 0) this.wave();

        const keep = [];
        for (const s of this.shapes) {
            s[4] += G;
            s[1] += s[3];
            s[2] += s[4];

            if (s[4] > 0 && s[2] > (H + s[7]) * FP) {
                this.misses++;
                continue;
            }
            keep.push(s);
        }
        this.shapes = keep;

        if (this.misses >= LIVES) this.lose();
    }

    dump() {
        const out = [this.w, this.ids, this.clock, this.next, this.misses, this.down, this.bx, this.by, this.ax, this.ay,
            this.cuts, this.near, this.pdx, this.pdy, this.still, this.shapes.length];
        for (const s of this.shapes) for (const v of s) out.push(v);
        return out;
    }
}
