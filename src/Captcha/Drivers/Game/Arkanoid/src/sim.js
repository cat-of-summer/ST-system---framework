import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

// Мир высотой H единиц; ширина — params.w (сервер считает её из пропорций холста), на экране
// мир масштабируется под высоту игровой зоны. Мяч — в фиксированной точке ×256.
export const H      = 280;
export const FP     = 256;
export const PY     = H - 24;
export const PW     = 48;
export const PH     = 8;
export const R      = 4;
export const ROWS   = 4;
export const BW     = 26;
export const BH     = 10;
export const BGAP   = 2;
export const BY0    = 22;

const PSPEED = 8;
const V      = 1536;
const LAUNCH = [-1024, -768, 768, 1024];

/** Кирпичей в ряду и отступ слева — под ширину мира. */
export function brickLayout(w) {
    const cols = Math.max(4, idiv(w - 16 + BGAP, BW + BGAP));
    return { cols: cols, x0: idiv(w - cols * (BW + BGAP) + BGAP, 2) };
}

/**
 * Код ввода 0 — куда вести ракетку (value — x центра, единицы мира); ракетка едет туда
 * не быстрее PSPEED за тик. Любой ввод на старте жизни запускает мяч.
 * Реакция — мяч отбит ракеткой. broken — разбитые за партию кирпичи: это счёт на экране.
 */
export class ArkanoidSim extends Sim {

    setup() {
        this.w      = this.params.w | 0 || 498;
        this.layout = brickLayout(this.w);
        this.px     = idiv(this.w, 2);
        this.target = this.px;
        this.broken = 0;
        this.fill();
    }

    fill() {
        let count = 0;
        this.bricks = [];

        for (let i = 0; i < ROWS * this.layout.cols; i++) {
            const on = this.rng.int(10) < 7 ? 1 : 0;
            this.bricks.push(on);
            count += on;
        }

        if (count === 0) this.bricks[0] = 1;
    }

    restart() {
        this.vx = 0;
        this.vy = 0;
        this.bx = this.px * FP;
        this.by = (PY - R) * FP;
    }

    start() {
        this.vx = LAUNCH[this.rng.int(4)];
        this.vy = -V;
        this.bx = this.px * FP;
        this.by = (PY - R) * FP;
    }

    handle(code, value) {
        if (code !== 0) return;

        const target = Math.max(PW / 2, Math.min(this.w - PW / 2, value));

        // Осмысленно — пока мяч летит вниз, вести ракетку к нему.
        if (this.vy > 0 && target !== this.target) {
            const bx = idiv(this.bx, FP);
            this.intent(Math.abs(target - bx) < Math.abs(this.target - bx));
        }

        this.target = target;
    }

    update() {
        const d = this.target - this.px;
        this.px += d > PSPEED ? PSPEED : (d < -PSPEED ? -PSPEED : d);

        const ox = this.bx;
        const oy = this.by;

        this.bx += this.vx;
        this.by += this.vy;

        if (this.bx < R * FP) {
            this.bx = R * FP;
            this.vx = Math.abs(this.vx);
        } else if (this.bx > (this.w - R) * FP) {
            this.bx = (this.w - R) * FP;
            this.vx = -Math.abs(this.vx);
        }

        if (this.by < R * FP) {
            this.by = R * FP;
            this.vy = Math.abs(this.vy);
        }

        const cols = this.layout.cols;

        for (let i = 0; i < ROWS * cols; i++) {
            if (!this.bricks[i]) continue;

            const x0 = (this.layout.x0 + (i % cols) * (BW + BGAP)) * FP;
            const y0 = (BY0 + idiv(i, cols) * (BH + BGAP)) * FP;
            const x1 = x0 + BW * FP;
            const y1 = y0 + BH * FP;

            if (this.bx + R * FP > x0 && this.bx - R * FP < x1 && this.by + R * FP > y0 && this.by - R * FP < y1) {
                this.bricks[i] = 0;
                this.broken++;

                if (ox + R * FP > x0 && ox - R * FP < x1) this.vy = -this.vy;
                else this.vx = -this.vx;

                if (this.bricks.indexOf(1) < 0) this.fill();
                break;
            }
        }

        const top = PY * FP;

        if (this.vy > 0 && oy + R * FP <= top && this.by + R * FP >= top
            && this.bx >= (this.px - PW / 2 - R) * FP && this.bx <= (this.px + PW / 2 + R) * FP) {
            this.by = top - R * FP;
            this.vy = -V;
            this.vx = idiv((this.bx - this.px * FP) * 5, PW / 2 + R);
            if (this.vx > -192 && this.vx < 192) this.vx = this.vx < 0 ? -192 : 192;
            this.score();
        }

        if (this.by > H * FP) this.lose();
    }

    dump() {
        return [this.w, this.broken, this.px, this.target, this.bx, this.by, this.vx, this.vy].concat(this.bricks);
    }
}
