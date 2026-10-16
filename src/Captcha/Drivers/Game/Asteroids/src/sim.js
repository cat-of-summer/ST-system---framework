import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';
import { ONE, isin, icos } from '../../assets/engine/src/imath.js';

// Мир высотой H единиц; ширина — params.w (сервер считает её из пропорций холста). Координаты и
// скорости — в фиксированной точке ×FP.
export const H  = 150;
export const FP = 256;

/** Ракета: x центра и её контур — три выпуклых многоугольника (корпус с носом и два крыла), единицы мира от центра. */
export const RX = 28;
export const ROCKET = [
    [[15, 0], [8, -4], [-11, -4], [-11, 4], [8, 4]],
    [[-1, -4], [-11, -4], [-14, -10], [-8, -10]],
    [[-1, 4], [-8, 10], [-14, 10], [-11, 4]],
];
export const NOSE = 15;
export const TAIL = 14;
/** Радиус круга, в который ракета вписана целиком, — для грубой проверки. */
export const RB   = 18;
export const YMIN = 11;
export const YMAX = H - 11;

/** Радиусы астероида по размерам: маленький (1–2 ХП), средний (3–5), большой (6–10). */
export const R = [8, 13, 20];
/** Вершин у контура; радиус вершины — R · m / 16, m в 12…17; угол сдвинут на j в −4…4. */
export const N  = 10;
export const M0 = 12;
export const M1 = 17;

export const HP0 = 5;
export const HP1 = 10;

/** Скорость ракеты, единиц за тик. */
export const PSPEED = 4;
/** Пуля: единиц за тик; выстрел раз в FIRE тиков, пока огонь включён; в полёте не больше SHOTS. */
export const SHOT  = 9;
export const FIRE  = 6;
export const SHOTS = 6;
/** Астероидов на поле не больше. */
export const ROCKS = 4;
/** Зона опасности перед носом: здесь у астероида считается прогноз, попадёт ли он в ракету. */
export const DANGER = 100;
/** Запас к сумме радиусов, при котором прогноз — угроза. */
export const MARGIN = 2;
/** На столько ракета должна сдвинуться от места, где её застала угроза, чтобы проход засчитался увёртом. */
export const MOVED = 8;
/** События (попадания, обломы, взрывы, увёрты) живут столько тиков — их читает отрисовка. */
export const KEEP = 15;

export const HIT = 0, SHRINK = 1, BOOM = 2, DODGE = 3;

/** Размер по ХП. */
export function tierOf(hp) {
    return hp >= 6 ? 2 : (hp >= 3 ? 1 : 0);
}

/** Радиус описанного круга, FP: самая дальняя вершина — m = M1. */
export function reachOf(tier) {
    return idiv(R[tier] * FP * M1, 16);
}

/** Знак ориентации тройки точек: > 0 — поворот по часовой (экранная ось y вниз). */
function orient(ax, ay, bx, by, cx, cy) {
    const v = (bx - ax) * (cy - ay) - (by - ay) * (cx - ax);
    return v > 0 ? 1 : (v < 0 ? -1 : 0);
}

function within(a, b, c) {
    return Math.min(a, b) <= c && c <= Math.max(a, b);
}

/** Отрезки p1–p2 и q1–q2 пересекаются или касаются. */
export function segments(p1, p2, q1, q2) {
    const o1 = orient(p1[0], p1[1], p2[0], p2[1], q1[0], q1[1]);
    const o2 = orient(p1[0], p1[1], p2[0], p2[1], q2[0], q2[1]);
    const o3 = orient(q1[0], q1[1], q2[0], q2[1], p1[0], p1[1]);
    const o4 = orient(q1[0], q1[1], q2[0], q2[1], p2[0], p2[1]);

    if (o1 * o2 < 0 && o3 * o4 < 0) return true;

    // Касание: конец одного отрезка лежит на другом.
    if (o1 === 0 && within(p1[0], p2[0], q1[0]) && within(p1[1], p2[1], q1[1])) return true;
    if (o2 === 0 && within(p1[0], p2[0], q2[0]) && within(p1[1], p2[1], q2[1])) return true;
    if (o3 === 0 && within(q1[0], q2[0], p1[0]) && within(q1[1], q2[1], p1[1])) return true;
    if (o4 === 0 && within(q1[0], q2[0], p2[0]) && within(q1[1], q2[1], p2[1])) return true;

    return false;
}

/** Точка внутри многоугольника — чётность пересечений луча вправо, без деления. */
export function inside(poly, x, y) {
    let odd = false;

    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const ax = poly[j][0], ay = poly[j][1], bx = poly[i][0], by = poly[i][1];
        if ((ay > y) === (by > y)) continue;

        // x пересечения ребра с горизонталью y правее точки.
        const lhs = (bx - ax) * (y - ay), rhs = (x - ax) * (by - ay);
        if (by > ay ? lhs > rhs : lhs < rhs) odd = !odd;
    }

    return odd;
}

/** Многоугольники пересекаются: рёбра задевают друг друга или один целиком внутри другого. */
export function overlap(p, q) {
    for (let i = 0, j = p.length - 1; i < p.length; j = i++)
        for (let k = 0, l = q.length - 1; k < q.length; l = k++)
            if (segments(p[j], p[i], q[l], q[k])) return true;

    return inside(q, p[0][0], p[0][1]) || inside(p, q[0][0], q[0][1]);
}

/**
 * Ракета против астероидов. Ракета у левого края ходит по вертикали и стреляет вправо, астероиды
 * летят справа налево, вращаются и отражаются от верха и низа. У астероида 5–10 ХП, попадание
 * снимает одну; на порогах (6 → 5, 3 → 2) он уменьшается, на 0 — взрывается.
 *
 * Хитбокс — тот же контур, что на экране: у астероида — неровный многоугольник hull(), повёрнутый
 * на rot, у ракеты — ROCKET. Столкновение — пересечение контуров; пуля — горизонтальный отрезок
 * пути за тик, попадает в первую точку контура на нём.
 *
 * Коды ввода: 0 — куда вести ракету (value — y центра, единицы мира), 1 — огонь включён, 2 — огонь
 * выключен. Пока огонь включён, выстрел раз в FIRE тиков.
 *
 * Реакция — сбитый астероид и увёрт: астероид, у которого на входе в зону опасности прогноз
 * показал удар, прошёл мимо, а ракета за это время сдвинулась на MOVED. Смерть — контур ракеты
 * задел астероид.
 *
 * Осмысленно: включить огонь, когда на линии огня астероид; новая цель ракеты под угрозой — вне
 * полосы удара, без угрозы — ближе к линии ближайшего астероида впереди.
 *
 * Астероид: {id, x, y, vx, vy, rot (×8, 0…2047), spin, hp, tier, m, j, seen, threat, py, ty, passed}:
 * seen — прогноз посчитан, py — его y, ty — где тогда была ракета.
 * Событие: [clock, вид, x, y, id] — для отрисовки, живёт KEEP тиков.
 */
export class AsteroidsSim extends Sim {

    setup() {
        this.w   = this.params.w | 0 || 267;
        this.ids = 0;
    }

    restart() {
        this.y      = idiv(H, 2) * FP;
        this.target = this.y;
        this.rocks  = [];
        this.shots  = [];
        this.firing = 0;
        this.cool   = 0;
        this.next   = 30;
        this.clock  = 0;
        this.events = [];
    }

    /** Вершины контура астероида, FP. */
    static hull(a) {
        const out = [], r = R[a.tier] * FP, base = a.rot >> 3;

        for (let k = 0; k < N; k++) {
            const ang = base + idiv(k * 256, N) + a.j[k], rr = idiv(r * a.m[k], 16);
            out.push([a.x + idiv(rr * icos(ang), ONE), a.y + idiv(rr * isin(ang), ONE)]);
        }

        return out;
    }

    /** Контур ракеты с центром на высоте y, FP: три многоугольника. */
    static rocket(y) {
        return ROCKET.map(function (part) {
            return part.map(function (p) { return [(RX + p[0]) * FP, y + p[1] * FP]; });
        });
    }

    /** Новые неровности контура: при рождении и когда астероид обломился. */
    shape(a) {
        a.m = [];
        a.j = [];
        for (let k = 0; k < N; k++) {
            a.m.push(M0 + this.rng.int(M1 - M0 + 1));
            a.j.push(this.rng.int(9) - 4);
        }
    }

    spawn() {
        const hp = HP0 + this.rng.int(HP1 - HP0 + 1), tier = tierOf(hp), reach = reachOf(tier);
        const lo = reach + FP, span = H * FP - 2 * reach - 2 * FP;
        const y  = lo + this.rng.int(Math.max(1, idiv(span, FP))) * FP;

        // Место у правого края занято — отложить.
        for (const b of this.rocks)
            if (b.x > (this.w - 40) * FP && Math.abs(b.y - y) < reach + reachOf(b.tier) + 4 * FP) {
                this.next = 8;
                return;
            }

        const a = {
            id: this.ids++, x: this.w * FP + reach + FP, y: y,
            vx: -(400 + this.rng.int(180) + Math.min(200, idiv(this.clock, 3))),
            vy: this.rng.int(129) - 64,
            rot: this.rng.int(2048),
            spin: (1 + this.rng.int(8)) * (this.rng.int(2) ? 1 : -1),
            hp: hp, tier: tier, m: null, j: null, seen: 0, threat: 0, py: 0, ty: 0, passed: 0,
        };
        this.shape(a);
        this.rocks.push(a);

        this.next = Math.max(28, 75 - idiv(this.clock, 12)) + this.rng.int(25);
    }

    /** y центра астероида, когда он дойдёт до ракеты: по vy с отражением от краёв. */
    static predict(a) {
        const t = idiv(a.x - RX * FP, -a.vx), reach = reachOf(a.tier);
        const lo = reach, span = H * FP - 2 * reach, period = 2 * span;
        if (span <= 0) return a.y;

        let v = (a.y + a.vy * t - lo) % period;
        if (v < 0) v += period;
        if (v > span) v = period - v;
        return lo + v;
    }

    /** Полоса удара: ближе к прогнозу — ракета задета. */
    static band(a) {
        return reachOf(a.tier) + (10 + MARGIN) * FP;
    }

    /** Ближайший к носу астероид с угрозой, ещё не прошедший ракету, или null. */
    threat() {
        let best = null;
        for (const a of this.rocks)
            if (a.threat && !a.passed && (!best || a.x < best.x)) best = a;
        return best;
    }

    /** Ближайший к носу астероид впереди или null. */
    ahead() {
        let best = null;
        for (const a of this.rocks)
            if (a.x > (RX + NOSE) * FP && (!best || a.x < best.x)) best = a;
        return best;
    }

    /** На линии огня астероид: y ракеты в вертикальном размахе его контура. */
    aimed() {
        for (const a of this.rocks) {
            if (a.x + reachOf(a.tier) <= (RX + NOSE) * FP) continue;
            let top = a.y, bottom = a.y;
            for (const p of AsteroidsSim.hull(a)) { if (p[1] < top) top = p[1]; if (p[1] > bottom) bottom = p[1]; }
            if (this.y >= top && this.y <= bottom) return true;
        }
        return false;
    }

    handle(code, value) {
        if (code === 0) {
            const target = Math.max(YMIN, Math.min(YMAX, value)) * FP;

            if (target !== this.target) {
                const t = this.threat();
                if (t) {
                    this.intent(Math.abs(target - t.py) >= AsteroidsSim.band(t));
                } else {
                    const a = this.ahead();
                    if (a) {
                        const before = Math.abs(this.target - a.y), after = Math.abs(target - a.y);
                        if (before !== after) this.intent(after < before);
                    }
                }
            }

            this.target = target;
        } else if (code === 1) {
            if (!this.firing) this.intent(this.aimed());
            this.firing = 1;
        } else if (code === 2) {
            this.firing = 0;
        }
    }

    event(kind, x, y, id) {
        this.events.push([this.clock, kind, x, y, id]);
    }

    update() {
        this.clock++;

        const kept = [];
        for (const e of this.events) if (this.clock - e[0] < KEEP) kept.push(e);
        this.events = kept;

        const d = this.target - this.y, step = PSPEED * FP;
        this.y += d > step ? step : (d < -step ? -step : d);

        if (--this.next <= 0 && this.rocks.length < ROCKS) this.spawn();

        this.fly();

        if (this.cool > 0) this.cool--;
        if (this.firing && this.cool === 0 && this.shots.length < SHOTS) {
            this.shots.push([(RX + NOSE) * FP, this.y]);
            this.cool = FIRE;
        }

        this.shoot();

        const body = AsteroidsSim.rocket(this.y);
        for (const a of this.rocks) {
            const dx = a.x - RX * FP, dy = a.y - this.y, near = reachOf(a.tier) + RB * FP;
            if (dx * dx + dy * dy > near * near) continue;

            const hull = AsteroidsSim.hull(a);
            for (const part of body)
                if (overlap(part, hull)) { this.lose(); return; }
        }
    }

    /** Астероиды летят, вращаются, отражаются от краёв; прогноз на входе в зону опасности, увёрт на проходе. */
    fly() {
        const kept = [];

        for (const a of this.rocks) {
            const reach = reachOf(a.tier);

            a.x  += a.vx;
            a.y  += a.vy;
            a.rot = (a.rot + a.spin + 2048) % 2048;

            if (a.y < reach) { a.y = reach; a.vy = Math.abs(a.vy); }
            if (a.y > H * FP - reach) { a.y = H * FP - reach; a.vy = -Math.abs(a.vy); }

            // Вошёл в зону опасности — прогноз удара.
            if (!a.seen && a.x - (RX + NOSE) * FP < DANGER * FP) {
                a.seen = 1;
                a.py   = AsteroidsSim.predict(a);
                if (Math.abs(a.py - this.y) < AsteroidsSim.band(a)) { a.threat = 1; a.ty = this.y; }
            }

            // Целиком позади ракеты.
            if (!a.passed && a.x + reach < (RX - TAIL) * FP) {
                a.passed = 1;
                if (a.threat && Math.abs(this.y - a.ty) >= MOVED * FP) {
                    this.score();
                    this.event(DODGE, RX * FP, this.y, a.id);
                }
            }

            if (a.x + reach < 0) continue;
            kept.push(a);
        }

        this.rocks = kept;
    }

    /** Пули летят вправо; попадание — первая точка контура на пути за тик. */
    shoot() {
        const kept = [];

        for (const s of this.shots) {
            const x0 = s[0], x1 = s[0] + SHOT * FP, y = s[1];
            let hit = null, hx = 0;

            for (const a of this.rocks) {
                const reach = reachOf(a.tier);
                if (a.hp <= 0 || Math.abs(a.y - y) > reach || a.x + reach < x0 || a.x - reach > x1) continue;

                const ix = AsteroidsSim.entry(AsteroidsSim.hull(a), x0, x1, y);
                if (ix >= 0 && (!hit || ix < hx)) { hit = a; hx = ix; }
            }

            if (hit) {
                this.damage(hit, hx, y);
                continue;
            }

            s[0] = x1;
            if (x1 <= (this.w + 30) * FP) kept.push(s);
        }

        this.shots = kept;
        this.rocks = this.rocks.filter(function (a) { return a.hp > 0; });
    }

    /**
     * Первая точка контура на горизонтальном отрезке x0…x1 на высоте y, FP, или −1. Начало отрезка
     * внутри контура (астероид наехал на пулю) — само начало.
     */
    static entry(hull, x0, x1, y) {
        if (inside(hull, x0, y)) return x0;

        let best = -1;
        for (let i = 0, j = hull.length - 1; i < hull.length; j = i++) {
            const ax = hull[j][0], ay = hull[j][1], bx = hull[i][0], by = hull[i][1];
            if (Math.min(ay, by) > y || Math.max(ay, by) < y) continue;

            const ix = ay === by ? Math.min(ax, bx) : ax + idiv((bx - ax) * (y - ay), by - ay);
            if (ix >= x0 && ix <= x1 && (best < 0 || ix < best)) best = ix;
        }
        return best;
    }

    /** Попадание в точку (x, y): минус ХП; на пороге — меньше и новый контур, на нуле — взрыв и очко. */
    damage(a, x, y) {
        a.hp--;
        this.event(HIT, x, y, a.id);

        if (a.hp <= 0) {
            this.event(BOOM, a.x, a.y, a.id);
            this.score();
            return;
        }

        const tier = tierOf(a.hp);
        if (tier !== a.tier) {
            a.tier = tier;
            this.shape(a);
            this.event(SHRINK, a.x, a.y, a.id);
        }
    }

    dump() {
        const out = [this.w, this.ids, this.y, this.target, this.firing, this.cool, this.next, this.clock, this.rocks.length];
        for (const a of this.rocks)
            out.push(a.id, a.x, a.y, a.vx, a.vy, a.rot, a.spin, a.hp, a.tier, a.seen, a.threat, a.py, a.ty, a.passed, ...a.m, ...a.j);
        out.push(this.shots.length);
        for (const s of this.shots) out.push(s[0], s[1]);
        out.push(this.events.length);
        for (const e of this.events) out.push(...e);
        return out;
    }
}
