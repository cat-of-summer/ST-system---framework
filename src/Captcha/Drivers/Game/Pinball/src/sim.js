import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';
import { ONE, isin, icos, isqrt } from '../../assets/engine/src/imath.js';

// Стол W × H единиц, портретный; физика в фиксированной точке ×256, 60 тиков в секунду, два
// подшага на тик — мяч не проскакивает сквозь стенки и флипперы.
export const W  = 160;
export const H  = 240;
export const FP = 256;

/** Радиус мяча, ускорение «наклона стола» (FP/тик²), предел скорости (FP/тик). */
export const R    = 4;
export const G    = 9;
export const VMAX = 1400;

/** Стенки: [x1, y1, x2, y2] в единицах. Желоб пускателя — справа, между x = 144 и 156. */
export const WALLS = [
    [30, 4, 130, 4],
    [4, 40, 30, 4],
    [130, 4, 156, 30],
    [4, 40, 4, 168],
    [156, 30, 156, 236],
    [144, 64, 144, 236],
    [4, 168, 38, 200],
    [144, 168, 106, 200],
    [144, 236, 156, 236],
];

/**
 * Перегородка желоба: как только мяч вылетел из желоба выше CLOSE_Y, вход в желоб закрывается
 * наклонной стенкой — мяч скатывается с неё в поле, а не обратно на пускатель. Открывается с
 * новым мячом.
 */
export const GATE    = [156, 40, 144, 64];
export const CLOSE_Y = 36;

/** Рогатки над флипперами: треугольник [A, B, C], толкает грань A–C. */
export const SLINGS = [
    [16, 138, 16, 166, 32, 178],
    [128, 138, 128, 166, 112, 178],
];

/**
 * Бамперы раскладываются от seed в верхней части поля: 3 или 4 штуки радиусом 8…10, по зоне
 * BUMPER_ZONE [x0, y0, x1, y1] (центры), не ближе друг к другу, чем радиусы плюс BUMPER_GAP — между
 * ними проходит мяч. Ниже зоны — рогатки и флипперы, правее — желоб.
 */
export const BUMPER_ZONE = [28, 48, 124, 128];
export const BUMPER_GAP  = 12;

/**
 * Флипперы: [ось x, ось y, длина, угол покоя, угол поднятого] — углы в 256 делениях круга, 0 —
 * вправо, 64 — вниз. Левый смотрит вправо-вниз и поднимается против часовой стрелки, правый —
 * зеркально.
 */
export const FLIPPERS = [[42, 204, 26, 22, -18], [102, 204, 26, 106, 146]];

/** Толщина флиппера, шаг его поворота за тик, отскок от стенок и бамперов в процентах. */
export const FR    = 3;
export const SWING = 8;
const WALL_E   = 45;
const FLIP_E   = 30;
const BUMP_KICK  = 520;
const SLING_KICK = 380;

/** Пускатель: где лежит мяч, сколько тиков копится заряд, скорость при пуске. */
export const BALL0 = [150, 232];
export const CHARGE = 48;
const LAUNCH0 = 520;
const LAUNCH_K = 18;

/** Нижняя часть стола (центр мяча ниже y = LOW): только тут нажатия флипперов — действия. */
export const LOW = 150;

/** Сколько тиков после нажатия ждать касания флиппера с мячом: дольше — взмах мимо. */
export const WINDOW = 24;

/** Очки: бампер, рогатка. */
export const BUMP_POINTS = 100;
export const SLING_POINTS = 10;

/**
 * Пинбол. Коды ввода: 0/1 — левый флиппер поднять/опустить, 2/3 — правый, 4/5 — пускатель
 * взвести/отпустить (заряд копится, пока держат, отпущен — мяч из желоба летит в поле; скорость
 * и наклон пуска чуть разбросаны от rng).
 *
 * Реакция — мяч отбит поднимающимся флиппером (не чаще раза, пока мяч не поднимется выше
 * середины низа стола). Мяч ушёл в слив между флипперами — смерть. Очки бамперов и рогаток —
 * points, это счёт на экране.
 *
 * Действие — поднять флиппер, пока мяч в игре (не в желобе пускателя). Осмысленно, если мяч в этот
 * момент внизу стола (low()) на половине этого флиппера (side()), — человек жмёт, когда мяч у
 * флипперов, а случайные нажатия раскиданы по времени. Мяч выше — осмысленно, если поднятый флиппер
 * за WINDOW тиков коснулся его (нажал заранее, мяч подлетел); не коснулся, нажат снова до касания
 * или мяч ушёл в слив — мимо.
 */
export class PinballSim extends Sim {

    get cooldown() { return 60; }

    setup() {
        this.points  = 0;
        this.bumpers = [];

        const count = 3 + this.rng.int(2), z = BUMPER_ZONE;
        for (let i = 0; i < count; i++)
            for (let tries = 0; tries < 30; tries++) {
                const x = z[0] + this.rng.int(z[2] - z[0]), y = z[1] + this.rng.int(z[3] - z[1]), r = 8 + this.rng.int(3);
                let free = true;
                for (const b of this.bumpers) {
                    const dx = x - b[0], dy = y - b[1], gap = r + b[2] + BUMPER_GAP;
                    if (dx * dx + dy * dy < gap * gap) { free = false; break; }
                }
                if (free) { this.bumpers.push([x, y, r]); break; }
            }
    }

    restart() {
        this.bx = BALL0[0] * FP;
        this.by = BALL0[1] * FP;
        this.vx = 0;
        this.vy = 0;
        // Флипперы: угол, нажат ли, сколько повернулся за тик.
        this.fa = [FLIPPERS[0][3], FLIPPERS[1][3]];
        this.fu = [0, 0];
        this.fd = [0, 0];
        this.wait = [0, 0];
        this.charge = 0;
        this.pull   = 0;
        this.lock   = 0;
        this.closed = 0;
        this.shut   = 0;
        // Вспышки для вида, тиков до конца: бамперы, затем рогатки.
        this.flash  = [];
        for (let i = 0; i < this.bumpers.length + SLINGS.length; i++) this.flash.push(0);
    }

    /** Мяч на половине стола флиппера f: левый — до x = 80, правый — от x = 64 (середина — обоим). */
    side(f) {
        const x = idiv(this.bx, FP);
        return f === 0 ? x <= 80 : x >= 64;
    }

    /** Мяч в игре в нижней части стола: не в желобе и ниже LOW. */
    low() {
        return this.bx < 144 * FP && this.by >= LOW * FP;
    }

    handle(code, value) {
        if (code === 0 || code === 2) {
            const f = code >> 1;
            if (!this.fu[f] && this.bx < 144 * FP) {
                // Прошлый взмах так и не коснулся мяча — мимо.
                if (this.wait[f]) { this.intent(false); this.wait[f] = 0; }
                // Мяч внизу — по делу сразу; выше — если флиппер коснётся его за WINDOW тиков.
                if (this.low() && this.side(f)) this.intent(true);
                else this.wait[f] = WINDOW;
            }
            this.fu[f] = 1;
        } else if (code === 1 || code === 3) {
            this.fu[code >> 1] = 0;
        } else if (code === 4) {
            this.pull = 1;
        } else if (code === 5) {
            // Пуск с разбросом от seed: одна и та же сила не даёт одну и ту же траекторию.
            if (this.pull && this.inLane()) {
                const jitter = this.rng.int(41);
                this.vx = jitter - 20;
                this.vy = -Math.min(VMAX, LAUNCH0 + this.charge * LAUNCH_K + jitter);
            }
            this.pull = 0;
            this.charge = 0;
        }
    }

    /** Мяч лежит на пускателе. */
    inLane() {
        return this.bx > 144 * FP && this.by > 220 * FP;
    }

    update() {
        if (this.pull && this.charge < CHARGE) this.charge++;
        for (let f = 0; f < 2; f++) if (this.wait[f] && --this.wait[f] === 0) this.intent(false);
        for (let i = 0; i < this.flash.length; i++) if (this.flash[i] > 0) this.flash[i]--;

        // Флипперы поворачиваются к нужному углу, по половине шага в подшаге.
        for (let f = 0; f < 2; f++) {
            const p = FLIPPERS[f], to = this.fu[f] ? p[4] : p[3];
            const d = Math.max(-SWING, Math.min(SWING, to - this.fa[f]));
            this.fd[f] = d;
        }

        this.vy += G;
        this.clamp();

        for (let k = 0; k < 2; k++) {
            for (let f = 0; f < 2; f++) this.fa[f] += k === 0 ? idiv(this.fd[f], 2) : this.fd[f] - idiv(this.fd[f], 2);

            this.bx += idiv(this.vx, 2);
            this.by += idiv(this.vy, 2);
            this.collide();
        }

        if (this.by < 160 * FP) this.lock = 0;
        if (!this.closed && this.bx > 144 * FP && this.by < CLOSE_Y * FP) this.closed = 1;
        // Перегородка въезжает за несколько тиков — только для вида.
        if (this.closed && this.shut < 10) this.shut++;

        if (this.by > (H + R * 2) * FP) {
            for (let f = 0; f < 2; f++) if (this.wait[f]) { this.intent(false); this.wait[f] = 0; }
            this.lose();
        }
    }

    clamp() {
        const len = isqrt(this.vx * this.vx + this.vy * this.vy);
        if (len > VMAX) {
            this.vx = idiv(this.vx * VMAX, len);
            this.vy = idiv(this.vy * VMAX, len);
        }
    }

    collide() {
        for (const w of WALLS) this.segment(w[0] * FP, w[1] * FP, w[2] * FP, w[3] * FP, 0, WALL_E, 0, 0, 0, -1);
        if (this.closed) this.segment(GATE[0] * FP, GATE[1] * FP, GATE[2] * FP, GATE[3] * FP, 0, WALL_E, 0, 0, 0, -1);

        for (let i = 0; i < SLINGS.length; i++) {
            const s = SLINGS[i];
            if (this.segment(s[0] * FP, s[1] * FP, s[4] * FP, s[5] * FP, 0, WALL_E, SLING_KICK, 0, 0, -1)) {
                this.flash[this.bumpers.length + i] = 8;
                this.points += SLING_POINTS;
            }
            this.segment(s[0] * FP, s[1] * FP, s[2] * FP, s[3] * FP, 0, WALL_E, 0, 0, 0, -1);
            this.segment(s[2] * FP, s[3] * FP, s[4] * FP, s[5] * FP, 0, WALL_E, 0, 0, 0, -1);
        }

        for (let i = 0; i < this.bumpers.length; i++) {
            const b = this.bumpers[i];
            if (this.segment(b[0] * FP, b[1] * FP, b[0] * FP, b[1] * FP, b[2] * FP, WALL_E, BUMP_KICK, 0, 0, -1)) {
                this.flash[i] = 10;
                this.points += BUMP_POINTS;
            }
        }

        for (let f = 0; f < 2; f++) {
            const p = FLIPPERS[f], tip = this.tip(f);
            this.segment(p[0] * FP, p[1] * FP, tip[0], tip[1], FR * FP, FLIP_E, 0, p[0] * FP, p[1] * FP, f);
        }

        this.clamp();
    }

    /** Конец флиппера f, FP. */
    tip(f) {
        const p = FLIPPERS[f];
        return [p[0] * FP + idiv(p[2] * FP * icos(this.fa[f]), ONE), p[1] * FP + idiv(p[2] * FP * isin(this.fa[f]), ONE)];
    }

    /**
     * Столкновение мяча с отрезком (ax, ay)–(bx, by) толщиной rad (точка — круг бампера): мяч
     * выталкивается по нормали, нормальная составляющая скорости отражается с потерей e %, плюс
     * толчок kick. У флиппера f (≥ 0) — относительно скорости его поверхности в точке касания
     * (вращение вокруг оси ox, oy). Возвращает, было ли касание.
     */
    segment(ax, ay, bx, by, rad, e, kick, ox, oy, f) {
        const dx = bx - ax, dy = by - ay, len2 = dx * dx + dy * dy;
        let px = ax, py = ay;

        if (len2 > 0) {
            let t = (this.bx - ax) * dx + (this.by - ay) * dy;
            if (t < 0) t = 0;
            if (t > len2) t = len2;
            px = ax + idiv(dx * t, len2);
            py = ay + idiv(dy * t, len2);
        }

        const cx = this.bx - px, cy = this.by - py;
        const reach = R * FP + rad;
        const d2 = cx * cx + cy * cy;
        if (d2 >= reach * reach) return false;

        const d = isqrt(d2);
        if (d === 0) return false;

        const nx = idiv(cx * ONE, d), ny = idiv(cy * ONE, d);

        // Выталкивание из стенки.
        this.bx += idiv(nx * (reach - d), ONE);
        this.by += idiv(ny * (reach - d), ONE);

        // Скорость поверхности флиппера: ω × r, ω — поворот за тик (2π / 256 ≈ 804 / 32768 рад).
        let sx = 0, sy = 0;
        if (f >= 0 && this.fd[f] !== 0) {
            sx = idiv(-(py - oy) * this.fd[f] * 804, 32768);
            sy = idiv((px - ox) * this.fd[f] * 804, 32768);
        }

        let rx = this.vx - sx, ry = this.vy - sy;
        const vn = idiv(rx * nx + ry * ny, ONE);

        if (vn < 0) {
            const j = idiv(-vn * (100 + e), 100);
            rx += idiv(j * nx, ONE);
            ry += idiv(j * ny, ONE);
        }
        if (kick) {
            rx += idiv(kick * nx, ONE);
            ry += idiv(kick * ny, ONE);
        }

        this.vx = rx + sx;
        this.vy = ry + sy;

        // Поднятый флиппер коснулся мяча — взмах был по делу.
        if (f >= 0 && this.fu[f] && this.wait[f]) {
            this.intent(true);
            this.wait[f] = 0;
        }

        // Отбит поднимающимся флиппером — мяч полетел вверх.
        if (f >= 0 && this.fu[f] && this.fd[f] !== 0 && this.vy < 0 && !this.lock) {
            this.lock = 1;
            this.score();
        }

        return true;
    }

    dump() {
        return [this.bx, this.by, this.vx, this.vy, this.fa[0], this.fa[1], this.fu[0], this.fu[1], this.fd[0], this.fd[1], this.wait[0], this.wait[1],
            this.charge, this.pull, this.lock, this.closed, this.shut, this.points, this.bumpers.length].concat(this.flash, [].concat.apply([], this.bumpers));
    }
}
