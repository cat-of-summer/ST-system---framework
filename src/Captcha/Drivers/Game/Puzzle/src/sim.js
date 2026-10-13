import { Sim } from '../../assets/engine/src/sim.js';

/** Кодирование хода «кусочек k в ячейку s»: value = k × 64 + s. */
export const SLOT_BITS = 64;

/**
 * Пазл: кусочки лежат на столе, их ставят в ячейки поля. Коды ввода: 0 — поставить кусочек
 * (value = k × 64 + s), 1 — убрать кусочек k с поля на стол. Занятая ячейка освобождается: её
 * кусочек уходит туда, откуда пришёл новый (на стол или в его прежнюю ячейку).
 *
 * params: rows, cols и — только у сервера — order: в ячейке спрайта k кусочек order[k], то есть
 * его место — ячейка поля order[k]. Без order (в браузере) реакции не считаются: собрано ли,
 * клиент не знает. Реакции — сколько кусочков стоит на своих местах; цель — все.
 */
export class PuzzleSim extends Sim {

    get cooldown() { return 1; }

    setup() {
        this.rows  = this.params.rows | 0;
        this.cols  = this.params.cols | 0;
        this.n     = this.rows * this.cols;
        this.order = this.params.order || null;
        this.slots = [];
        this.where = [];

        for (let i = 0; i < this.n; i++) {
            this.slots.push(-1);
            this.where.push(-1);
        }
    }

    handle(code, value) {
        if (code === 0 && value >= 0) {
            const k = Math.floor(value / SLOT_BITS);
            const s = value % SLOT_BITS;
            if (k < this.n && s < this.n) this.place(k, s);
        } else if (code === 1 && value >= 0 && value < this.n && this.where[value] >= 0) {
            this.slots[this.where[value]] = -1;
            this.where[value] = -1;
            this.recount();
        }
    }

    place(k, s) {
        const from     = this.where[k];
        const occupant = this.slots[s];

        if (from === s) return;

        if (from >= 0) this.slots[from] = -1;

        if (occupant >= 0) {
            this.where[occupant] = from;
            if (from >= 0) this.slots[from] = occupant;
        }

        this.slots[s] = k;
        this.where[k] = s;

        // Осмысленно — поставить кусочек на его место; считает только сервер, он знает order.
        if (this.order) this.intent(this.order[k] === s);

        this.recount();
    }

    recount() {
        if (!this.order) return;

        let placed = 0;
        for (let s = 0; s < this.n; s++)
            if (this.slots[s] >= 0 && this.order[this.slots[s]] === s) placed++;

        this.reactions = placed;
        if (placed > this.peak) this.peak = placed;
    }

    /** Все ячейки заняты — ответ можно отправлять. */
    filled() {
        let count = 0;
        for (let s = 0; s < this.n; s++) if (this.slots[s] >= 0) count++;
        return count;
    }

    dump() {
        return this.slots.concat(this.where);
    }
}
