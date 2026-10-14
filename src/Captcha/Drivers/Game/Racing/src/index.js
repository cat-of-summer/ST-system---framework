import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { RacingSim, FP, N, ROAD, WALL, CHECKS, DOWN, STEER } from './sim.js';
import { racingControls } from './controls.js';

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

/** Сколько единиц мира видно по высоте игровой зоны. */
const VIEW = 170;

/** Время в тиках (30 в секунду) → «M:SS.ffffff»; дробная часть до микросекунд. */
export function stopwatch(ticks) {
    const sec = Math.max(0, ticks) / 30;
    const m = Math.floor(sec / 60), s = sec - m * 60;
    return m + ':' + (s < 10 ? '0' : '') + s.toFixed(6);
}

/**
 * Состояние только для вида: следы шин, пыль, вспышка круга, нормали трассы для поребриков и
 * отбойников (считаются один раз на трассу).
 */
const fx = { skids: [], dust: [], sparks: [], lapAt: 0, lapText: '', laps: 0, track: null, edges: null, last: 0, cam: null };

/** Нормали трассы и смещённые линии: края асфальта и отбойники по обе стороны. */
function edges(track) {
    const out = { left: [], right: [], wallL: [], wallR: [] };
    for (let i = 0; i < N; i++) {
        const a = track[(i + N - 1) % N], b = track[(i + 1) % N];
        let nx = -(b[1] - a[1]), ny = b[0] - a[0];
        const len = Math.hypot(nx, ny) || 1;
        nx /= len; ny /= len;
        const p = track[i];
        out.left.push([p[0] + nx * ROAD, p[1] + ny * ROAD]);
        out.right.push([p[0] - nx * ROAD, p[1] - ny * ROAD]);
        out.wallL.push([p[0] + nx * (WALL + 4), p[1] + ny * (WALL + 4)]);
        out.wallR.push([p[0] - nx * (WALL + 4), p[1] - ny * (WALL + 4)]);
    }
    return out;
}

function loop(ctx, pts) {
    ctx.beginPath();
    pts.forEach(function (p, i) { if (i) ctx.lineTo(p[0], p[1]); else ctx.moveTo(p[0], p[1]); });
    ctx.closePath();
}

/** Трава: полосы покоса в мировых координатах (едут вместе с миром) и пучки травы. */
function grass(ctx, x0, y0, x1, y1) {
    ctx.fillStyle = '#166534';
    ctx.fillRect(x0, y0, x1 - x0, y1 - y0);
    ctx.fillStyle = 'rgba(34,197,94,0.18)';
    for (let y = Math.floor(y0 / 48) * 48; y < y1; y += 96) ctx.fillRect(x0, y, x1 - x0, 48);

    ctx.fillStyle = 'rgba(5,46,22,0.5)';
    for (let gx = Math.floor(x0 / 22) * 22; gx < x1; gx += 22)
        for (let gy = Math.floor(y0 / 22) * 22; gy < y1; gy += 22) {
            const h = ((gx * 73856093) ^ (gy * 19349663)) >>> 0;
            if (h % 3) continue;
            ctx.fillRect(gx + (h % 17), gy + ((h >> 5) % 17), 1.6, 1.6);
        }
}

function track(ctx, sim, e, now) {
    const t = sim.track;

    // Поребрики: широкая красная полоса с белыми вставками по краям асфальта.
    ctx.lineJoin = 'round';
    ctx.lineCap = 'butt';
    ctx.strokeStyle = '#dc2626';
    ctx.lineWidth = ROAD * 2 + 7;
    loop(ctx, t); ctx.stroke();
    ctx.strokeStyle = '#f8fafc';
    ctx.setLineDash([8, 8]);
    loop(ctx, t); ctx.stroke();
    ctx.setLineDash([]);

    // Асфальт: тёмный, к середине чуть светлее, белые краевые линии и пунктир по оси.
    ctx.strokeStyle = '#3f3f46';
    ctx.lineWidth = ROAD * 2 - 1;
    loop(ctx, t); ctx.stroke();
    ctx.strokeStyle = '#46464f';
    ctx.lineWidth = ROAD * 1.2;
    loop(ctx, t); ctx.stroke();
    ctx.strokeStyle = 'rgba(248,250,252,0.75)';
    ctx.lineWidth = 1;
    loop(ctx, e.left); ctx.stroke();
    loop(ctx, e.right); ctx.stroke();
    ctx.strokeStyle = 'rgba(250,204,21,0.55)';
    ctx.lineWidth = 1.2;
    ctx.setLineDash([12, 14]);
    loop(ctx, t); ctx.stroke();
    ctx.setLineDash([]);

    // Отбойники из шин по обе стороны.
    for (const side of [e.wallL, e.wallR])
        for (let i = 0; i < N; i++) {
            const a = side[i], b = side[(i + 1) % N];
            const steps = Math.max(1, Math.round(Math.hypot(b[0] - a[0], b[1] - a[1]) / 6));
            for (let s = 0; s < steps; s++) {
                const x = a[0] + (b[0] - a[0]) * s / steps, y = a[1] + (b[1] - a[1]) * s / steps;
                ctx.fillStyle = '#18181b';
                ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = (i + s) % 4 === 0 ? '#ef4444' : '#52525b';
                ctx.beginPath(); ctx.arc(x, y, 1.4, 0, Math.PI * 2); ctx.fill();
            }
        }

    // Старт-финиш: шахматная полоса поперёк трассы.
    const p = t[0], q = t[1];
    const ang = Math.atan2(q[1] - p[1], q[0] - p[0]);
    ctx.save();
    ctx.translate(p[0], p[1]);
    ctx.rotate(ang);
    const cell = ROAD / 4;
    for (let r = 0; r < 2; r++)
        for (let c = -4; c < 4; c++) {
            ctx.fillStyle = (r + c) % 2 ? '#f8fafc' : '#0f172a';
            ctx.fillRect(r * cell - cell, c * cell, cell, cell);
        }
    ctx.restore();

    // Ворота чекпоинтов: следующие горят и пульсируют, остальные — тусклые столбики.
    for (let c = 0; c < CHECKS; c++) {
        const i = sim.checks[c], next = c === sim.cp;
        const l = e.left[i], r = e.right[i];
        for (const s of [l, r]) {
            const dx = s[0] - t[i][0], dy = s[1] - t[i][1], len = Math.hypot(dx, dy) || 1;
            const x = s[0] + dx / len * 5, y = s[1] + dy / len * 5;
            ctx.fillStyle = next ? '#facc15' : 'rgba(250,204,21,0.25)';
            ctx.shadowColor = '#facc15';
            ctx.shadowBlur = next ? 8 + 4 * Math.sin(now / 150) : 0;
            ctx.beginPath(); ctx.arc(x, y, next ? 3 : 2, 0, Math.PI * 2); ctx.fill();
        }
        ctx.shadowBlur = 0;
        if (next) {
            ctx.strokeStyle = 'rgba(250,204,21,' + (0.35 + 0.2 * Math.sin(now / 150)).toFixed(3) + ')';
            ctx.lineWidth = 1.5;
            ctx.setLineDash([3, 3]);
            ctx.beginPath(); ctx.moveTo(l[0], l[1]); ctx.lineTo(r[0], r[1]); ctx.stroke();
            ctx.setLineDash([]);
        }
    }
}

/** Машина: тень, колёса (передние поворачиваются с рулём), кузов с полосами, стёкла, фары, стопы. */
function car(ctx, x, y, a, steer, braking) {
    const L = 15, Wd = 8;
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(a);

    ctx.fillStyle = 'rgba(0,0,0,0.35)';
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(-L / 2 + 1.5, -Wd / 2 + 2, L, Wd, 3); else ctx.rect(-L / 2 + 1.5, -Wd / 2 + 2, L, Wd);
    ctx.fill();

    // Колёса.
    ctx.fillStyle = '#0a0a0a';
    for (const [wx, wy, front] of [[4.5, -4.6, 1], [4.5, 4.6, 1], [-4.5, -4.6, 0], [-4.5, 4.6, 0]]) {
        ctx.save();
        ctx.translate(wx, wy);
        if (front) ctx.rotate(steer * 0.45);
        ctx.fillRect(-2, -1, 4, 2);
        ctx.restore();
    }

    const g = ctx.createLinearGradient(0, -Wd / 2, 0, Wd / 2);
    g.addColorStop(0, '#f87171');
    g.addColorStop(0.5, '#dc2626');
    g.addColorStop(1, '#7f1d1d');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.moveTo(-L / 2, -Wd / 2 + 1);
    ctx.quadraticCurveTo(-L / 2, -Wd / 2, -L / 2 + 2, -Wd / 2);
    ctx.lineTo(L / 2 - 3, -Wd / 2 + 0.5);
    ctx.quadraticCurveTo(L / 2, -Wd / 2 + 1.5, L / 2, 0);
    ctx.quadraticCurveTo(L / 2, Wd / 2 - 1.5, L / 2 - 3, Wd / 2 - 0.5);
    ctx.lineTo(-L / 2 + 2, Wd / 2);
    ctx.quadraticCurveTo(-L / 2, Wd / 2, -L / 2, Wd / 2 - 1);
    ctx.closePath();
    ctx.fill();

    // Полосы по капоту и крыше.
    ctx.fillStyle = 'rgba(255,255,255,0.85)';
    ctx.fillRect(-L / 2 + 1, -0.9, L - 2, 0.6);
    ctx.fillRect(-L / 2 + 1, 0.3, L - 2, 0.6);

    // Лобовое и заднее стекло.
    ctx.fillStyle = '#1e3a8a';
    ctx.beginPath(); ctx.moveTo(2.5, -3); ctx.lineTo(4.2, -2.4); ctx.lineTo(4.2, 2.4); ctx.lineTo(2.5, 3); ctx.closePath(); ctx.fill();
    ctx.fillStyle = 'rgba(147,197,253,0.6)';
    ctx.beginPath(); ctx.moveTo(2.9, -2.4); ctx.lineTo(3.8, -2); ctx.lineTo(3.8, -0.6); ctx.lineTo(2.9, -0.8); ctx.closePath(); ctx.fill();
    ctx.fillStyle = '#1e3a8a';
    ctx.fillRect(-4.8, -2.6, 1.3, 5.2);

    // Спойлер, фары, стопы.
    ctx.fillStyle = '#111827';
    ctx.fillRect(-L / 2 - 0.6, -Wd / 2 + 0.3, 1.2, Wd - 0.6);
    ctx.fillStyle = '#fef08a';
    ctx.fillRect(L / 2 - 1.2, -Wd / 2 + 1, 1, 1.4);
    ctx.fillRect(L / 2 - 1.2, Wd / 2 - 2.4, 1, 1.4);
    ctx.fillStyle = braking ? '#ff3b3b' : '#7f1d1d';
    if (braking) { ctx.shadowColor = '#ef4444'; ctx.shadowBlur = 6; }
    ctx.fillRect(-L / 2 - 0.2, -Wd / 2 + 1, 0.9, 1.4);
    ctx.fillRect(-L / 2 - 0.2, Wd / 2 - 2.4, 0.9, 1.4);
    ctx.shadowBlur = 0;
    ctx.restore();
}

/** Мини-карта в правом нижнем углу: трасса, машина, следующий чекпоинт. */
function minimap(ctx, sim, view) {
    const t = sim.track;
    let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (const p of t) { x0 = Math.min(x0, p[0]); y0 = Math.min(y0, p[1]); x1 = Math.max(x1, p[0]); y1 = Math.max(y1, p[1]); }

    const bw = Math.min(78, view.W * 0.24), bh = bw * (y1 - y0) / (x1 - x0);
    const bx = view.W - bw - 8, by = view.H - bh - 8, k = bw / (x1 - x0);
    const map = function (p) { return [bx + (p[0] - x0) * k, by + (p[1] - y0) * k]; };

    ctx.fillStyle = 'rgba(2,6,23,0.6)';
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(bx - 5, by - 5, bw + 10, bh + 10, 6); else ctx.rect(bx - 5, by - 5, bw + 10, bh + 10);
    ctx.fill();

    ctx.strokeStyle = 'rgba(226,232,240,0.8)';
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    loop(ctx, t.map(map));
    ctx.stroke();

    const c = map(t[sim.checks[sim.cp]]);
    ctx.fillStyle = '#facc15';
    ctx.beginPath(); ctx.arc(c[0], c[1], 2.2, 0, Math.PI * 2); ctx.fill();
    const s = map(t[0]);
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(s[0] - 1.5, s[1] - 1.5, 3, 3);

    const m = map([sim.x / FP, sim.y / FP]);
    ctx.fillStyle = '#ef4444';
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(m[0], m[1], 2.6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
}

function render(ctx, sim, view) {
    const now = view.now;
    const dt  = fx.last ? Math.min(50, now - fx.last) : 16;
    fx.last = now;
    const a = sim.idle ? 0 : view.alpha;

    if (fx.track !== sim.track) { fx.track = sim.track; fx.edges = edges(sim.track); fx.skids = []; }
    if (sim.laps < fx.laps) fx.laps = sim.laps;

    const head = (sim.h >> 3) * Math.PI * 2 / 256;
    const cx = (sim.x + sim.vx * a) / FP, cy = (sim.y + sim.vy * a) / FP;
    const sc = (view.H - HUD) / VIEW;
    const steer = sim.steer / STEER;
    const grassy = sim.grass();

    // Камера: за машиной, чуть вперёд по ходу.
    // Камера: чуть вперёд по ходу и плавно догоняет — без рывков, когда машина резко тормозит на
    // траве или о отбойник.
    const lead = Math.max(0, sim.vf) / 1500 * 25;
    const want = [cx + Math.cos(head) * lead, cy + Math.sin(head) * lead];
    if (!fx.cam || sim.idle) fx.cam = want;
    const follow = 1 - Math.exp(-dt / 140);
    fx.cam = [fx.cam[0] + (want[0] - fx.cam[0]) * follow, fx.cam[1] + (want[1] - fx.cam[1]) * follow];
    const camx = fx.cam[0], camy = fx.cam[1];
    const hw = view.W / 2 / sc, hh = (view.H - HUD) / 2 / sc;

    ctx.save();
    ctx.beginPath(); ctx.rect(0, HUD, view.W, view.H - HUD); ctx.clip();
    ctx.translate(view.W / 2, HUD + (view.H - HUD) / 2);
    ctx.scale(sc, sc);
    ctx.translate(-camx, -camy);

    grass(ctx, camx - hw - 2, camy - hh - 2, camx + hw + 2, camy + hh + 2);
    track(ctx, sim, fx.edges, now);

    // Следы шин в заносе: руль на скорости.
    if (!sim.idle && sim.drift && Math.abs(sim.vf) > 300) {
        for (const s of [-3.6, 3.6])
            fx.skids.push({ x: cx - Math.cos(head) * 5 - Math.sin(head) * s, y: cy - Math.sin(head) * 5 + Math.cos(head) * s, a: head, born: now });
        if (fx.skids.length > 600) fx.skids.splice(0, fx.skids.length - 600);
    }
    for (const s of fx.skids) {
        const age = (now - s.born) / 4000;
        if (age >= 1) continue;
        ctx.save();
        ctx.translate(s.x, s.y);
        ctx.rotate(s.a);
        ctx.fillStyle = 'rgba(17,17,17,' + (0.35 * (1 - age)).toFixed(3) + ')';
        ctx.fillRect(-1.5, -0.7, 3, 1.4);
        ctx.restore();
    }

    // Пыль из-под колёс на траве.
    if (!sim.idle && grassy && Math.abs(sim.vf) > 200)
        fx.dust.push({ x: cx - Math.cos(head) * 7, y: cy - Math.sin(head) * 7, vx: -Math.cos(head) * 0.01 + (Math.random() - 0.5) * 0.02,
            vy: -Math.sin(head) * 0.01 + (Math.random() - 0.5) * 0.02, born: now });
    fx.dust = fx.dust.filter(function (d) {
        const age = (now - d.born) / 700;
        if (age >= 1) return false;
        d.x += d.vx * dt; d.y += d.vy * dt;
        ctx.fillStyle = 'rgba(214,190,140,' + (0.45 * (1 - age)).toFixed(3) + ')';
        ctx.beginPath(); ctx.arc(d.x, d.y, 2 + age * 5, 0, Math.PI * 2); ctx.fill();
        return true;
    });

    // Удар об отбойник — машину трясёт.
    // Трётся об отбойник — искры с той стороны, где стенка.
    if (!sim.idle && sim.scrape) {
        const n = sim.nearest(), dx = cx - n[2] / FP, dy = cy - n[3] / FP, len = Math.hypot(dx, dy) || 1;
        for (let i = 0; i < 3; i++)
            fx.sparks.push({ x: cx + dx / len * 4, y: cy + dy / len * 4, vx: -sim.vx / FP * 0.02 + (Math.random() - 0.5) * 0.08,
                vy: -sim.vy / FP * 0.02 + (Math.random() - 0.5) * 0.08, born: now });
    }
    fx.sparks = fx.sparks.filter(function (p) {
        const age = (now - p.born) / 350;
        if (age >= 1) return false;
        p.x += p.vx * dt; p.y += p.vy * dt;
        ctx.fillStyle = age < 0.4 ? '#fef08a' : '#fb923c';
        ctx.globalAlpha = 1 - age;
        ctx.fillRect(p.x - 0.6, p.y - 0.6, 1.2, 1.2);
        ctx.globalAlpha = 1;
        return true;
    });

    car(ctx, cx, cy, head, steer, (sim.keys & DOWN) !== 0 && sim.vf > 0);
    ctx.restore();

    minimap(ctx, sim, view);

    // Не туда — если машина развернулась против трассы.
    if (!sim.idle && Math.abs(sim.aim()) > 80 && sim.vf > 300) {
        ctx.fillStyle = 'rgba(239,68,68,' + (0.6 + 0.4 * Math.sin(now / 120)).toFixed(3) + ')';
        ctx.font = '900 16px ' + FONT;
        ctx.textAlign = 'center';
        ctx.fillText('НЕ ТУДА', view.W / 2, HUD + 26);
    }

    // Круг пройден: время круга крупно, тает.
    if (sim.laps > fx.laps) {
        fx.laps = sim.laps;
        fx.lapAt = now;
        fx.lapText = stopwatch(sim.lapTimes[sim.lapTimes.length - 1]);
    }
    if (fx.lapAt && now - fx.lapAt < 1800) {
        const k = (now - fx.lapAt) / 1800;
        ctx.save();
        ctx.globalAlpha = 1 - k * k;
        ctx.translate(view.W / 2, HUD + (view.H - HUD) * 0.35);
        ctx.scale(1 + 0.2 * (1 - Math.min(1, k * 4)), 1 + 0.2 * (1 - Math.min(1, k * 4)));
        ctx.font = 'italic 900 18px ' + FONT;
        ctx.textAlign = 'center';
        ctx.lineWidth = 3;
        ctx.strokeStyle = 'rgba(0,0,0,0.6)';
        ctx.strokeText(fx.lapText, 0, 0);
        ctx.fillStyle = '#fde047';
        ctx.fillText(fx.lapText, 0, 0);
        ctx.restore();
    }
}

createDriver({
    name: 'racing',
    Sim:  RacingSim,
    // На телефоне — две половинки крестовины: слева газ и тормоз, справа руль.
    pad:  { zones: { left: ['up', 'down'], right: ['left', 'right'] } },
    render: render,
    controls: racingControls,
    // Секундомер круга: тики плюс доля до следующего — до микросекунд; справа — лучший круг.
    score: function (sim, view) {
        return stopwatch(sim.clock - sim.lapAt + (sim.idle || !view ? 0 : view.alpha));
    },
    status: function (sim) {
        return sim.best ? 'best ' + stopwatch(sim.best).slice(0, -3) : '';
    },
    over: function (sim) { return sim.laps >= sim.laps0; },
    icon: function (ctx, x, y, r) {
        const c = r * 0.55;
        for (let i = 0; i < 4; i++)
            for (let j = 0; j < 4; j++) {
                ctx.fillStyle = (i + j) % 2 ? '#f8fafc' : '#0f172a';
                ctx.fillRect(x - r + i * c, y - r + j * c, c, c);
            }
    },
});
