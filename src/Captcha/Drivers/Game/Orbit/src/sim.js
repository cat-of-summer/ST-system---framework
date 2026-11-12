import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';
import { ilen, isqrt } from '../../assets/engine/src/imath.js';

/**
 * Мир: короткая сторона игровой зоны — SHORT единиц, длинная — по пропорциям холста (params.w,
 * params.h; 320 × 200 → 1778 × 1000). Координаты и скорости — в фиксированной точке ×FP.
 */
export const SHORT = 1000;
export const FP    = 1024;

/**
 * Шаг физики — 1/STEPS секунды игрового времени; при обычном темпе шаг на тик (60 тиков в
 * секунду — игра идёт в 0,6 от реального времени). Скорость — FP за секунду игры, позиция за шаг
 * сдвигается на idiv(v, STEPS).
 */
export const STEPS = 100;

/** Сторона квадрата ракеты (хитбокс), единицы. */
export const ROCKET = 20;

/** Притяжение внутри поля планеты: 300 ед./с², за шаг — 3 ед./с, в FP. Постоянное, как в ABS. */
export const GRAV = 3 * FP;

/** Натяжение рогатки, единицы: максимум и мёртвая зона. */
export const MAX_PULL = 220;
export const MIN_PULL = 20;

/**
 * Натяжение в коде ввода 1 — в половинах единицы, ±PULL_Q по каждой оси:
 * value = (qx + PULL_Q) · PULL_PACK + (qy + PULL_Q).
 */
export const PULL_Q    = 110;
export const PULL_PACK = 2 * PULL_Q + 1;

/**
 * Скорость рывка: от V_MIN у края мёртвой зоны до V_MAX при полном натяжении, FP/с. V_MIN хватает,
 * чтобы выбраться из поля своей планеты со стартовой орбиты в любую сторону, кроме самой планеты.
 */
export const V_MIN = 380 * FP;
export const V_MAX = 580 * FP;

/**
 * Динамическая орбита: доля скорости вдоль планеты (косинус угла к касательной), с которой
 * траектория начинает походить на орбиту (кольцо проступает, только вид) и с которой ракета на неё
 * встаёт; во сколько раз ракета может быть быстрее круговой скорости √(g·d), чтобы орбита её взяла.
 * Доли — в десятых.
 */
export const ALONG_SHOW  = 6;
export const ALONG_CATCH = 9;
export const FAST_MAX    = 26;

/** Остаток радиальной скорости после посадки на орбиту гаснет как e^(−4t): ×984/1024 за шаг. */
export const SETTLE = 984;

/** Ниже этой высоты над поверхностью орбита не опускается, единицы. */
export const ORBIT_MIN_ALT = ROCKET;

/** Вылет за поле дальше OUT единиц или полёт дольше MAX_FLIGHT шагов (15 с игры) — потеря. */
export const OUT        = 300;
export const MAX_FLIGHT = 15 * STEPS;

/**
 * Темп игры в промилле: 1000 — шаг физики за тик, 100 — пока тянут рогатку и ракету можно
 * запустить (замедление на 90 %). К цели темп тянется на EASE/1024 разницы за тик (~10/с).
 */
export const RATE_FULL = 1000;
export const RATE_AIM  = 100;
export const EASE      = 157;

/** Подсказка траектории: шагов вперёд (4 с игры) и шаг между точками. */
export const PREVIEW_STEPS = 4 * STEPS;
export const PREVIEW_EVERY = 4;

/** Состояния ракеты. */
export const ORBIT  = 0;
export const FLIGHT = 1;

/**
 * Раскладка: первая планета — стартовая. Доли ширины и высоты мира в промилле, радиус планеты,
 * радиус поля гравитации и стартовой орбиты — в единицах. Отдельно для горизонтальной и
 * вертикальной игровой зоны.
 */
export const LANDSCAPE = [
    [240, 620, 60, 300, 135],
    [520, 300, 36, 240, 90],
    [780, 620, 85, 340, 175],
];

export const PORTRAIT = [
    [350, 780, 60, 300, 135],
    [680, 520, 36, 240, 90],
    [420, 270, 85, 340, 175],
];

/** Итоги шага полёта (flightStep): летит дальше, разбилась, потерялась; ≥ 0 — поймана орбитой планеты. */
export const FLYING = -1;
export const CRASH  = -2;
export const LOST   = -3;

/** Скорость рывка по натяжению в коде ввода (половины единицы); null — в мёртвой зоне. */
export function launchVelocity(qx, qy) {
    const len = isqrt((qx * qx + qy * qy) * 4 * FP * FP);
    if (len < MIN_PULL * FP) return null;

    const speed = V_MIN + idiv((V_MAX - V_MIN) * (Math.min(len, MAX_PULL * FP) - MIN_PULL * FP), (MAX_PULL - MIN_PULL) * FP);
    return [idiv(2 * qx * FP * speed, len), idiv(2 * qy * FP * speed, len)];
}

/** Натяжение → value кода 1. */
export function packPull(qx, qy) {
    return (qx + PULL_Q) * PULL_PACK + (qy + PULL_Q);
}

/** value кода 1 → натяжение [qx, qy]; null — значение не из рогатки. */
export function unpackPull(value) {
    if (value < 0 || value >= PULL_PACK * PULL_PACK) return null;
    return [idiv(value, PULL_PACK) - PULL_Q, value % PULL_PACK - PULL_Q];
}

/** Шаг полунеявным Эйлером: постоянное притяжение полей, в которых тело находится. */
export function gravityStep(b, planets) {
    let ax = 0, ay = 0;
    for (const p of planets) {
        const dx = p.x - b.x, dy = p.y - b.y;
        const d  = ilen(dx, dy);
        if (d < FP || d > p.field * FP) continue;
        ax += idiv(dx * GRAV, d);
        ay += idiv(dy * GRAV, d);
    }
    b.vx += ax;
    b.vy += ay;
    b.x  += idiv(b.vx, STEPS);
    b.y  += idiv(b.vy, STEPS);
}

/** Индекс планеты, в которую врезалась ракета, или −1. */
export function hitPlanet(b, planets) {
    for (let i = 0; i < planets.length; i++) {
        const p = planets[i], dx = b.x - p.x, dy = b.y - p.y, reach = (p.r * 2 + ROCKET) * FP / 2;
        if (dx * dx + dy * dy < reach * reach) return i;
    }
    return -1;
}

/**
 * Траектория легла бы на орбиту планеты p: ракета в поле, идёт почти по касательной (доля
 * скорости вдоль планеты не меньше ALONG_CATCH/10) и не быстрее FAST_MAX/10 круговой скорости.
 */
export function catches(b, p) {
    const rx = b.x - p.x, ry = b.y - p.y;
    const d  = ilen(rx, ry);
    if (d > p.field * FP || d < FP) return false;

    const v = ilen(b.vx, b.vy);
    if (v >= FP && 10 * Math.abs(rx * b.vy - ry * b.vx) < ALONG_CATCH * d * v) return false;

    // v / √(g·d) ≤ FAST_MAX / 10, в FP: v² · 100 ≤ FAST_MAX² · 300 · d · FP.
    return 100 * v * v <= FAST_MAX * FAST_MAX * 300 * d * FP;
}

function outside(b, w, h) {
    return b.x < -OUT * FP || b.y < -OUT * FP || b.x > (w + OUT) * FP || b.y > (h + OUT) * FP;
}

/**
 * Шаг полёта тела b {x, y, vx, vy, ignore, flight}: гравитация, удар, вылет, выход из поля
 * родной планеты (ignore), захват орбитой. Возвращает FLYING, CRASH, LOST или индекс планеты,
 * орбита которой поймала тело. Тот же шаг у симуляции, подсказки бота и тестов.
 */
export function flightStep(b, planets, w, h) {
    gravityStep(b, planets);
    b.flight++;

    if (hitPlanet(b, planets) >= 0) return CRASH;
    if (outside(b, w, h) || b.flight > MAX_FLIGHT) return LOST;

    const home = planets[b.ignore];
    if (home) {
        const dx = b.x - home.x, dy = b.y - home.y;
        if (dx * dx + dy * dy > home.field * home.field * FP * FP) b.ignore = -1;
    }

    for (let i = 0; i < planets.length; i++)
        if (i !== b.ignore && catches(b, planets[i])) return i;

    return FLYING;
}

/**
 * Подсказка траектории после рывка (vx, vy) из точки (x, y): только гравитация полей, без орбит,
 * как в исходной игре. points — [x, y] в FP через PREVIEW_EVERY шагов, hit — точка удара или null.
 */
export function preview(sim, x, y, vx, vy) {
    const b = { x: x, y: y, vx: vx, vy: vy };
    const points = [[x, y]];
    for (let i = 1; i <= PREVIEW_STEPS; i++) {
        gravityStep(b, sim.planets);
        if (hitPlanet(b, sim.planets) >= 0) return { points: points, hit: [b.x, b.y] };
        if (outside(b, sim.w, sim.h)) break;
        if (i % PREVIEW_EVERY === 0) points.push([b.x, b.y]);
    }
    return { points: points, hit: null };
}

/**
 * Полёт после рывка (vx, vy) с орбиты, где ракета сейчас, до исхода — как его прожила бы
 * симуляция: индекс планеты, орбита которой поймает ракету, CRASH или LOST. Для бота и тестов.
 */
export function outcome(sim, vx, vy) {
    const b = { x: sim.x, y: sim.y, vx: vx, vy: vy, ignore: sim.planet, flight: 0 };
    for (;;) {
        const r = flightStep(b, sim.planets, sim.w, sim.h);
        if (r !== FLYING) return r;
    }
}

/**
 * Орбиты: ракета рогаткой перелетает между планетами. Физика как в Angry Birds Space — у
 * планеты большое круглое поле, внутри которого ракету тянет к центру с постоянной силой, снаружи
 * она летит по прямой. Сверх ABS — динамические орбиты: траектория, идущая в поле почти по
 * касательной и не слишком быстро для этой высоты, ложится на орбиту (радиус — текущая высота,
 * скорость — своя), и с неё ракета уже не падает. Своя планета не ловит ракету, пока та не
 * вышла из её поля.
 *
 * Коды ввода: 0 — начали тянуть рогатку (игра замедляется, пока ракета на орбите), 1 — отпустили
 * (value — натяжение, packPull(); в мёртвой зоне — просто отмена), 2 — прицеливание отменено.
 *
 * Запуск — действие; осмыслен, если полёт кончился орбитой другой планеты. Перелёт на орбиту
 * другой планеты — реакция. Удар о планету, вылет за поле или слишком долгий полёт — смерть,
 * новая жизнь — с орбиты первой планеты.
 *
 * Орбита хранится без углов: смещение от центра планеты (ox, oy), радиус, остаток радиальной
 * скорости и скорость вдоль орбиты; шаг поворачивает смещение на малый угол (sin и cos рядом
 * Тейлора в целых) и возвращает его длину к радиусу.
 */
export class OrbitSim extends Sim {

    get cooldown() { return 60; }

    setup() {
        this.w = this.params.w | 0 || 1778;
        this.h = this.params.h | 0 || 1000;

        const w = this.w, h = this.h;
        this.planets = (h > w ? PORTRAIT : LANDSCAPE).map(function (s) {
            return { x: idiv(s[0] * w * FP, 1000), y: idiv(s[1] * h * FP, 1000), r: s[2], field: s[3], orbit: s[4] };
        });
    }

    restart() {
        const p = this.planets[0];

        this.mode    = ORBIT;
        this.planet  = 0;
        this.from    = 0;
        this.ignore  = -1;
        this.flight  = 0;
        this.ox      = 0;
        this.oy      = -p.orbit * FP;
        this.radius  = p.orbit * FP;
        this.radialV = 0;
        this.speed   = isqrt(300 * this.radius * FP);
        this.dir     = 1;
        this.x       = p.x + this.ox;
        this.y       = p.y + this.oy;
        this.px      = this.x;
        this.py      = this.y;
        this.vx      = 0;
        this.vy      = 0;
        this.aiming  = 0;
        this.rate    = RATE_FULL;
        this.tacc    = 0;
        this.steps   = 0;
    }

    handle(code, value) {
        if (code === 0) {
            this.aiming = 1;
            return;
        }
        if (code === 2) {
            this.aiming = 0;
            return;
        }
        if (code !== 1) return;

        this.aiming = 0;
        const q = unpackPull(value);
        const v = q && launchVelocity(q[0], q[1]);
        if (!v || this.mode !== ORBIT) return;

        this.mode   = FLIGHT;
        this.vx     = v[0];
        this.vy     = v[1];
        this.from   = this.planet;
        this.ignore = this.planet;
        this.flight = 0;
    }

    update() {
        const target = this.aiming && this.mode === ORBIT ? RATE_AIM : RATE_FULL;
        const ease   = idiv((target - this.rate) * EASE, 1024);
        this.rate    = ease === 0 ? target : this.rate + ease;

        this.tacc += this.rate;
        while (this.tacc >= RATE_FULL) {
            this.tacc -= RATE_FULL;
            this.step();
            if (this.dead > 0) break;
        }
    }

    step() {
        this.px = this.x;
        this.py = this.y;
        this.steps++;

        if (this.mode === ORBIT) {
            this.stepOrbit();
            return;
        }

        const r = flightStep(this, this.planets, this.w, this.h);
        if (r === FLYING) return;

        if (r < 0) {
            this.intent(false);
            this.lose();
            return;
        }

        this.intent(r !== this.from);
        if (r !== this.from) this.score();
        this.catchOrbit(r);
    }

    /** По орбите — своей скоростью; остаток радиальной скорости после посадки плавно гаснет. */
    stepOrbit() {
        const p = this.planets[this.planet];

        this.radialV = idiv(this.radialV * SETTLE, 1024);
        this.radius  = Math.max((p.r + ORBIT_MIN_ALT) * FP, Math.min(p.field * FP, this.radius + idiv(this.radialV, STEPS)));

        // Угол за шаг, рад × S: speed / (STEPS · radius).
        const S  = 1048576;
        const th = idiv(this.speed * S, STEPS * this.radius) * this.dir;
        const t2 = idiv(th * th, S);
        const sn = th - idiv(idiv(t2 * th, S), 6);
        const cs = S - idiv(t2, 2);

        const nx = idiv(this.ox * cs - this.oy * sn, S);
        const ny = idiv(this.oy * cs + this.ox * sn, S);
        const d  = Math.max(1, ilen(nx, ny));

        this.ox = idiv(nx * this.radius, d);
        this.oy = idiv(ny * this.radius, d);
        this.x  = p.x + this.ox;
        this.y  = p.y + this.oy;
    }

    /** Траектория легла на орбиту: радиус — текущая высота, ракета сохраняет скорость. */
    catchOrbit(i) {
        const p  = this.planets[i];
        const rx = this.x - p.x, ry = this.y - p.y;
        const d  = Math.max(1, ilen(rx, ry));

        this.mode    = ORBIT;
        this.planet  = i;
        this.ignore  = -1;
        this.flight  = 0;
        this.dir     = rx * this.vy - ry * this.vx >= 0 ? 1 : -1;
        this.ox      = rx;
        this.oy      = ry;
        this.radius  = d;
        this.radialV = idiv(rx * this.vx + ry * this.vy, d);
        this.speed   = ilen(this.vx, this.vy);
    }

    dump() {
        const out = [this.w, this.h, this.mode, this.planet, this.from, this.ignore, this.flight, this.ox, this.oy,
            this.radius, this.radialV, this.speed, this.dir, this.x, this.y, this.px, this.py, this.vx, this.vy,
            this.aiming, this.rate, this.tacc, this.steps];
        for (const p of this.planets) out.push(p.x, p.y, p.r, p.field, p.orbit);
        return out;
    }
}
