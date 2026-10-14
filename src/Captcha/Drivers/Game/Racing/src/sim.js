import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';
import { isin, icos, isqrt, iatan2, adiff } from '../../assets/engine/src/imath.js';

// Мир в единицах, машина — в фиксированной точке ×256; 30 тиков в секунду.
export const FP = 256;

/** Трасса: K опорных точек по кругу, между соседними — S точек сглаживания; всего N = K · S. */
export const K = 12;
export const S = 8;
export const N = K * S;

/**
 * Опорные точки: на расстоянии R0 + rng(RV) от центра (CX, CY), угол — равномерно плюс сдвиг до
 * ±JITTER (256 делений круга), по x трасса шире в 115…145 / 100 раз. Первая точка — слева от
 * центра (угол START_A), углы растут — на экране (ось y вниз) это движение по часовой стрелке:
 * со старта машина едет вверх.
 */
export const CX = 600;
export const CY = 450;
export const R0 = 160;
export const RV = 250;
export const JITTER = 7;
export const START_A = 128;

/**
 * Трасса годится, если её витки не ближе GAP друг к другу (отбойники не наезжают) и нигде не
 * поворачивает круче BEND делений круга на точку; иначе строится заново, до TRIES раз, с каждым
 * разом спокойнее: разброс радиуса и угла убывает до нуля, последняя попытка — гладкий овал.
 */
export const GAP = 150;
export const BEND = 20;
export const TRIES = 40;

/** Полуширина асфальта и травы до отбойника, от осевой; радиус машины для отбойника. */
export const ROAD = 26;
export const WALL = 58;
export const CAR_R = 5;

/** Чекпоинтов на круг: равномерно по точкам трассы, последний — линия старта. */
export const CHECKS = 8;

/** Кнопки в маске кода 0. */
export const UP = 1, RIGHT = 2, DOWN = 4, LEFT = 8;

/**
 * Машина. Скорость — вектор (vx, vy), FP за тик; курс ×8 (2048 делений круга). Масштаб: машина 15
 * единиц ≈ 4,5 м, единица ≈ 0,3 м, 30 тиков в секунду; VMAX 1500 FP/тик ≈ 190 км/ч.
 *
 * Мотор: тяга A0 · (VENG − v) / VENG — сильная на старте, слабеет к максимуму, как у настоящей
 * машины: до 100 км/ч ≈ 1,2 с, до VMAX ≈ 3,8 с. Без газа — накат: сопротивление воздуха и шин
 * ≈ 10 % скорости в секунду. Тормоз — ≈ 2 с с максимума до нуля.
 *
 * Руль steer — от −STEER до STEER: пока кнопку держат, растёт на STEER_IN за тик, отпустили — к
 * нулю на STEER_OUT — откликается быстро. Скорость поворота растёт со скоростью до TURN_V, выше —
 * слабеет: на большой скорости машина поворачивает шире.
 */
const A0 = 30;
const VENG = [1750, 700];
const BRAKE = 24;
const VMAX = 1500;
const VBACK = 400;
const VGRASS = 600;
export const STEER = 32;
const STEER_IN = 11;
const STEER_OUT = 11;
const TURN = 15;
const TURN_V = 900;

/**
 * Шины: боковая скорость до SLIP гасится сцеплением (GRIP / 32 за тик), больше — занос: она
 * гасится лишь на SLIDE за тик, а сам занос съедает продольную скорость. На траве всё хуже.
 */
export const SLIP = 100;
const GRIP = [14, 6];
const SLIDE = [80, 45];
const SLIP_GRASS = 80;

/** Точка, на которую смотрит руль: столько точек трассы вперёд. */
export const AHEAD = 4;

/**
 * Гонка с видом сверху по замкнутой трассе от seed, по часовой стрелке. Код ввода 0, value — маска
 * зажатых кнопок (UP газ, DOWN тормоз и задний ход, LEFT/RIGHT руль), пишется при каждой смене.
 *
 * Руль нарастает, пока его держат: короткое нажатие доворачивает, долгое — закладывает вираж.
 * Поворот немного гасит скорость; на скорости машину сносит, занос тоже её гасит. Трава — хуже
 * сцепление, ниже предел скорости. Отбойник — без отскока: скорость в стенку гаснет, вдоль стенки
 * машина трётся и тормозит, вплоть до остановки.
 *
 * Реакция — пройден очередной чекпоинт (по порядку, назад не считается); CHECKS на круг. Смерти
 * нет. Круг пройден — laps + 1, его время — в lapTimes; партия закончена, когда кругов params.laps.
 *
 * Осмысленное действие — нажать руль (LEFT или RIGHT) в сторону, куда уходит трасса: к точке на
 * AHEAD точек вперёд. Газ и тормоз — не действия.
 */
export class RacingSim extends Sim {

    get cooldown() { return 1; }

    setup() {
        this.laps0 = Math.max(1, this.params.laps | 0 || 1);
        this.track = RacingSim.build(this.rng);
        this.checks = [];
        for (let c = 1; c <= CHECKS; c++) this.checks.push(idiv(c * N, CHECKS) % N);
    }

    /** Трасса от rng: первая годная из TRIES попыток, каждая следующая — со сниженным разбросом. */
    static build(rng) {
        let track = null;
        for (let tries = 0; tries < TRIES; tries++) {
            const left = TRIES - 1 - tries;
            track = RacingSim.shape(rng, idiv(RV * left, TRIES - 1), idiv(JITTER * left, TRIES - 1));
            if (RacingSim.valid(track)) break;
        }
        return track;
    }

    /** K опорных точек со случайным радиусом и углом, сглаженных Catmull-Rom в N точек [x, y]. */
    static shape(rng, rv, jitter) {
        const sx = 115 + rng.int(31), ctrl = [];
        for (let k = 0; k < K; k++) {
            const a = START_A + idiv(k * 256, K) + rng.int(jitter * 2 + 1) - jitter, r = R0 + rng.int(rv + 1);
            ctrl.push([CX + idiv(idiv(r * icos(a), 1024) * sx, 100), CY + idiv(r * isin(a), 1024)]);
        }

        const out = [], s3 = S * S * S;
        for (let k = 0; k < K; k++) {
            const p0 = ctrl[(k + K - 1) % K], p1 = ctrl[k], p2 = ctrl[(k + 1) % K], p3 = ctrl[(k + 2) % K];
            for (let s = 0; s < S; s++) {
                const pt = [];
                for (let d = 0; d < 2; d++) {
                    const num = 2 * p1[d] * s3
                        + (p2[d] - p0[d]) * s * S * S
                        + (2 * p0[d] - 5 * p1[d] + 4 * p2[d] - p3[d]) * s * s * S
                        + (3 * p1[d] - p0[d] - 3 * p2[d] + p3[d]) * s * s * s;
                    pt.push(idiv(num, 2 * s3));
                }
                out.push(pt);
            }
        }
        return out;
    }

    /**
     * Ни одного поворота круче BEND на точку, и разные витки не ближе GAP: точки, которые по прямой
     * ближе GAP, а по трассе — дальше 2 · GAP (в обе стороны), — это другой виток.
     */
    static valid(t) {
        const cum = [0];
        for (let i = 0; i < N; i++) {
            const a = t[i], b = t[(i + 1) % N], c = t[(i + 2) % N];
            if (Math.abs(adiff(iatan2(b[1] - a[1], b[0] - a[0]), iatan2(c[1] - b[1], c[0] - b[0]))) > BEND) return false;
            cum.push(cum[i] + isqrt((b[0] - a[0]) * (b[0] - a[0]) + (b[1] - a[1]) * (b[1] - a[1])));
        }

        const total = cum[N];
        for (let i = 0; i < N; i++)
            for (let j = i + 1; j < N; j++) {
                const along = cum[j] - cum[i];
                if (Math.min(along, total - along) <= 2 * GAP) continue;
                const dx = t[j][0] - t[i][0], dy = t[j][1] - t[i][1];
                if (dx * dx + dy * dy < GAP * GAP) return false;
            }
        return true;
    }

    restart() {
        const p = this.track[0], q = this.track[1];
        this.x = p[0] * FP;
        this.y = p[1] * FP;
        this.h = iatan2(q[1] - p[1], q[0] - p[0]) * 8;
        this.vx = 0;
        this.vy = 0;
        this.vf = 0;
        this.vl = 0;
        this.steer = 0;
        this.keys = 0;
        this.seg = 0;
        this.off = 0;
        this.cp = 0;
        this.laps = 0;
        this.clock = 0;
        this.lapAt = 0;
        this.best = 0;
        this.lapTimes = [];
        this.drift = 0;
        this.scrape = 0;
    }

    /**
     * Ближайшая к машине точка осевой около прошлого отрезка, всё в FP: [отрезок, квадрат
     * расстояния, x, y точки осевой].
     */
    nearest() {
        let best = -1, bestD = Infinity, px = 0, py = 0;

        for (let o = -8; o <= 8; o++) {
            const i = (this.seg + o + N) % N, a = this.track[i], b = this.track[(i + 1) % N];
            const ax = a[0] * FP, ay = a[1] * FP, dx = (b[0] - a[0]) * FP, dy = (b[1] - a[1]) * FP, len2 = dx * dx + dy * dy;
            let t = (this.x - ax) * dx + (this.y - ay) * dy;
            if (t < 0) t = 0;
            if (t > len2) t = len2;
            const qx = ax + (len2 ? idiv(dx * t, len2) : 0), qy = ay + (len2 ? idiv(dy * t, len2) : 0);
            const d = (this.x - qx) * (this.x - qx) + (this.y - qy) * (this.y - qy);
            if (d < bestD) { bestD = d; best = i; px = qx; py = qy; }
        }

        return [best, bestD, px, py];
    }

    /** Насколько курс отличается от направления на точку AHEAD вперёд: < 0 — трасса уходит влево. */
    aim() {
        const t = this.track[(this.seg + AHEAD) % N];
        const want = iatan2(t[1] - idiv(this.y, FP), t[0] - idiv(this.x, FP));
        return adiff(this.h >> 3, want);
    }

    /** На траве: дальше ROAD от осевой. */
    grass() {
        return this.off > ROAD * ROAD * FP * FP;
    }

    handle(code, value) {
        if (code !== 0) return;
        value &= 15;

        const pressed = value & ~this.keys;
        if (pressed & (LEFT | RIGHT)) {
            const d = this.aim();
            const left = (pressed & LEFT) !== 0;
            // Задним ходом руль работает наоборот.
            const want = this.vf < 0 ? !left : left;
            this.intent(want ? d <= 2 : d >= -2);
        }

        this.keys = value;
    }

    update() {
        this.clock++;
        const k = this.keys, g = this.grass() ? 1 : 0;

        // Руль: держат — нарастает, отпустили — возвращается.
        const dir = (k & RIGHT ? 1 : 0) - (k & LEFT ? 1 : 0);
        if (dir) this.steer = Math.max(-STEER, Math.min(STEER, this.steer + dir * STEER_IN));
        else if (this.steer > 0) this.steer = Math.max(0, this.steer - STEER_OUT);
        else this.steer = Math.min(0, this.steer + STEER_OUT);

        // Скорость в осях машины: вдоль (vf) и вбок (vl).
        const c = icos(this.h >> 3), s = isin(this.h >> 3);
        let vf = idiv(this.vx * c + this.vy * s, 1024);
        let vl = idiv(-this.vx * s + this.vy * c, 1024);

        const top = g ? VGRASS : VMAX;
        if (k & UP) {
            if (vf < top) vf = Math.min(top, vf + Math.max(1, Math.min(A0 * 2, idiv(A0 * (VENG[g] - vf), VENG[g]))));
        } else if (k & DOWN) {
            vf = Math.max(-VBACK, vf - (vf > 0 ? BRAKE : 10));
        } else {
            // Накат: сопротивление воздуха и качения, до остановки.
            const drag = idiv(Math.abs(vf), 300) + 2;
            vf = vf > 0 ? Math.max(0, vf - drag) : Math.min(0, vf + drag);
        }
        // Выше предела (въехал на траву) — гасится плавно, без рывка.
        if (vf > top) vf -= idiv(vf - top, 20) + 1;
        if (g) vf -= idiv(vf, 80);

        // Поворот: курс — на руль, умноженный на долю скорости (на большой скорости — шире); чуть
        // гасит скорость.
        const speed = Math.abs(vf), steerAbs = Math.abs(this.steer);
        let turn = idiv(this.steer * TURN * Math.min(speed, TURN_V), STEER * TURN_V);
        if (speed > TURN_V) turn = idiv(turn * 3 * TURN_V, 2 * TURN_V + speed);
        this.h = (this.h + (vf < 0 ? -turn : turn) + 2048) % 2048;
        vf -= idiv(vf * steerAbs, STEER * 300);

        // Шины: сцепление гасит снос, сверх предела — занос, он съедает продольную скорость.
        const slip = g ? SLIP_GRASS : SLIP;
        if (Math.abs(vl) <= slip) {
            vl -= idiv(vl * GRIP[g], 32);
            this.drift = 0;
        } else {
            const loss = idiv(Math.abs(vl), 64);
            vf -= vf > 0 ? Math.min(vf, loss) : -Math.min(-vf, loss);
            vl -= vl > 0 ? SLIDE[g] : -SLIDE[g];
            this.drift = 1;
        }

        // Обратно в мир — в осях, какими они были до поворота: машина повернулась, а скорость ещё
        // летит по-старому; на следующем тике разница станет сносом.
        this.vx = idiv(vf * c - vl * s, 1024);
        this.vy = idiv(vf * s + vl * c, 1024);
        this.vf = vf;
        this.vl = vl;

        this.x += this.vx;
        this.y += this.vy;

        const n = this.nearest();
        this.seg = n[0];
        this.off = n[1];

        // Отбойник: дальше WALL − CAR_R от осевой — машину ставит вплотную к нему, скорость в стенку
        // гаснет, вдоль стенки — трётся: каждый тик касания теряет четверть.
        const limit = (WALL - CAR_R) * FP;
        this.scrape = 0;
        if (n[1] > limit * limit) {
            const dx = this.x - n[2], dy = this.y - n[3], d = Math.max(1, isqrt(n[1]));
            const nx = idiv(dx * 1024, d), ny = idiv(dy * 1024, d);
            this.x = n[2] + idiv(nx * limit, 1024);
            this.y = n[3] + idiv(ny * limit, 1024);

            const into = idiv(this.vx * nx + this.vy * ny, 1024);
            if (into > 0) {
                this.vx -= idiv(into * nx, 1024);
                this.vy -= idiv(into * ny, 1024);
            }
            this.vx -= idiv(this.vx, 4);
            this.vy -= idiv(this.vy, 4);
            this.off = limit * limit;
            this.scrape = 1;
        }

        // Чекпоинт — машина в первых трёх точках после него.
        const target = this.checks[this.cp];
        if ((this.seg - target + N) % N < 3) {
            this.score();
            this.cp++;
            if (this.cp === CHECKS) {
                this.cp = 0;
                this.laps++;
                const time = this.clock - this.lapAt;
                this.lapTimes.push(time);
                if (!this.best || time < this.best) this.best = time;
                this.lapAt = this.clock;
            }
        }
    }

    dump() {
        return [this.x, this.y, this.h, this.vx, this.vy, this.vf, this.vl, this.steer, this.keys, this.seg, this.off,
            this.cp, this.laps, this.clock, this.lapAt, this.best, this.drift, this.scrape].concat(this.lapTimes);
    }
}
