import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { MinesweeperSim } from './sim.js';
import { minesControls, HOLD_MS } from './controls.js';

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

// Цифры — классические цвета, осветлённые под тёмное поле.
const DIGITS = ['', '#60a5fa', '#34d399', '#fb7185', '#a78bfa', '#f59e0b', '#22d3ee', '#f472b6', '#e2e8f0'];

const REVEAL_MS = 220;
const STAGGER   = 35;

function rounded(ctx, x, y, w, h, r) {
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(x, y, w, h, r);
    else ctx.rect(x, y, w, h);
}

function easeOutBack(k) {
    const c = 1.70158;
    return 1 + (c + 1) * Math.pow(k - 1, 3) + c * Math.pow(k - 1, 2);
}

function easeOutBounce(k) {
    if (k < 1 / 2.75) return 7.5625 * k * k;
    if (k < 2 / 2.75) return 7.5625 * (k -= 1.5 / 2.75) * k + 0.75;
    if (k < 2.5 / 2.75) return 7.5625 * (k -= 2.25 / 2.75) * k + 0.9375;
    return 7.5625 * (k -= 2.625 / 2.75) * k + 0.984375;
}

/** Закрытая клетка: выпуклая плитка с фаской; hot — под курсором, down — нажата. */
function tile(ctx, x, y, s, hot, down) {
    const r = s * 0.14, b = Math.max(1.5, s * 0.08);

    const g = ctx.createLinearGradient(x, y, x + s, y + s);
    g.addColorStop(0, hot ? '#52627e' : '#435371');
    g.addColorStop(1, hot ? '#36445e' : '#2b374e');
    ctx.fillStyle = g;
    rounded(ctx, x + 1, y + 1, s - 2, s - 2, r);
    ctx.fill();

    if (down) {
        ctx.fillStyle = 'rgba(0,0,0,0.25)';
        rounded(ctx, x + 1, y + 1, s - 2, s - 2, r);
        ctx.fill();
        return;
    }

    // Фаска: светлый верх и левый край, тёмный низ и правый.
    ctx.save();
    rounded(ctx, x + 1, y + 1, s - 2, s - 2, r);
    ctx.clip();
    ctx.fillStyle = 'rgba(255,255,255,0.16)';
    ctx.fillRect(x, y, s, b);
    ctx.fillRect(x, y, b, s);
    ctx.fillStyle = 'rgba(0,0,0,0.32)';
    ctx.fillRect(x, y + s - b - 1, s, b + 1);
    ctx.fillRect(x + s - b - 1, y, b + 1, s);
    ctx.restore();
}

/** Открытая клетка: утопленная, с цифрой. pop — появление цифры 0…1. */
function well(ctx, x, y, s, num, pop, red) {
    ctx.fillStyle = red ? '#7f1d1d' : '#0d1628';
    rounded(ctx, x + 1, y + 1, s - 2, s - 2, s * 0.1);
    ctx.fill();
    ctx.strokeStyle = 'rgba(148,163,184,0.10)';
    ctx.lineWidth = 1;
    ctx.stroke();

    if (!num || pop <= 0) return;

    ctx.save();
    ctx.translate(x + s / 2, y + s / 2 + s * 0.03);
    const k = easeOutBack(Math.min(1, pop));
    ctx.scale(k, k);
    ctx.font = '800 ' + Math.round(s * 0.58) + 'px ' + FONT;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.shadowColor = DIGITS[num];
    ctx.shadowBlur = s * 0.25;
    ctx.fillStyle = DIGITS[num];
    ctx.fillText(String(num), 0, 0);
    ctx.restore();
}

/** Флажок на клетке: полотнище колышется; drop — втыкание сверху с подскоком (0…1); wrong — перечёркнут. */
function flag(ctx, x, y, s, now, drop, wrong) {
    const cx = x + s * 0.5, base = y + s * 0.8;
    const lift = (1 - easeOutBounce(Math.min(1, drop))) * s * 0.6;

    ctx.save();
    ctx.translate(0, -lift);
    ctx.globalAlpha = Math.min(1, 0.3 + drop * 2);

    ctx.fillStyle = 'rgba(0,0,0,0.35)';
    ctx.beginPath();
    ctx.ellipse(cx, base + lift, s * 0.2, s * 0.06, 0, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = '#cbd5e1';
    ctx.fillRect(cx - s * 0.03, y + s * 0.18, s * 0.06, base - y - s * 0.18);

    // Полотнище: три точки края волнуются со сдвигом фазы.
    const top = y + s * 0.18, h = s * 0.32, w = s * 0.34;
    const wave = function (i) { return Math.sin(now / 170 + i * 1.3) * s * 0.035; };
    const g = ctx.createLinearGradient(cx, top, cx + w, top + h);
    g.addColorStop(0, '#f43f5e');
    g.addColorStop(1, '#be123c');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.moveTo(cx + s * 0.03, top);
    ctx.quadraticCurveTo(cx + w * 0.5, top + wave(0), cx + w, top + h * 0.5 + wave(1));
    ctx.quadraticCurveTo(cx + w * 0.5, top + h + wave(2), cx + s * 0.03, top + h);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    if (wrong) {
        ctx.strokeStyle = '#fde047';
        ctx.lineWidth = Math.max(1.5, s * 0.08);
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(x + s * 0.22, y + s * 0.22); ctx.lineTo(x + s * 0.78, y + s * 0.78);
        ctx.moveTo(x + s * 0.78, y + s * 0.22); ctx.lineTo(x + s * 0.22, y + s * 0.78);
        ctx.stroke();
    }
}

/** Мина: шар с шипами и бликом; k — масштаб появления. */
function mine(ctx, x, y, s, k) {
    if (k <= 0) return;

    const cx = x + s / 2, cy = y + s / 2, r = s * 0.22;
    ctx.save();
    ctx.translate(cx, cy);
    ctx.scale(k, k);

    ctx.strokeStyle = '#94a3b8';
    ctx.lineWidth = Math.max(1, s * 0.07);
    ctx.lineCap = 'round';
    ctx.beginPath();
    for (let i = 0; i < 8; i++) {
        const a = i * Math.PI / 4;
        ctx.moveTo(Math.cos(a) * r * 0.6, Math.sin(a) * r * 0.6);
        ctx.lineTo(Math.cos(a) * r * 1.5, Math.sin(a) * r * 1.5);
    }
    ctx.stroke();

    const g = ctx.createRadialGradient(-r * 0.35, -r * 0.35, r * 0.1, 0, 0, r);
    g.addColorStop(0, '#cbd5e1');
    g.addColorStop(1, '#1e293b');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(0, 0, r, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = 'rgba(255,255,255,0.8)';
    ctx.beginPath();
    ctx.arc(-r * 0.35, -r * 0.35, r * 0.18, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
}

/**
 * Вид: всё, что знает только экран, — волна открытия, втыкание флажка, взрыв, частицы. Ввод —
 * в controls.js; от него здесь клетка под курсором, курсор клавиатуры и нажатая клетка.
 */
function minesView(api) {
    const input = minesControls(api);
    const ui    = input.ui;
    const g     = ui.g, cols = ui.cols, rows = ui.rows;

    // Только для вида: когда клетка открылась и флажок воткнулся, взрыв, частицы.
    const fx = { open: [], flag: [], openAt: [], flagAt: [], boomAt: 0, parts: [], wonAt: 0, last: 0 };

    function at(c) {
        return [g.x + (c % cols) * g.s, g.y + Math.floor(c / cols) * g.s];
    }

    /** Новые открытые клетки и флажки — с моментом появления; новое поле — всё с нуля. */
    function track(sim, now) {
        for (let c = 0; c < sim.n; c++) {
            if (sim.open[c] && !fx.open[c]) {
                const o = ui.origin >= 0 ? ui.origin : c;
                const d = Math.hypot(c % cols - o % cols, Math.floor(c / cols) - Math.floor(o / cols));
                fx.openAt[c] = Math.max(now, ui.originAt) + d * STAGGER;
            }
            if (sim.flag[c] && !fx.flag[c]) fx.flagAt[c] = now;
            fx.open[c] = sim.open[c];
            fx.flag[c] = sim.flag[c];
        }
    }

    function explode(sim, now) {
        const p = at(sim.boom), cx = p[0] + g.s / 2, cy = p[1] + g.s / 2;
        fx.boomAt = now;

        for (let i = 0; i < 26; i++) {
            const a = (i / 26) * Math.PI * 2 + (i % 3) * 0.4, v = 0.06 + (i % 5) * 0.035;
            fx.parts.push({ x: cx, y: cy, vx: Math.cos(a) * v, vy: Math.sin(a) * v - 0.05, born: now, life: 500 + (i % 4) * 150,
                r: 1 + (i % 3) * 0.8, color: ['#fde047', '#fb923c', '#f97316', '#94a3b8'][i % 4], g: 0.00025 });
        }
        for (let i = 0; i < 8; i++)
            fx.parts.push({ x: cx + (i - 4) * 2, y: cy, vx: (i - 4) * 0.006, vy: -0.015 - (i % 3) * 0.008, born: now, life: 900,
                r: g.s * 0.2, color: 'smoke', g: 0 });
    }

    function confetti(now) {
        fx.wonAt = now;
        for (let i = 0; i < 40; i++)
            fx.parts.push({ x: g.x + (i * 37 % (cols * g.s)), y: g.y - 4, vx: ((i * 13) % 7 - 3) * 0.01, vy: 0.02 + (i % 5) * 0.012,
                born: now, life: 1400, r: 1.6, color: [COLORS.green, COLORS.yellow, COLORS.blue, COLORS.red, COLORS.purple][i % 5], g: 0.00008, spin: i });
    }

    function render(ctx, sim, view) {
        const now = view.now;
        const dt  = fx.last ? Math.min(50, now - fx.last) : 0;
        fx.last = now;

        if (sim.placed === 0 && sim.opened === 0) { fx.open = []; fx.flag = []; fx.openAt = []; fx.flagAt = []; }
        track(sim, now);

        if (sim.dead > 0 && sim.boom >= 0 && !fx.boomAt) explode(sim, now);
        if (!sim.dead) fx.boomAt = 0;
        if (sim.won && !fx.wonAt) confetti(now);
        if (!sim.won) fx.wonAt = 0;

        // Фон и тряска при взрыве.
        ctx.fillStyle = COLORS.bg;
        ctx.fillRect(0, HUD, view.W, view.H - HUD);

        ctx.save();
        const shake = fx.boomAt ? Math.max(0, 1 - (now - fx.boomAt) / 350) : 0;
        if (shake) ctx.translate(Math.sin(now / 17) * 3 * shake, Math.cos(now / 23) * 3 * shake);

        const pad = g.s * 0.2;
        const board = ctx.createLinearGradient(0, g.y - pad, 0, g.y + rows * g.s + pad);
        board.addColorStop(0, '#16213a');
        board.addColorStop(1, '#0a1222');
        ctx.fillStyle = board;
        rounded(ctx, g.x - pad, g.y - pad, cols * g.s + pad * 2, rows * g.s + pad * 2, g.s * 0.25);
        ctx.fill();
        ctx.strokeStyle = 'rgba(148,163,184,0.18)';
        ctx.lineWidth = 1;
        ctx.stroke();

        const dying = sim.dead > 0;

        for (let c = 0; c < sim.n; c++) {
            const p = at(c), x = p[0], y = p[1], s = g.s;
            const since = now - (fx.openAt[c] || 0);

            if (sim.open[c] && since >= 0) {
                const num = sim.number(c);
                // Победа: волна подсветки по диагонали.
                if (sim.won) {
                    const w = (now - fx.wonAt - (c % cols + Math.floor(c / cols)) * 45) / 300;
                    well(ctx, x, y, s, num, 1, false);
                    if (w > 0 && w < 1) {
                        ctx.fillStyle = 'rgba(52,211,153,' + (0.45 * Math.sin(w * Math.PI)).toFixed(3) + ')';
                        rounded(ctx, x + 1, y + 1, s - 2, s - 2, s * 0.1);
                        ctx.fill();
                    }
                } else {
                    well(ctx, x, y, s, num, (since - REVEAL_MS * 0.5) / 200, false);
                }

                // Плитка уходит: сжимается, поворачивается и тает.
                if (since < REVEAL_MS) {
                    const k = since / REVEAL_MS;
                    ctx.save();
                    ctx.globalAlpha = 1 - k;
                    ctx.translate(x + s / 2, y + s / 2 - k * s * 0.15);
                    ctx.rotate(k * 0.35);
                    ctx.scale(1 - k * 0.7, 1 - k * 0.7);
                    tile(ctx, -s / 2, -s / 2, s, false, false);
                    ctx.restore();
                }
                continue;
            }

            if (dying && sim.mine[c] && !sim.flag[c]) {
                // Взорвавшаяся — сразу, остальные — по расстоянию от неё.
                const b = at(sim.boom);
                const d = Math.hypot(x - b[0], y - b[1]) / s;
                const k = c === sim.boom ? 1 : Math.min(1, Math.max(0, (now - fx.boomAt - 180 - d * 70) / 160));
                if (c === sim.boom) well(ctx, x, y, s, 0, 0, true);
                else if (k > 0) well(ctx, x, y, s, 0, 0, false);
                else tile(ctx, x, y, s, false, false);
                mine(ctx, x, y, s, c === sim.boom ? 1 : easeOutBack(k));
                continue;
            }

            const pressed = ui.hold && ui.hold.cell === c && !ui.hold.fired;
            tile(ctx, x, y, s, c === ui.hover && !dying, pressed);

            if (sim.flag[c]) flag(ctx, x, y, s, now, (now - (fx.flagAt[c] || 0)) / 300, dying && !sim.mine[c]);
        }

        // Курсор клавиатуры.
        if (ui.keyboard && !dying) {
            const p = at(ui.cursor);
            ctx.save();
            ctx.strokeStyle = COLORS.blue;
            ctx.lineWidth = 2;
            ctx.setLineDash([4, 3]);
            ctx.lineDashOffset = -now / 60;
            rounded(ctx, p[0] + 1, p[1] + 1, g.s - 2, g.s - 2, g.s * 0.14);
            ctx.stroke();
            ctx.restore();
        }

        // Долгое нажатие пальцем: кольцо заполняется до флажка или открытия соседей.
        const hold = ui.hold;
        if (hold && !hold.fired && !hold.mouse) {
            const k = Math.min(1, (now - hold.t0) / HOLD_MS);
            if (k > 0.15) {
                ctx.strokeStyle = COLORS.yellow;
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.arc(hold.x, hold.y, g.s * 0.7, -Math.PI / 2, -Math.PI / 2 + k * Math.PI * 2);
                ctx.stroke();
            }
        }

        // Взрыв: вспышка и ударная волна.
        if (fx.boomAt) {
            const t = now - fx.boomAt, p = at(sim.boom);
            if (t < 160) {
                ctx.fillStyle = 'rgba(255,247,237,' + (0.55 * (1 - t / 160)).toFixed(3) + ')';
                ctx.fillRect(0, HUD, view.W, view.H - HUD);
            }
            if (t < 600) {
                const k = t / 600;
                ctx.strokeStyle = 'rgba(253,186,116,' + (0.8 * (1 - k)).toFixed(3) + ')';
                ctx.lineWidth = 3 * (1 - k) + 0.5;
                ctx.beginPath();
                ctx.arc(p[0] + g.s / 2, p[1] + g.s / 2, g.s * (0.4 + k * 3.2), 0, Math.PI * 2);
                ctx.stroke();
            }
        }

        // Частицы: осколки, дым, конфетти.
        const keep = [];
        for (const q of fx.parts) {
            const age = (now - q.born) / q.life;
            if (age >= 1) continue;
            q.vy += q.g * dt;
            q.x += q.vx * dt;
            q.y += q.vy * dt;
            if (q.color === 'smoke') {
                ctx.fillStyle = 'rgba(100,116,139,' + (0.35 * (1 - age)).toFixed(3) + ')';
                ctx.beginPath();
                ctx.arc(q.x, q.y, q.r * (0.6 + age * 1.6), 0, Math.PI * 2);
                ctx.fill();
            } else {
                ctx.globalAlpha = 1 - age;
                ctx.fillStyle = q.color;
                if (q.spin !== undefined) {
                    ctx.save();
                    ctx.translate(q.x, q.y);
                    ctx.rotate(now / 120 + q.spin);
                    ctx.fillRect(-q.r * 1.5, -q.r * 0.6, q.r * 3, q.r * 1.2);
                    ctx.restore();
                } else {
                    ctx.beginPath();
                    ctx.arc(q.x, q.y, q.r, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.globalAlpha = 1;
            }
            keep.push(q);
        }
        fx.parts = keep;

        ctx.restore();
    }

    return Object.assign({}, input, { render: render });
}

createDriver({
    name: 'minesweeper',
    Sim:  MinesweeperSim,
    pointer: true,
    hover: true,
    render: function () {},
    controls: minesView,
    // В строке счёта — открытые клетки, справа — сколько мин осталось без флажка.
    score: function (sim) { return sim.opened; },
    status: function (sim) {
        let flags = 0;
        for (let c = 0; c < sim.n; c++) flags += sim.flag[c];
        return '⚑ ' + Math.max(0, sim.count - flags);
    },
    over: function (sim) { return sim.cleared > 0; },
    icon: function (ctx, x, y, r) { mine(ctx, x - r * 1.6, y - r * 1.6, r * 3.2, 1); },
});
