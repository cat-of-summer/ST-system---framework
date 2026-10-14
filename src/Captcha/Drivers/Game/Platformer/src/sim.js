import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

// Клетка — T единиц мира, координаты героя — в фиксированной точке ×256. Экран — cols × rows
// клеток из params; карта — SCREENS экранов подряд, соседние делят три клетки: монетка в конце
// экрана стоит там, где герой начинает следующий. Земля столбца c — g[c] клеток снизу
// (0 — обрыв), spikes[c] — шип на земле.
export const T       = 16;
export const FP      = 256;
export const PW      = 10;
export const PH      = 14;
export const SCREENS = 3;

const RUN     = 512;
const GRAVITY = 128;
const JUMP    = 1408;
const FALL    = 2048;
const LOW     = 2;
const HIGH    = 4;
const NEAR    = 16;
const EDGE    = 6;

/**
 * Маленький платформер: карта в три экрана генерируется из seed заранее. На каждом экране два
 * обрыва и два шипа (на широком поле больше), перед каждым не меньше двух ровных клеток, каждый
 * ровный участок на ярус выше или ниже предыдущего (высота земли 2–4) — ступени есть всегда.
 * Герой стартует слева, монетка — у правого края экрана. Монетка — реакция, и экран сдвигается
 * вправо на следующий уровень; после третьего — снова первый. Шипы и падение в обрыв — смерть,
 * жизнь начинается с начала текущего экрана.
 *
 * Коды ввода: 1 — зажали вправо, 3 — зажали влево, 5 — отпустили, 4 — прыжок (с земли).
 *
 * Осмысленно: вправо (к монетке) — да, влево — нет; прыжок на бегу не дальше клетки до
 * обрыва, шипа или ступени вверх (стоя — у самого края) — да, прыжок на ровном месте — нет.
 */
export class PlatformerSim extends Sim {

    setup() {
        this.cols   = this.params.cols | 0 || 20;
        this.rows   = this.params.rows | 0 || 11;
        this.shift  = this.cols - 3;
        this.len    = SCREENS * this.shift + 3;
        this.screen = 0;
        this.g      = [];
        this.spikes = [];
        for (let c = 0; c < this.len; c++) { this.g.push(LOW); this.spikes.push(0); }

        let h = LOW;
        for (let s = 0; s < SCREENS; s++) h = this.generate(s * this.shift, h);
    }

    /**
     * Экран с клетки base, земля в начале — на ярусе h: препятствия по порядку из seed, между
     * ними ровные участки. Возвращает ярус в конце экрана — с него начинается следующий.
     */
    generate(base, h) {
        const list = ['pit', 'pit', 'spike', 'spike'];
        for (let i = 0; i < idiv(Math.max(0, this.cols - 20), 8); i++) list.push(i % 2 === 0 ? 'pit' : 'spike');

        for (let i = list.length - 1; i > 0; i--) {
            const j = this.rng.int(i + 1);
            const t = list[i]; list[i] = list[j]; list[j] = t;
        }

        const level = [this.step(h)];
        for (let i = 0; i < list.length; i++) level.push(this.step(level[i]));

        // Перед каждым препятствием не меньше двух ровных клеток: прыжок (≈2,75 клетки) приземляется
        // на ровное, а не в следующее препятствие. Обрыв в две клетки — только если за ним не выше.
        // Последние три клетки экрана ровные: там монетка и начало следующего экрана.
        const end = base + this.cols - 3;
        let c = base + 3;

        for (let i = 0; i < list.length; i++) {
            let spare = end - c - 3 * (list.length - i);
            const run = 2 + (spare > 0 ? this.rng.int(2) : 0);
            spare -= run - 2;
            for (let k = 0; k < run; k++) this.g[c++] = level[i];

            if (list[i] === 'pit') {
                const width = 1 + (spare > 0 && level[i + 1] <= level[i] ? this.rng.int(2) : 0);
                for (let k = 0; k < width; k++) this.g[c++] = 0;
            } else {
                this.g[c] = level[i];
                this.spikes[c++] = 1;
            }
        }

        for (; c < base + this.cols; c++) this.g[c] = level[list.length];
        return level[list.length];
    }

    /** Следующий ярус: на один выше или ниже, у края диапазона — от края. */
    step(h) {
        if (h <= LOW) return h + 1;
        if (h >= HIGH) return h - 1;
        return h + (this.rng.int(2) ? 1 : -1);
    }

    /** Первая клетка текущего экрана. */
    base() {
        return this.screen * this.shift;
    }

    /** Клетка монетки текущего экрана — вторая с конца. */
    coin() {
        return this.base() + this.cols - 2;
    }

    /** Герой на второй клетке текущего экрана. */
    restart() {
        const c = this.base() + 1;
        this.x     = (c * T + 3) * FP;
        this.y     = (this.rows - this.g[c]) * T * FP;
        this.vy    = 0;
        this.dir   = 0;
        this.face  = 1;
        this.floor = 1;
    }

    /** Края текущего экрана — стены. */
    solid(c, r) {
        if (c < this.base() || c >= this.base() + this.cols) return true;
        return r >= this.rows - this.g[c] && r < this.rows;
    }

    /** Коробка героя [x, y − PH, x + PW, y) пересекает твёрдые клетки. */
    blocked(x, y) {
        const x0 = idiv(x, FP), x1 = idiv(x, FP) + PW - 1;
        const y0 = Math.max(0, idiv(y, FP) - PH), y1 = idiv(y, FP) - 1;
        if (x0 < 0) return true;

        for (let c = idiv(x0, T); c <= idiv(x1, T); c++)
            for (let r = idiv(y0, T); r <= idiv(Math.max(0, y1), T); r++)
                if (this.solid(c, r)) return true;
        return false;
    }

    /** Столбец под серединой героя. */
    column() {
        return idiv(idiv(this.x, FP) + idiv(PW, 2), T);
    }

    /**
     * Расстояние по ходу от края героя до ближайшей опасности — обрыва, шипа или ступени вверх —
     * в пределах трёх клеток; −1 — опасности нет.
     */
    danger() {
        const x0   = idiv(this.x, FP);
        const here = this.column();
        const cf   = this.face > 0 ? idiv(x0 + PW - 1, T) : idiv(x0, T);

        for (let k = 0; k <= 3; k++) {
            const n = cf + k * this.face;
            if (n < this.base() || n >= this.base() + this.cols) return -1;
            if (n === here || !(this.g[n] === 0 || this.spikes[n] || this.g[n] > this.g[here])) continue;
            return Math.max(0, this.face > 0 ? n * T - x0 - PW : x0 - n * T - T);
        }
        return -1;
    }

    handle(code) {
        if (code === 1 || code === 3) {
            const dir = code === 1 ? 1 : -1;
            if (dir === this.dir) return;

            this.intent(dir > 0);
            this.dir  = dir;
            this.face = dir;
        } else if (code === 5) {
            this.dir = 0;
        } else if (code === 4 && this.floor) {
            const d = this.danger();
            this.intent(d >= 0 && d <= (this.dir === this.face ? NEAR : EDGE));
            this.vy    = -JUMP;
            this.floor = 0;
        }
    }

    update() {
        if (this.dir) {
            const nx = this.x + this.dir * RUN;
            if (!this.blocked(nx, this.y)) this.x = nx;
        }

        // Стоит на земле — гравитация не сдвигает его, иначе каждый второй тик он «в воздухе».
        const standing = this.vy >= 0 && this.blocked(this.x, this.y + FP);
        if (!standing) this.vy = Math.min(FALL, this.vy + GRAVITY);
        const ny = this.y + this.vy;

        if (standing) {
            this.vy    = 0;
            this.floor = 1;
        } else if (!this.blocked(this.x, ny)) {
            this.y     = ny;
            this.floor = 0;
        } else if (this.vy > 0) {
            // Приземлился: ступни на верх клетки, в которую упёрся.
            this.y     = idiv(idiv(ny, FP) - 1, T) * T * FP;
            this.vy    = 0;
            this.floor = 1;
        } else {
            this.vy = 0;
        }

        if (idiv(this.y, FP) - PH > this.rows * T) { this.lose(); return; }

        const px0 = idiv(this.x, FP), py1 = idiv(this.y, FP);

        for (let c = idiv(px0, T); c <= idiv(px0 + PW - 1, T); c++) {
            if (!this.spikes[c]) continue;
            const top = (this.rows - this.g[c]) * T;
            if (px0 + PW > c * T + 3 && px0 < c * T + T - 3 && py1 > top - 9) { this.lose(); return; }
        }

        // Монетка: очко и следующий экран. Герой остаётся на месте — это вторая клетка нового
        // экрана; после последнего экрана — снова первый.
        const coin = this.coin(), cy = (this.rows - this.g[coin] - 1) * T;
        if (px0 + PW > coin * T + 3 && px0 < coin * T + T - 3 && py1 > cy + 3 && py1 - PH < cy + T - 3) {
            this.score();
            this.screen = (this.screen + 1) % SCREENS;
            if (this.screen === 0) this.restart();
        }
    }

    dump() {
        return [this.cols, this.rows, this.screen, this.x, this.y, this.vy, this.dir, this.face, this.floor]
            .concat(this.g, this.spikes);
    }
}
