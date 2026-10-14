import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { FruitNinjaSim, H, FP, G, LIVES } from './sim.js';
import { fruitControls, TRAIL_MS } from './controls.js';

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

// Цвета фигур: [основной, светлый блик, тёмный край, «мякоть» на срезе].
const PALETTE = [
    ['#ef4444', '#fca5a5', '#991b1b', '#fee2e2'],
    ['#f97316', '#fdba74', '#9a3412', '#ffedd5'],
    ['#facc15', '#fef08a', '#a16207', '#fefce8'],
    ['#22c55e', '#86efac', '#166534', '#dcfce7'],
    ['#3b82f6', '#93c5fd', '#1e40af', '#dbeafe'],
    ['#a855f7', '#d8b4fe', '#6b21a8', '#f3e8ff'],
];

// Скорость и ускорение мира в единицах за миллисекунду: тиков 30 в секунду.
const PER_MS = 30 / 1000;
const GRAV   = G / FP * PER_MS * PER_MS;

/** Контур фигуры радиуса r вокруг (0, 0): треугольник, квадрат, круг, ромб. */
function outline(ctx, kind, r) {
    ctx.beginPath();
    if (kind === 0) {
        for (let i = 0; i < 3; i++) {
            const a = -Math.PI / 2 + i * Math.PI * 2 / 3;
            if (i) ctx.lineTo(Math.cos(a) * r * 1.25, Math.sin(a) * r * 1.25 + r * 0.15);
            else ctx.moveTo(Math.cos(a) * r * 1.25, Math.sin(a) * r * 1.25 + r * 0.15);
        }
        ctx.closePath();
    } else if (kind === 1) {
        const s = r * 0.8, c = r * 0.18;
        if (ctx.roundRect) ctx.roundRect(-s, -s, s * 2, s * 2, c);
        else ctx.rect(-s, -s, s * 2, s * 2);
    } else if (kind === 2) {
        ctx.arc(0, 0, r, 0, Math.PI * 2);
    } else {
        ctx.moveTo(0, -r * 1.25); ctx.lineTo(r * 0.95, 0); ctx.lineTo(0, r * 1.25); ctx.lineTo(-r * 0.95, 0);
        ctx.closePath();
    }
}

/** Фигура: объёмная заливка, тёмная кромка, блик. */
function shape(ctx, kind, color, r) {
    const c = PALETTE[color];
    const g = ctx.createRadialGradient(-r * 0.35, -r * 0.4, r * 0.1, 0, 0, r * 1.3);
    g.addColorStop(0, c[1]);
    g.addColorStop(0.45, c[0]);
    g.addColorStop(1, c[2]);

    outline(ctx, kind, r);
    ctx.fillStyle = g;
    ctx.fill();
    ctx.strokeStyle = c[2];
    ctx.lineWidth = 0.8;
    ctx.stroke();

    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.beginPath();
    ctx.ellipse(-r * 0.32, -r * 0.42, r * 0.28, r * 0.14, -0.6, 0, Math.PI * 2);
    ctx.fill();
}

/**
 * Половина разрезанной фигуры: та сторона линии среза (угол cut в системе фигуры), куда смотрит
 * side; по срезу — светлая «мякоть».
 */
function half(ctx, h) {
    const r = h.r;
    ctx.save();
    ctx.rotate(h.cut);
    ctx.beginPath();
    ctx.rect(-r * 2, h.side > 0 ? 0 : -r * 2, r * 4, r * 2);
    ctx.rotate(-h.cut);
    ctx.clip();

    shape(ctx, h.kind, h.color, r);

    outline(ctx, h.kind, r);
    ctx.clip();
    ctx.rotate(h.cut);
    ctx.fillStyle = PALETTE[h.color][3];
    ctx.fillRect(-r * 2, h.side > 0 ? 0 : -1.3, r * 4, 1.3);
    ctx.fillStyle = 'rgba(255,255,255,0.25)';
    ctx.fillRect(-r * 2, h.side > 0 ? 1.3 : -2.6, r * 4, 1.3);
    ctx.restore();
}

/**
 * Вид Fruit Ninja поверх ввода (controls.js): доска с волокнами, фигуры, половинки после среза,
 * брызги и тающие кляксы, всплывающие очки и комбо, светящийся след клинка, крестик упущенной фигуры.
 */
function fruitView(api) {
    const input = fruitControls(api);
    const ui    = input.ui;

    // Только для вида: последние известные фигуры (по id), осколки, брызги, кляксы, надписи.
    const fx = { seen: new Map(), halves: [], drops: [], stains: [], texts: [], misses: [], cuts: 0, last: 0 };

    /** Фигуры, пропавшие с прошлого кадра: внизу — упущены, иначе — разрезаны клинком. */
    function vanished(sim, now) {
        const live = new Set(sim.shapes.map(function (s) { return s[0]; }));
        const angle = Math.atan2(sim.by - sim.ay, sim.bx - sim.ax);

        fx.seen.forEach(function (v, id) {
            if (live.has(id)) return;
            fx.seen.delete(id);
            if (sim.dead || sim.idle) return;

            if (v.y > H - 2 && v.vy > 0) {
                fx.misses.push({ x: v.x, born: now });
                return;
            }

            const nx = -Math.sin(angle), ny = Math.cos(angle);
            for (const side of [-1, 1])
                fx.halves.push({ kind: v.kind, color: v.color, r: v.r, x: v.x, y: v.y, a: v.a, cut: angle - v.a, side: side,
                    vx: v.vx + nx * side * 0.03, vy: v.vy + ny * side * 0.03 - 0.01, spin: side * 0.004, born: now });

            for (let i = 0; i < 16; i++) {
                const t = (i / 16) * Math.PI * 2, sp = 0.02 + (i % 4) * 0.012;
                fx.drops.push({ x: v.x, y: v.y, vx: Math.cos(t) * sp + Math.cos(angle) * 0.03, vy: Math.sin(t) * sp - 0.02,
                    r: 0.6 + (i % 3) * 0.5, color: PALETTE[v.color][0], born: now });
            }
            fx.stains.push({ x: v.x, y: v.y, r: v.r, color: PALETTE[v.color][0], born: now, seed: id });
            fx.texts.push({ x: v.x, y: v.y - v.r, text: '+1', color: PALETTE[v.color][1], born: now, big: false });
        });

        // Комбо: несколько фигур одним взмахом.
        if (sim.cuts >= 2 && sim.cuts > fx.cuts)
            fx.texts.push({ x: sim.bx, y: sim.by - 12, text: 'Combo ×' + sim.cuts, color: COLORS.yellow, born: now, big: true });
        fx.cuts = sim.cuts;
    }

    function board(ctx, W) {
        // Доска: вертикальные планки разного тона, швы и волокна.
        const plank = 38;
        for (let i = 0, x = 0; x < W; i++, x += plank) {
            const g = ctx.createLinearGradient(x, 0, x + plank, 0);
            g.addColorStop(0, i % 2 ? '#3b2416' : '#412818');
            g.addColorStop(1, i % 2 ? '#33200f' : '#3a2414');
            ctx.fillStyle = g;
            ctx.fillRect(x, 0, plank, H);

            ctx.strokeStyle = 'rgba(255,214,170,0.05)';
            ctx.lineWidth = 0.6;
            for (let k = 0; k < 4; k++) {
                ctx.beginPath();
                const ox = x + 6 + k * 8 + (i * 5) % 7;
                for (let y = 0; y <= H; y += 10) {
                    const xx = ox + Math.sin(y / 23 + i * 1.7 + k) * 2.2;
                    if (y) ctx.lineTo(xx, y); else ctx.moveTo(xx, y);
                }
                ctx.stroke();
            }

            ctx.fillStyle = 'rgba(0,0,0,0.45)';
            ctx.fillRect(x, 0, 1, H);
        }

        const v = ctx.createRadialGradient(W / 2, H / 2, H * 0.2, W / 2, H / 2, W * 0.75);
        v.addColorStop(0, 'rgba(0,0,0,0)');
        v.addColorStop(1, 'rgba(0,0,0,0.55)');
        ctx.fillStyle = v;
        ctx.fillRect(0, 0, W, H);
    }

    function render(ctx, sim, view) {
        const now = view.now;
        const dt  = fx.last ? Math.min(50, now - fx.last) : 16;
        fx.last = now;
        const a = sim.dead || sim.idle ? 0 : view.alpha;
        const s = (view.H - HUD) / H;
        const W = Math.max(sim.w, view.W / s);

        vanished(sim, now);

        ctx.save();
        ctx.translate(0, HUD);
        ctx.scale(s, s);

        board(ctx, W);

        // Кляксы сока на доске: тают за 2,5 с.
        fx.stains = fx.stains.filter(function (st) {
            const age = (now - st.born) / 2500;
            if (age >= 1) return false;
            ctx.fillStyle = st.color;
            ctx.globalAlpha = 0.3 * (1 - age);
            for (let k = 0; k < 5; k++) {
                const t = st.seed * 1.7 + k * 1.3;
                ctx.beginPath();
                ctx.arc(st.x + Math.cos(t) * st.r * 0.7, st.y + Math.sin(t) * st.r * 0.6, st.r * (0.35 + (k % 3) * 0.15), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1;
            return true;
        });

        // Фигуры — между тиками по скорости.
        for (const sh of sim.shapes) {
            // До следующего тика: скорость по y на нём уже с ускорением G.
            const x = (sh[1] + sh[3] * a) / FP, y = (sh[2] + (sh[4] + G) * a) / FP;
            const ang = sh[8] * (sim.clock - sh[9] + a) * 0.04;
            fx.seen.set(sh[0], { x: x, y: y, vx: sh[3] / FP * PER_MS, vy: sh[4] / FP * PER_MS, kind: sh[5], color: sh[6], r: sh[7], a: ang });

            ctx.fillStyle = 'rgba(0,0,0,0.28)';
            ctx.beginPath();
            ctx.ellipse(x + 2, y + sh[7] * 1.1, sh[7] * 0.8, sh[7] * 0.25, 0, 0, Math.PI * 2);
            ctx.fill();

            ctx.save();
            ctx.translate(x, y);
            ctx.rotate(ang);
            shape(ctx, sh[5], sh[6], sh[7]);
            ctx.restore();
        }

        // Половинки: разлетаются, вращаются и падают.
        fx.halves = fx.halves.filter(function (h) {
            h.vy += GRAV * dt;
            h.x += h.vx * dt;
            h.y += h.vy * dt;
            h.a += h.spin * dt;
            if (h.y > H + 20 || now - h.born > 2500) return false;
            ctx.save();
            ctx.translate(h.x, h.y);
            ctx.rotate(h.a);
            half(ctx, h);
            ctx.restore();
            return true;
        });

        // Брызги.
        fx.drops = fx.drops.filter(function (d) {
            const age = (now - d.born) / 650;
            if (age >= 1) return false;
            d.vy += GRAV * dt;
            d.x += d.vx * dt;
            d.y += d.vy * dt;
            ctx.globalAlpha = 1 - age;
            ctx.fillStyle = d.color;
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r * (1 - age * 0.5), 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha = 1;
            return true;
        });

        // Упущенная фигура: красный крестик у нижнего края.
        fx.misses = fx.misses.filter(function (m) {
            const age = (now - m.born) / 900;
            if (age >= 1) return false;
            const k = 1 + 0.4 * Math.max(0, 1 - age * 4);
            ctx.save();
            ctx.translate(m.x, H - 10);
            ctx.scale(k, k);
            ctx.globalAlpha = 1 - age * age;
            ctx.strokeStyle = COLORS.red;
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(-5, -5); ctx.lineTo(5, 5);
            ctx.moveTo(5, -5); ctx.lineTo(-5, 5);
            ctx.stroke();
            ctx.restore();
            return true;
        });

        // Всплывающие очки и комбо.
        fx.texts = fx.texts.filter(function (t) {
            const age = (now - t.born) / (t.big ? 900 : 600);
            if (age >= 1) return false;
            const k = t.big ? 1 + 0.3 * Math.max(0, 1 - age * 5) : 1;
            ctx.save();
            ctx.translate(t.x, t.y - age * 12);
            ctx.scale(k, k);
            ctx.globalAlpha = 1 - age * age;
            ctx.font = (t.big ? '800 11px ' : '700 8px ') + FONT;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.lineWidth = 2;
            ctx.strokeStyle = 'rgba(0,0,0,0.6)';
            ctx.strokeText(t.text, 0, 0);
            ctx.fillStyle = t.color;
            ctx.fillText(t.text, 0, 0);
            ctx.restore();
            return true;
        });

        ctx.restore();

        blade(ctx, now);
    }

    /** След клинка: сужающаяся к хвосту светящаяся лента по точкам пути за TRAIL_MS. */
    function blade(ctx, now) {
        const pts = ui.trail.filter(function (p) { return now - p.t < TRAIL_MS; });
        ui.trail = pts;
        if (pts.length < 2) return;

        ctx.save();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.shadowColor = '#67e8f9';
        ctx.shadowBlur = 10;
        for (let i = 1; i < pts.length; i++) {
            const k = i / (pts.length - 1), fade = 1 - (now - pts[i].t) / TRAIL_MS;
            ctx.strokeStyle = 'rgba(207,250,254,' + (0.9 * fade).toFixed(3) + ')';
            ctx.lineWidth = 0.6 + 3.4 * k;
            ctx.beginPath();
            ctx.moveTo(pts[i - 1].x, pts[i - 1].y);
            ctx.lineTo(pts[i].x, pts[i].y);
            ctx.stroke();
        }
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(255,255,255,0.95)';
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.moveTo(pts[pts.length - 2].x, pts[pts.length - 2].y);
        ctx.lineTo(pts[pts.length - 1].x, pts[pts.length - 1].y);
        ctx.stroke();
        ctx.restore();
    }

    return Object.assign({}, input, { render: render });
}

createDriver({
    name: 'fruitninja',
    Sim:  FruitNinjaSim,
    pointer: true,
    hover: true,
    render: function () {},
    controls: fruitView,
    // Справа — упущенные фигуры: три — и жизнь кончилась.
    status: function (sim) {
        const out = [];
        for (let i = 0; i < LIVES; i++) out.push(i < sim.misses ? '✕' : '·');
        return out.join(' ');
    },
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.translate(x - 1, y + 1);
        half(ctx, { kind: 2, color: 0, r: r * 0.9, cut: -0.6, side: -1 });
        ctx.translate(2.5, 1.5);
        half(ctx, { kind: 2, color: 0, r: r * 0.9, cut: -0.6, side: 1 });
        ctx.restore();
    },
});
