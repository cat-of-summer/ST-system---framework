import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

// Мир высотой H единиц; ширина — params.w (сервер считает её из пропорций холста), на экране
// мир масштабируется под высоту игровой зоны. Скорость и координаты птицы — в фиксированной
// точке ×256.
export const H       = 300;
export const GROUND  = 30;
export const FP      = 256;
export const BX      = 60;
export const R       = 8;
export const PW      = 40;
export const GAP     = 110;
export const SPEED   = 3;
export const SPACING = 150;

const GRAVITY = 205;
const FLAP    = -1792;
const MAX_VY  = 2560;
const FIRST   = 170;
const RESCUE  = 40;

/**
 * Код ввода 0 — взмах. Реакция — пройденная труба. Осмысленный взмах — когда птица ниже середины
 * зазора ближайшей трубы; выше — нет; далеко под зазором (спасается от земли) — не в счёт.
 * Разбилась — тоже неосмысленное действие: управление не удержало.
 */
export class FlappySim extends Sim {

    get cooldown() { return 30; }

    setup() {
        this.w = this.params.w | 0 || 533;
    }

    /** Верх зазора новой трубы. */
    gapY() {
        return 20 + this.rng.int(H - GROUND - GAP - 40);
    }

    restart() {
        this.y     = idiv(H, 2) * FP;
        this.vy    = 0;
        this.pipes = [];

        // Экран сразу заполнен трубами: в широком мире первая не должна ехать через весь экран.
        let last = -1;
        for (let x = BX + FIRST; x < this.w; x += SPACING) {
            this.pipes.push([x, this.gapY(), 0]);
            last = x;
        }

        this.dist = last < 0 ? SPACING : this.w - last;
    }

    /** Середина зазора ближайшей трубы впереди; без трубы — середина неба. */
    aim() {
        for (const pipe of this.pipes)
            if (pipe[0] + PW >= BX - R) return pipe[1] + idiv(GAP, 2);
        return idiv(H - GROUND, 2);
    }

    handle(code) {
        if (code !== 0) return;

        const y = idiv(this.y, FP), aim = this.aim();
        if (y <= aim + RESCUE) this.intent(y > aim - 10);
        this.vy = FLAP;
    }

    update() {
        this.vy = Math.min(this.vy + GRAVITY, MAX_VY);
        this.y += this.vy;

        if (this.y < R * FP) {
            this.y  = R * FP;
            this.vy = 0;
        }

        this.dist += SPEED;

        if (this.dist >= SPACING) {
            this.dist = 0;
            this.pipes.push([this.w, this.gapY(), 0]);
        }

        for (const pipe of this.pipes) {
            pipe[0] -= SPEED;

            if (!pipe[2] && pipe[0] + PW < BX - R) {
                pipe[2] = 1;
                this.score();
            }
        }

        while (this.pipes.length && this.pipes[0][0] + PW < 0) this.pipes.shift();

        const by = idiv(this.y, FP);

        if (by + R >= H - GROUND) {
            this.intent(false);
            this.lose();
            return;
        }

        for (const pipe of this.pipes)
            if (BX + R > pipe[0] && BX - R < pipe[0] + PW && (by - R < pipe[1] || by + R > pipe[1] + GAP)) {
                this.intent(false);
                this.lose();
                return;
            }
    }

    dump() {
        const out = [this.w, this.y, this.vy, this.dist, this.pipes.length];
        for (const pipe of this.pipes) out.push(pipe[0], pipe[1], pipe[2]);
        return out;
    }
}
