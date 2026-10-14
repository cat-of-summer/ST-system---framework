import { Sim } from '../../assets/engine/src/sim.js';

/** Фазы: показ всех карточек, игра, промах на виду, поле собрано. */
export const PREVIEW = 0, PLAY = 1, MISS = 2, WON = 3;

/** Сколько тиков (10 в секунду) промах остаётся открытым и сколько длится пауза после собранного поля. */
export const MISS_TICKS = 8;
export const WIN_PAUSE  = 20;

/** Карточка: лицом вниз, открыта, пара найдена. */
export const DOWN = 0, UP = 1, FOUND = 2;

/**
 * Memory: pairs пар карточек, расклад тасуется от rng. После START все карточки открываются на show
 * тиков, затем закрываются — пары нужно открывать по памяти. Код ввода 0, value — номер карточки.
 *
 * Открыта вторая карточка: совпала — пара остаётся открытой, это реакция; не совпала — обе видны
 * MISS_TICKS тиков (следующий клик закрывает их сразу), жизнь − 1. Жизни кончились — смерть, новая
 * жизнь — новый расклад и новый показ. Все пары найдены — solved + 1, через паузу новый расклад.
 *
 * Действие — вторая карточка пары; осмысленно, если пара совпала. Случайный выбор угадывает
 * примерно 1 / (2 · pairs − 1).
 */
export class MemorySim extends Sim {

    setup() {
        this.pairs  = Math.max(2, Math.min(20, this.params.pairs | 0 || 8));
        this.n      = this.pairs * 2;
        this.max    = Math.max(1, this.params.lives | 0 || 4);
        this.show   = Math.max(1, this.params.show | 0 || 50);
        this.solved = 0;
    }

    restart() {
        this.lives = this.max;
        this.deal();
    }

    /** Новый расклад лицом вниз: Фишер–Йетс по парам 0, 0, 1, 1, … */
    deal() {
        this.deck = [];
        this.face = [];
        for (let i = 0; i < this.n; i++) {
            this.deck.push(i >> 1);
            this.face.push(DOWN);
        }
        for (let i = this.n - 1; i > 0; i--) {
            const j = this.rng.int(i + 1);
            const t = this.deck[i]; this.deck[i] = this.deck[j]; this.deck[j] = t;
        }

        this.phase = PREVIEW;
        this.timer = this.show;
        this.first = -1;
        this.missA = -1;
        this.missB = -1;
        this.found = 0;
    }

    /** Показ начинается с первым вводом — до него карточки закрыты. */
    start() {
        this.reveal(UP);
    }

    reveal(state) {
        for (let i = 0; i < this.n; i++) this.face[i] = state;
    }

    /** Промах закрывается. */
    hide() {
        this.face[this.missA] = DOWN;
        this.face[this.missB] = DOWN;
        this.missA = this.missB = -1;
        this.phase = PLAY;
    }

    handle(code, value) {
        if (code !== 0 || value < 0 || value >= this.n) return;
        if (this.phase === MISS) this.hide();
        if (this.phase !== PLAY || this.face[value] !== DOWN) return;

        this.face[value] = UP;

        if (this.first < 0) {
            this.first = value;
            return;
        }

        const a = this.first;
        this.first = -1;

        if (this.deck[a] === this.deck[value]) {
            this.intent(true);
            this.face[a] = this.face[value] = FOUND;
            this.score();

            if (++this.found === this.pairs) {
                this.solved++;
                this.phase = WON;
                this.timer = WIN_PAUSE;
            }
            return;
        }

        this.intent(false);
        this.missA = a;
        this.missB = value;
        this.phase = MISS;
        this.timer = MISS_TICKS;

        if (--this.lives === 0) this.lose();
    }

    update() {
        if (this.phase === PREVIEW) {
            if (--this.timer === 0) {
                this.reveal(DOWN);
                this.phase = PLAY;
            }
        } else if (this.phase === MISS) {
            if (--this.timer === 0) this.hide();
        } else if (this.phase === WON) {
            if (--this.timer === 0) {
                this.deal();
                this.start();
            }
        }
    }

    dump() {
        return [this.pairs, this.lives, this.solved, this.phase, this.timer, this.first, this.missA, this.missB, this.found]
            .concat(this.deck, this.face);
    }
}
