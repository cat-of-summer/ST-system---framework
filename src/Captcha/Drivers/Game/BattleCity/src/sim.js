import { Sim } from '../../assets/engine/src/sim.js';
import { idiv } from '../../assets/engine/src/rng.js';

export const DX     = [0, 1, 0, -1];
export const DY     = [-1, 0, 1, 0];
export const BRICK  = 1;
export const STEEL  = 2;
export const PLAYER_PERIOD = 4;
export const ENEMY_PERIOD  = 6;
export const BULLET_PERIOD = 2;

const RESPAWN = 40;
const FIRE    = 40;
// Передышка в начале жизни, тиков: враги появляются разом и иначе встречают игрока залпом.
const CALM    = 40;

/**
 * Поле cols × rows (из params): кирпич и сталь по seed, три вражеских танка ходят и стреляют по rng.
 * Коды ввода: 0..3 — повернуть и шагнуть на клетку, а пока клавиша зажата — ехать дальше; 5 —
 * клавишу отпустили; 4 — выстрел. Шаг не чаще раза в PLAYER_PERIOD тиков: нажатие ставит его в
 * очередь, и отпустить клавишу раньше — шаг всё равно будет, а частые нажатия быстрее не везут.
 * Реакция — подбитый враг.
 *
 * Первые CALM тиков жизни враги не стреляют: они появляются разом и иначе встречают игрока залпом.
 *
 * Игрок: [x, y, направление, клавиша зажата, шаг в очереди, тиков до следующего шага].
 */
export class BattleCitySim extends Sim {

    setup() {
        const cols = this.cols = this.params.cols | 0 || 20;
        const rows = this.rows = this.params.rows | 0 || 11;
        const mid  = this.mid  = idiv(cols, 2);

        // Враги выходят из верхних углов и с середины верха, игрок — снизу по центру.
        this.spawns = [[0, 0], [mid, 0], [cols - 1, 0]];
        this.map    = [];

        for (let y = 0; y < rows; y++)
            for (let x = 0; x < cols; x++) {
                const near = x >= mid - 1 && x <= mid + 1;
                const safe = y === 0 || y === rows - 1
                    || (y === rows - 2 && near)
                    || (y === 1 && (x <= 1 || x >= cols - 2 || near));

                if (safe) { this.map.push(0); continue; }

                const r = this.rng.int(100);
                this.map.push(r < 22 ? BRICK : (r < 27 ? STEEL : 0));
            }
    }

    restart() {
        this.player  = [this.mid, this.rows - 1, 0, 0, 0, 0];
        this.enemies = this.spawns.map(function (s) { return [s[0], s[1], 2, 1, 0]; });
        this.bullets = [];
        this.clock   = 0;
    }

    handle(code) {
        const p = this.player;

        if (code >= 0 && code <= 3) {
            // Осмысленно — повернуть к ближайшему врагу.
            const e = code !== p[2] ? this.nearest() : null;
            if (e) this.intent((code === 0 && e[1] < p[1]) || (code === 1 && e[0] > p[0])
                || (code === 2 && e[1] > p[1]) || (code === 3 && e[0] < p[0]));

            p[2] = code;
            p[3] = 1;
            p[4] = 1;
        } else if (code === 5) {
            p[3] = 0;
        } else if (code === 4 && !this.armed(-1)) {
            // Осмысленно — стрелять, когда враг на линии огня; расчистка кирпича не в счёт ни так, ни так.
            const sight = this.inSight();
            if (sight !== 2) this.intent(sight === 1);
            this.bullets.push([p[0], p[1], p[2], -1]);
        }
    }

    nearest() {
        let best = null, dist = 1e9;

        for (const e of this.enemies) {
            if (!e[3]) continue;
            const d = Math.abs(e[0] - this.player[0]) + Math.abs(e[1] - this.player[1]);
            if (d < dist) { dist = d; best = e; }
        }

        return best;
    }

    /**
     * Что впереди по направлению ствола: 1 — живой враг (кирпич между не мешает, сталь мешает),
     * 2 — первым на пути кирпич и врага за ним нет, 0 — мимо.
     */
    inSight() {
        const d = this.player[2];
        let x = this.player[0], y = this.player[1], brick = false;

        for (;;) {
            x += DX[d];
            y += DY[d];
            if (x < 0 || y < 0 || x >= this.cols || y >= this.rows || this.map[y * this.cols + x] === STEEL) return brick ? 2 : 0;
            if (this.map[y * this.cols + x] === BRICK && !brick) brick = true;
            for (const e of this.enemies) if (e[3] && e[0] === x && e[1] === y) return 1;
        }
    }

    armed(owner) {
        for (const b of this.bullets) if (b[3] === owner) return true;
        return false;
    }

    free(x, y) {
        if (x < 0 || y < 0 || x >= this.cols || y >= this.rows || this.map[y * this.cols + x]) return false;
        if (this.player[0] === x && this.player[1] === y) return false;
        for (const e of this.enemies) if (e[3] && e[0] === x && e[1] === y) return false;
        return true;
    }

    update() {
        this.clock++;

        if (this.clock % BULLET_PERIOD === 0 && !this.flyBullets()) return;

        const p = this.player;

        if (p[5] > 0) p[5]--;

        if ((p[3] || p[4]) && p[5] === 0) {
            if (this.free(p[0] + DX[p[2]], p[1] + DY[p[2]])) {
                p[0] += DX[p[2]];
                p[1] += DY[p[2]];
            }
            p[4] = 0;
            p[5] = PLAYER_PERIOD;
        }

        for (let i = 0; i < this.enemies.length; i++) {
            const e = this.enemies[i];

            if (!e[3]) {
                if (--e[4] <= 0) {
                    const s = this.spawns[this.rng.int(this.spawns.length)];
                    if (this.free(s[0], s[1])) {
                        e[0] = s[0];
                        e[1] = s[1];
                        e[2] = 2;
                        e[3] = 1;
                        e[4] = 0;
                    }
                }
                continue;
            }

            if (this.clock % ENEMY_PERIOD === 0) {
                const turn = this.rng.int(4) === 0;
                if (turn || !this.free(e[0] + DX[e[2]], e[1] + DY[e[2]])) e[2] = this.rng.int(4);
                if (this.free(e[0] + DX[e[2]], e[1] + DY[e[2]])) {
                    e[0] += DX[e[2]];
                    e[1] += DY[e[2]];
                }
            }

            if (this.clock > CALM && this.rng.int(FIRE) === 0 && !this.armed(i)) this.bullets.push([e[0], e[1], e[2], i]);
        }

        const kept = [];

        for (const b of this.bullets) {
            const hit = this.hit(b);
            if (hit === 2) { this.bullets = []; this.lose(); return; }
            if (hit === 0) kept.push(b);
        }

        this.bullets = kept;
    }

    /** false — игрок подбит. */
    flyBullets() {
        const kept = [];

        for (const b of this.bullets) {
            b[0] += DX[b[2]];
            b[1] += DY[b[2]];

            if (b[0] < 0 || b[1] < 0 || b[0] >= this.cols || b[1] >= this.rows) continue;

            const tile = this.map[b[1] * this.cols + b[0]];
            if (tile === BRICK) { this.map[b[1] * this.cols + b[0]] = 0; continue; }
            if (tile === STEEL) continue;

            const hit = this.hit(b);
            if (hit === 2) { this.bullets = []; this.lose(); return false; }
            if (hit === 0) kept.push(b);
        }

        this.bullets = kept;
        return true;
    }

    /** 0 — мимо, 1 — подбит враг (пуля израсходована), 2 — подбит игрок. */
    hit(b) {
        if (b[3] < 0) {
            for (const e of this.enemies)
                if (e[3] && e[0] === b[0] && e[1] === b[1]) {
                    e[3] = 0;
                    e[4] = RESPAWN;
                    this.score();
                    return 1;
                }
            return 0;
        }

        return this.player[0] === b[0] && this.player[1] === b[1] ? 2 : 0;
    }

    dump() {
        let out = [this.cols, this.rows, this.clock].concat(this.player);
        for (const e of this.enemies) out = out.concat(e);
        out.push(this.bullets.length);
        for (const b of this.bullets) out = out.concat(b);
        return out.concat(this.map);
    }
}
