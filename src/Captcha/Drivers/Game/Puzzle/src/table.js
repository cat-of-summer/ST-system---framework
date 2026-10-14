import { Rng } from '../../assets/engine/src/rng.js';
import { COLORS, HUD } from '../../assets/engine/src/driver.js';
import { cellAt } from './board.js';
import { SLOT_BITS } from './sim.js';

const GAP = 6;

/**
 * Стол пазла: поле и рядом лоток с разбросанными кусочками — справа или снизу, как решил сервер
 * (cfg.tray) под размер холста. Здесь всё, что касается только экрана:
 * где лежит кусочек на столе, что тащат, что выбрано с клавиатуры. В лог уходят лишь «поставить
 * в ячейку» и «убрать на стол» — то, что меняет сборку.
 *
 * Форма кусочков вырезана сервером в спрайте: клиент рисует их как есть и формы не знает.
 * Закреплённые кусочки (cfg.params.fixed, лёгкий уровень) стоят на поле с начала: их не взять,
 * в их ячейки не поставить, в лотке их нет.
 */
export function puzzleTable(api) {
    const cfg   = api.cfg;
    const W     = api.W;
    const H     = api.H;
    const rows  = cfg.params.rows | 0;
    const cols  = cfg.params.cols | 0;
    const n     = rows * cols;
    const bw    = cfg.board[0];
    const bh    = cfg.board[1];
    const cw    = bw / cols;
    const ch    = bh / rows;
    const D     = cfg.density || 1;
    const sb    = cfg.bleed | 0;
    const b     = sb / D;
    const right = cfg.tray !== 'below';

    const locked = [];
    for (let k = 0; k < n; k++) locked.push(0);
    for (const f of cfg.params.fixed || []) locked[f[0]] = 1;
    const loose = [];
    for (let k = 0; k < n; k++) if (!locked[k]) loose.push(k);

    // Поле — у края холста со стороны, противоположной лотку; язычки остаются внутри холста.
    const bx = right ? Math.round(b) : Math.round((W - bw) / 2);
    const by = right ? HUD + Math.round((H - HUD - bh) / 2) : HUD + Math.round(b);

    // Где в лотке могут лежать клетки кусочков: от язычков поля через зазор до края холста.
    const tx0 = right ? bx + bw + 2 * b + GAP : b;
    const ty0 = right ? HUD + b : by + bh + 2 * b + GAP;
    const tx1 = W - b;
    const ty1 = H - b;

    const sprite = new Image();
    let ready = false;
    sprite.onload = function () { ready = true; };
    sprite.src = cfg.sprite;

    let pos, z, drag, sel, held, cursor, keyboard;

    /** Разброс кусочков по столу — от seed выдачи, одинаково при каждом сбросе. */
    function scatter() {
        const rng   = new Rng((cfg.seed ^ 0xA5A5A5A5) >>> 0);
        const fit   = Math.max(1, Math.floor((tx1 - tx0 + GAP) / (cw + GAP)));
        const lines = Math.ceil(loose.length / fit);
        const per   = Math.ceil(loose.length / lines);

        // Кусочки равномерно по всему лотку — чем свободнее, тем дальше друг от друга.
        const sx = per > 1 ? (tx1 - tx0 - cw) / (per - 1) : 0;
        const sy = lines > 1 ? (ty1 - ty0 - ch) / (lines - 1) : 0;
        const x0 = per > 1 ? tx0 : (tx0 + tx1 - cw) / 2;
        const y0 = lines > 1 ? ty0 : (ty0 + ty1 - ch) / 2;

        z = loose.slice();
        for (let i = z.length - 1; i > 0; i--) {
            const j = rng.int(i + 1);
            const t = z[i]; z[i] = z[j]; z[j] = t;
        }

        pos = [];
        z.forEach(function (k, i) {
            pos[k] = {
                x: clampX(x0 + (i % per) * sx + rng.int(9) - 4),
                y: clampY(y0 + Math.floor(i / per) * sy + rng.int(9) - 4),
            };
        });

        drag = null;
        sel = loose[0];
        held = false;
        cursor = 0;
        keyboard = false;
    }

    function clampX(x) { return Math.max(b / 2, Math.min(W - cw - b / 2, x)); }
    function clampY(y) { return Math.max(HUD + b / 2, Math.min(H - ch - b / 2, y)); }

    function slotAt(x, y) { return cellAt(x - bx, y - by, rows, cols, bw, bh); }

    function slotRect(s) { return [bx + (s % cols) * cw, by + Math.floor(s / cols) * ch]; }

    function toTop(k) { z = z.filter(function (i) { return i !== k; }); z.push(k); }

    /** Кусочек под точкой: сначала лежащие на столе (сверху вниз), потом стоящие на поле. */
    function pieceAt(x, y) {
        const sim = api.sim();

        for (let i = z.length - 1; i >= 0; i--) {
            const k = z[i];
            if (sim.where[k] >= 0) continue;
            if (x >= pos[k].x && x < pos[k].x + cw && y >= pos[k].y && y < pos[k].y + ch) return k;
        }

        const s = slotAt(x, y);
        return s >= 0 && sim.slots[s] >= 0 && !locked[sim.slots[s]] ? sim.slots[s] : -1;
    }

    /** Ячейка s занята закреплённым кусочком — туда не поставить. */
    function fixedSlot(s) {
        const k = api.sim().slots[s];
        return k >= 0 && locked[k] === 1;
    }

    /** Следующий свободный кусочек для выбора с клавиатуры: шаг step по списку незакреплённых. */
    function nextLoose(k, step) {
        const i = loose.indexOf(k);
        return loose[(i + step + loose.length) % loose.length];
    }

    /** Поставить k в ячейку s; вытесненный со стола кусочек ложится на место k. */
    function place(k, s) {
        const sim      = api.sim();
        const occupant = sim.slots[s];

        if (fixedSlot(s)) return;
        if (occupant >= 0 && occupant !== k && sim.where[k] < 0) pos[occupant] = { x: pos[k].x, y: pos[k].y };

        api.record(0, k * SLOT_BITS + s);
    }

    function unplace(k, x, y) {
        pos[k] = { x: clampX(x), y: clampY(y) };
        toTop(k);
        if (api.sim().where[k] >= 0) api.record(1, k);
    }

    // --- отрисовка ------------------------------------------------------------------------------

    function cellSource(k) {
        return [(k % cols) * (cw * D + 2 * sb), Math.floor(k / cols) * (ch * D + 2 * sb)];
    }

    /**
     * Кусочек целиком: форма с язычками и выемками уже вырезана в спрайте прозрачностью, тень
     * повторяет контур. (x, y) — угол клетки кусочка, язычки выходят за неё на b.
     */
    function drawPiece(ctx, k, x, y, shadow) {
        const src = cellSource(k);

        ctx.save();
        ctx.shadowColor   = 'rgba(0,0,0,' + (shadow > 4 ? 0.6 : 0.45) + ')';
        ctx.shadowBlur    = shadow;
        ctx.shadowOffsetY = shadow > 4 ? 4 : 1;
        ctx.drawImage(sprite, src[0], src[1], cw * D + 2 * sb, ch * D + 2 * sb, x - b, y - b, cw + 2 * b, ch + 2 * b);
        ctx.restore();
    }

    function frame(ctx, x, y, color, dash) {
        ctx.save();
        ctx.setLineDash(dash || []);
        ctx.strokeStyle = color;
        ctx.lineWidth = 2.5;
        ctx.strokeRect(x - 1, y - 1, cw + 2, ch + 2);
        ctx.restore();
    }

    function mark(ctx, k, color) {
        const at = api.sim().where[k] >= 0 ? slotRect(api.sim().where[k]) : [pos[k].x, pos[k].y];
        frame(ctx, at[0], at[1], color);
    }

    function render(ctx, sim) {
        ctx.fillStyle = COLORS.bg;
        ctx.fillRect(0, HUD, W, H - HUD);
        ctx.fillStyle = COLORS.board;
        ctx.fillRect(bx - 6, by - 6, bw + 12, bh + 12);

        // Сетка поля: куда класть. Формы в ней нет — форма у кусочков.
        ctx.save();
        ctx.setLineDash([3, 4]);
        ctx.strokeStyle = 'rgba(230,238,248,0.18)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        for (let c = 0; c <= cols; c++) { ctx.moveTo(bx + c * cw + 0.5, by); ctx.lineTo(bx + c * cw + 0.5, by + bh); }
        for (let r = 0; r <= rows; r++) { ctx.moveTo(bx, by + r * ch + 0.5); ctx.lineTo(bx + bw, by + r * ch + 0.5); }
        ctx.stroke();
        ctx.restore();

        if (!ready) return;

        for (let s = 0; s < n; s++) {
            const k = sim.slots[s];
            if (k < 0 || (drag && drag.k === k)) continue;
            const at = slotRect(s);
            drawPiece(ctx, k, at[0], at[1], 2);
        }

        for (const k of z)
            if (sim.where[k] < 0 && !(drag && drag.k === k)) drawPiece(ctx, k, pos[k].x, pos[k].y, 5);

        if (keyboard) {
            mark(ctx, sel, COLORS.yellow);
            if (held) {
                const at = slotRect(cursor);
                frame(ctx, at[0], at[1], COLORS.blue, [6, 4]);
            }
        }

        if (drag) {
            const s = slotAt(drag.x, drag.y);
            if (s >= 0) {
                const at = slotRect(s);
                frame(ctx, at[0], at[1], fixedSlot(s) ? COLORS.red : COLORS.green);
            }
            drawPiece(ctx, drag.k, drag.x - drag.ox, drag.y - drag.oy, 14);
        }
    }

    scatter();

    return {
        render: render,
        reset: scatter,

        press: function (x, y) {
            const k = pieceAt(x, y);
            if (k < 0) return;

            const sim  = api.sim();
            const from = sim.where[k];
            const at   = from >= 0 ? slotRect(from) : [pos[k].x, pos[k].y];

            keyboard = false;
            drag = { k: k, ox: x - at[0], oy: y - at[1], x: x, y: y };
            toTop(k);
        },

        move: function (x, y) {
            if (!drag) return;
            drag.x = x;
            drag.y = y;
        },

        up: function (x, y, ok) {
            if (!drag) return;

            const d = drag;
            drag = null;
            if (!ok) return;

            const s = slotAt(x, y);
            if (s >= 0 && !fixedSlot(s)) place(d.k, s);
            else if (s < 0) unplace(d.k, x - d.ox, y - d.oy);
        },

        key: function (key, down) {
            if (!down) return key.indexOf('Arrow') === 0 || key === ' ' || key === 'Enter' || key === 'Escape';

            const sim = api.sim();
            const r = Math.floor(cursor / cols), c = cursor % cols;

            keyboard = true;

            switch (key) {
                case 'ArrowLeft':
                case 'ArrowRight':
                    if (held) cursor = key === 'ArrowLeft' ? (c > 0 ? cursor - 1 : cursor) : (c < cols - 1 ? cursor + 1 : cursor);
                    else sel = nextLoose(sel, key === 'ArrowLeft' ? -1 : 1);
                    return true;
                case 'ArrowUp':
                    if (held && r > 0) cursor -= cols;
                    else if (!held) sel = nextLoose(sel, -1);
                    return true;
                case 'ArrowDown':
                    if (held && r < rows - 1) cursor += cols;
                    else if (!held) sel = nextLoose(sel, 1);
                    return true;
                case ' ':
                case 'Enter':
                    if (!held) {
                        held = true;
                        cursor = sim.where[sel] >= 0 ? sim.where[sel] : 0;
                    } else {
                        held = false;
                        place(sel, cursor);
                    }
                    return true;
                case 'Escape':
                    held = false;
                    return true;
                case 'Backspace':
                case 'Delete':
                    if (!held && sim.where[sel] >= 0) unplace(sel, pos[sel].x, pos[sel].y);
                    return true;
            }

            return false;
        },

        release: function () {
            drag = null;
            held = false;
        },
    };
}
