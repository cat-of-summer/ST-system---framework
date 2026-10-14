import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { PinballSim, W, H, FP, R, WALLS, GATE, SLINGS, FLIPPERS, FR, CHARGE, BUMP_POINTS, SLING_POINTS } from './sim.js';
import { pinballControls } from './controls.js';

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

/** Контур поля: всё, что внутри бортиков. */
const FIELD = [[30, 4], [130, 4], [156, 30], [156, 240], [4, 240], [4, 40]];

/** Резиновые столбики на стыках бортиков — только вид, физика у стыков та же, что у стенок. */
const POSTS = [[4, 168], [38, 200], [144, 168], [106, 200], [144, 64], [16, 138], [128, 138], [16, 166], [128, 166], [32, 178], [112, 178]];

/** Верхние лампы над бамперами. */
const TOP_LANES = [56, 72, 88];

/**
 * Состояние только для вида: след мяча, всплывающие очки, когда начались вспышки бамперов и
 * рогаток, когда мяч ушёл в слив. На симуляцию не влияет.
 */
const fx = { trail: [], pops: [], flash: [], drainAt: 0 };

/** Стол в игровой зоне: масштаб и левый верхний угол; панели по бокам — если хватает места. */
function geometry(view) {
    const s = (view.H - HUD - 8) / H;
    const x = (view.W - W * s) / 2;
    return { s: s, x: x, y: HUD + 4, side: x - 10 };
}

function path(ctx, pts) {
    ctx.beginPath();
    pts.forEach(function (p, i) { if (i) ctx.lineTo(p[0], p[1]); else ctx.moveTo(p[0], p[1]); });
    ctx.closePath();
}

/** Рама стола: тёмный металл вокруг поля и по низу, с болтами. */
function cabinet(ctx) {
    const g = ctx.createLinearGradient(-6, 0, W + 6, 0);
    g.addColorStop(0, '#475569');
    g.addColorStop(0.04, '#1e293b');
    g.addColorStop(0.96, '#1e293b');
    g.addColorStop(1, '#475569');
    ctx.fillStyle = g;
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(-4, -3, W + 8, H + 6, 6); else ctx.rect(-4, -3, W + 8, H + 6);
    ctx.fill();

    ctx.fillStyle = '#94a3b8';
    for (const p of [[-1, 10], [-1, 120], [-1, 230], [W + 1, 10], [W + 1, 120], [W + 1, 230]]) {
        ctx.beginPath(); ctx.arc(p[0], p[1], 1.1, 0, Math.PI * 2); ctx.fill();
    }
}

function playfield(ctx, sim, now) {
    const g = ctx.createRadialGradient(W / 2, H * 0.55, 10, W / 2, H * 0.55, H * 0.75);
    g.addColorStop(0, '#3b0f7a');
    g.addColorStop(0.5, '#1e1b4b');
    g.addColorStop(1, '#0b1026');
    ctx.fillStyle = g;
    path(ctx, FIELD);
    ctx.fill();

    ctx.save();
    path(ctx, FIELD);
    ctx.clip();

    // Печать на поле: лучи от слива, звёздная пыль, большой круг-мишень посередине.
    ctx.strokeStyle = 'rgba(167,139,250,0.08)';
    ctx.lineWidth = 0.6;
    ctx.beginPath();
    for (let a = -7; a <= 7; a++) { ctx.moveTo(72, 240); ctx.lineTo(72 + a * 20, 0); }
    ctx.stroke();
    ctx.fillStyle = 'rgba(255,255,255,0.35)';
    for (let i = 0; i < 26; i++) {
        const x = (i * 53) % 140 + 8, y = (i * 97) % 150 + 20, tw = 0.5 + 0.5 * Math.sin(now / 500 + i);
        ctx.globalAlpha = 0.15 + 0.25 * tw;
        ctx.fillRect(x, y, 0.8, 0.8);
    }
    ctx.globalAlpha = 1;
    ctx.strokeStyle = 'rgba(244,114,182,0.12)';
    ctx.lineWidth = 1.2;
    for (const r of [22, 34]) { ctx.beginPath(); ctx.arc(72, 150, r, 0, Math.PI * 2); ctx.stroke(); }

    // Ореолы под бамперами — печать, на которой они стоят.
    for (const b of sim.bumpers) {
        const halo = ctx.createRadialGradient(b[0], b[1], b[2], b[0], b[1], b[2] + 8);
        halo.addColorStop(0, 'rgba(250,204,21,0.25)');
        halo.addColorStop(1, 'rgba(250,204,21,0)');
        ctx.fillStyle = halo;
        ctx.beginPath(); ctx.arc(b[0], b[1], b[2] + 8, 0, Math.PI * 2); ctx.fill();
        ctx.setLineDash([2, 2]);
        ctx.strokeStyle = 'rgba(250,204,21,0.35)';
        ctx.lineWidth = 0.6;
        ctx.beginPath(); ctx.arc(b[0], b[1], b[2] + 5, 0, Math.PI * 2); ctx.stroke();
        ctx.setLineDash([]);
    }

    // Верхние лампы бегут по кругу.
    TOP_LANES.forEach(function (x, i) {
        const on = Math.floor(now / 220) % 3 === i;
        lamp(ctx, x, 24, 2.6, '#fde047', on);
    });

    // Стрелки-вставки над флипперами бегут вверх, к бамперам.
    for (let i = 0; i < 3; i++) {
        const on = Math.floor(now / 160) % 4 === i, y = 188 - i * 10;
        ctx.fillStyle = on ? 'rgba(52,211,153,0.95)' : 'rgba(52,211,153,0.16)';
        ctx.shadowColor = '#34d399';
        ctx.shadowBlur = on ? 6 : 0;
        ctx.beginPath();
        ctx.moveTo(72, y - 5); ctx.lineTo(78, y + 1.5); ctx.lineTo(72, y - 0.5); ctx.lineTo(66, y + 1.5);
        ctx.closePath();
        ctx.fill();
    }
    ctx.shadowBlur = 0;

    // Боковые лампы у дорожек к флипперам.
    lamp(ctx, 12, 180, 1.8, '#60a5fa', Math.floor(now / 400) % 2 === 0);
    lamp(ctx, 136, 180, 1.8, '#60a5fa', Math.floor(now / 400) % 2 === 1);

    // Желоб пускателя и стрелки «запуск».
    ctx.fillStyle = 'rgba(0,0,0,0.4)';
    ctx.fillRect(144, 30, 12, 210);
    for (let i = 0; i < 4; i++) {
        const on = Math.floor(now / 120) % 4 === 3 - i, y = 120 + i * 22;
        ctx.fillStyle = on ? 'rgba(251,146,60,0.9)' : 'rgba(251,146,60,0.15)';
        ctx.beginPath(); ctx.moveTo(150, y - 4); ctx.lineTo(153.5, y + 1); ctx.lineTo(146.5, y + 1); ctx.closePath(); ctx.fill();
    }

    // Фартук у слива: пластик под флипперами с прорезью посередине.
    ctx.fillStyle = '#0f172a';
    path(ctx, [[4, 216], [52, 228], [52, 240], [4, 240]]);
    ctx.fill();
    path(ctx, [[144, 216], [92, 228], [92, 240], [144, 240]]);
    ctx.fill();
    ctx.fillStyle = 'rgba(244,114,182,0.6)';
    ctx.font = '700 5px ' + FONT;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('ST', 26, 233);
    ctx.fillText('ST', 118, 233);

    ctx.restore();
}

/** Лампа-вставка: стеклянный кружок с ободком; горит — светится. */
function lamp(ctx, x, y, r, color, on) {
    ctx.fillStyle = 'rgba(15,23,42,0.9)';
    ctx.beginPath(); ctx.arc(x, y, r + 0.8, 0, Math.PI * 2); ctx.fill();
    ctx.shadowColor = color;
    ctx.shadowBlur = on ? 7 : 0;
    ctx.fillStyle = color;
    ctx.globalAlpha = on ? 1 : 0.22;
    ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();
    ctx.globalAlpha = 1;
    ctx.shadowBlur = 0;
    ctx.fillStyle = 'rgba(255,255,255,0.55)';
    ctx.beginPath(); ctx.arc(x - r * 0.35, y - r * 0.35, r * 0.3, 0, Math.PI * 2); ctx.fill();
}

/** Хромированный бортик: тёмная подложка, металл, блик. */
function rail(ctx, x1, y1, x2, y2) {
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#0f172a';
    ctx.lineWidth = 3.6;
    ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
    ctx.strokeStyle = '#94a3b8';
    ctx.lineWidth = 2.1;
    ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
    ctx.strokeStyle = 'rgba(255,255,255,0.6)';
    ctx.lineWidth = 0.6;
    ctx.beginPath(); ctx.moveTo(x1 - 0.3, y1 - 0.4); ctx.lineTo(x2 - 0.3, y2 - 0.4); ctx.stroke();
}

/** Перегородка желоба: после вылета мяча выдвигается из правого бортика и закрывает вход. */
function gate(ctx, sim) {
    if (!sim.closed) return;
    const k = sim.shut / 10, ex = GATE[0] + (GATE[2] - GATE[0]) * k, ey = GATE[1] + (GATE[3] - GATE[1]) * k;
    rail(ctx, GATE[0], GATE[1], ex, ey);
    ctx.fillStyle = '#fb923c';
    ctx.beginPath(); ctx.arc(GATE[0] - 2, GATE[1] + 4, 1.2, 0, Math.PI * 2); ctx.fill();
}

/** Резиновый столбик: металлический штырь в чёрном резиновом кольце. */
function post(ctx, x, y) {
    ctx.fillStyle = '#020617';
    ctx.beginPath(); ctx.arc(x, y, 2.2, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#cbd5e1';
    ctx.beginPath(); ctx.arc(x, y, 1, 0, Math.PI * 2); ctx.fill();
}

/** Бампер: тень, металлическая юбка с прорезями, красный корпус с кольцом огней, шляпка со звездой. */
function bumper(ctx, b, glow, now) {
    const k = glow > 0 ? 1 + 0.12 * glow : 1, r = b[2];
    ctx.save();
    ctx.translate(b[0], b[1]);

    ctx.fillStyle = 'rgba(0,0,0,0.45)';
    ctx.beginPath(); ctx.ellipse(1.2, 2.2, r + 2, r + 1.5, 0, 0, Math.PI * 2); ctx.fill();

    // Юбка: металлическое кольцо, при ударе вздрагивает к центру.
    const skirt = ctx.createLinearGradient(0, -r - 2, 0, r + 2);
    skirt.addColorStop(0, '#e2e8f0');
    skirt.addColorStop(1, '#475569');
    ctx.fillStyle = skirt;
    ctx.beginPath(); ctx.arc(0, 0, r + 1.8 - glow * 0.8, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = 'rgba(15,23,42,0.6)';
    ctx.lineWidth = 0.4;
    for (let i = 0; i < 12; i++) {
        const a = i * Math.PI / 6;
        ctx.beginPath(); ctx.moveTo(Math.cos(a) * r, Math.sin(a) * r); ctx.lineTo(Math.cos(a) * (r + 1.6), Math.sin(a) * (r + 1.6)); ctx.stroke();
    }

    ctx.scale(k, k);
    ctx.shadowColor = '#f43f5e';
    ctx.shadowBlur = 3 + 16 * glow;
    const body = ctx.createRadialGradient(-r * 0.3, -r * 0.3, 1, 0, 0, r);
    body.addColorStop(0, glow > 0 ? '#fda4af' : '#fb7185');
    body.addColorStop(1, glow > 0 ? '#e11d48' : '#9f1239');
    ctx.fillStyle = body;
    ctx.beginPath(); ctx.arc(0, 0, r, 0, Math.PI * 2); ctx.fill();
    ctx.shadowBlur = 0;

    // Кольцо огоньков бежит по кругу, при ударе горят все.
    for (let i = 0; i < 8; i++) {
        const a = i * Math.PI / 4, on = glow > 0 || Math.floor(now / 120) % 8 === i;
        ctx.fillStyle = on ? '#fef08a' : 'rgba(254,240,138,0.25)';
        ctx.beginPath(); ctx.arc(Math.cos(a) * r * 0.82, Math.sin(a) * r * 0.82, 0.7, 0, Math.PI * 2); ctx.fill();
    }

    const cap = ctx.createRadialGradient(-1.6, -1.6, 0.4, 0, 0, r * 0.62);
    cap.addColorStop(0, '#ffffff');
    cap.addColorStop(1, glow > 0 ? '#fde047' : '#facc15');
    ctx.fillStyle = cap;
    ctx.beginPath(); ctx.arc(0, 0, r * 0.6, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = 'rgba(161,98,7,0.6)';
    ctx.lineWidth = 0.5;
    ctx.stroke();

    ctx.fillStyle = 'rgba(190,18,60,0.85)';
    ctx.beginPath();
    for (let i = 0; i < 10; i++) {
        const a = -Math.PI / 2 + i * Math.PI / 5 + now / 1500, rr = (i % 2 ? 0.14 : 0.36) * r;
        if (i) ctx.lineTo(Math.cos(a) * rr, Math.sin(a) * rr); else ctx.moveTo(Math.cos(a) * rr, Math.sin(a) * rr);
    }
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // Волна от удара.
    if (glow > 0) {
        ctx.strokeStyle = 'rgba(251,113,133,' + (0.8 * glow).toFixed(3) + ')';
        ctx.lineWidth = 1.2;
        ctx.beginPath(); ctx.arc(b[0], b[1], r + 3 + (1 - glow) * 12, 0, Math.PI * 2); ctx.stroke();
    }
}

/** Рогатка: пластик с винтами поверх треугольника, белая резинка на толкающей грани. */
function sling(ctx, s, glow) {
    const c = [(s[0] + s[2] + s[4]) / 3, (s[1] + s[3] + s[5]) / 3];
    const pg = ctx.createLinearGradient(s[0], s[1], s[4], s[5]);
    pg.addColorStop(0, glow > 0 ? 'rgba(196,181,253,0.85)' : 'rgba(139,92,246,0.7)');
    pg.addColorStop(1, 'rgba(76,29,149,0.85)');
    ctx.fillStyle = pg;
    path(ctx, [[s[0], s[1]], [s[2], s[3]], [s[4], s[5]]]);
    ctx.fill();
    rail(ctx, s[0], s[1], s[2], s[3]);
    rail(ctx, s[2], s[3], s[4], s[5]);

    // Блик на пластике и два винта.
    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.lineWidth = 0.8;
    ctx.beginPath(); ctx.moveTo(s[2] + (c[0] - s[2]) * 0.3, s[3] - 18); ctx.lineTo(s[2] + (c[0] - s[2]) * 0.3, s[3] - 4); ctx.stroke();
    for (const t of [0.3, 0.7]) {
        const x = s[2] + (c[0] - s[2]) * 0.55, y = s[1] + (s[3] - s[1]) * t + 4;
        ctx.fillStyle = '#e2e8f0';
        ctx.beginPath(); ctx.arc(x, y, 0.9, 0, Math.PI * 2); ctx.fill();
    }

    ctx.lineCap = 'round';
    ctx.shadowColor = '#fde047';
    ctx.shadowBlur = 12 * glow;
    ctx.strokeStyle = '#020617';
    ctx.lineWidth = 3;
    ctx.beginPath(); ctx.moveTo(s[0], s[1]); ctx.lineTo(s[4], s[5]); ctx.stroke();
    ctx.strokeStyle = glow > 0 ? '#fef08a' : '#f8fafc';
    ctx.lineWidth = 1.8;
    ctx.beginPath(); ctx.moveTo(s[0], s[1]); ctx.lineTo(s[4], s[5]); ctx.stroke();
    ctx.shadowBlur = 0;
}

/** Флиппер: тень, капсула с красной резинкой, полоса-логотип, болт на оси. */
function flipper(ctx, sim, f) {
    const p = FLIPPERS[f], a = sim.fa[f] * Math.PI * 2 / 256;
    const tx = p[0] + Math.cos(a) * p[2], ty = p[1] + Math.sin(a) * p[2];
    const r0 = FR + 1.4, r1 = FR - 0.6;

    function capsule(ox, oy) {
        ctx.beginPath();
        ctx.arc(p[0] + ox, p[1] + oy, r0, a + Math.PI / 2, a - Math.PI / 2);
        ctx.arc(tx + ox, ty + oy, r1, a - Math.PI / 2, a + Math.PI / 2);
        ctx.closePath();
    }

    ctx.fillStyle = 'rgba(0,0,0,0.4)';
    capsule(1, 2.2);
    ctx.fill();

    const nx = -Math.sin(a), ny = Math.cos(a);
    const g = ctx.createLinearGradient(p[0] - nx * r0, p[1] - ny * r0, p[0] + nx * r0, p[1] + ny * r0);
    g.addColorStop(0, '#ffffff');
    g.addColorStop(1, '#cbd5e1');
    ctx.fillStyle = g;
    capsule(0, 0);
    ctx.fill();
    ctx.strokeStyle = '#dc2626';
    ctx.lineWidth = 1.3;
    ctx.stroke();

    // Полоса-логотип вдоль флиппера.
    ctx.strokeStyle = 'rgba(37,99,235,0.85)';
    ctx.lineWidth = 0.9;
    ctx.beginPath();
    ctx.moveTo(p[0] + Math.cos(a) * 6, p[1] + Math.sin(a) * 6);
    ctx.lineTo(p[0] + Math.cos(a) * (p[2] - 5), p[1] + Math.sin(a) * (p[2] - 5));
    ctx.stroke();

    ctx.fillStyle = '#334155';
    ctx.beginPath(); ctx.arc(p[0], p[1], 1.9, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.75)';
    ctx.beginPath(); ctx.arc(p[0] - 0.5, p[1] - 0.5, 0.6, 0, Math.PI * 2); ctx.fill();
}

/** Стальной мяч: тень, металлический градиент, отражение ламп сверху и блик. */
function ball(ctx, x, y) {
    ctx.fillStyle = 'rgba(0,0,0,0.4)';
    ctx.beginPath(); ctx.ellipse(x + 1.3, y + 2, R, R * 0.7, 0, 0, Math.PI * 2); ctx.fill();

    const g = ctx.createRadialGradient(x - 1.4, y - 1.6, 0.3, x, y, R);
    g.addColorStop(0, '#ffffff');
    g.addColorStop(0.35, '#e2e8f0');
    g.addColorStop(0.8, '#94a3b8');
    g.addColorStop(1, '#334155');
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.arc(x, y, R, 0, Math.PI * 2); ctx.fill();

    ctx.save();
    ctx.beginPath(); ctx.arc(x, y, R, 0, Math.PI * 2); ctx.clip();
    ctx.fillStyle = 'rgba(167,139,250,0.45)';
    ctx.fillRect(x - R, y + R * 0.25, R * 2, R * 0.35);
    ctx.fillStyle = 'rgba(253,224,71,0.35)';
    ctx.fillRect(x - R, y - R * 0.15, R * 2, R * 0.18);
    ctx.restore();

    ctx.fillStyle = '#ffffff';
    ctx.beginPath(); ctx.arc(x - 1.3, y - 1.5, 0.8, 0, Math.PI * 2); ctx.fill();
}

/** Пускатель: пружина под мячом сжимается с зарядом, красная ручка. */
function plunger(ctx, sim) {
    const k = sim.charge / CHARGE, top = 237 + k * 2.5;
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    const turns = 5, h = 240 - top;
    for (let i = 0; i <= turns * 2; i++) ctx.lineTo(i % 2 ? 153 : 147, top + h * i / (turns * 2));
    ctx.stroke();
    ctx.fillStyle = '#ef4444';
    ctx.fillRect(146, top - 1, 8, 1.6);
}

/** Блик стекла: широкая косая полоса поверх всего стола. */
function glass(ctx) {
    ctx.save();
    path(ctx, FIELD);
    ctx.clip();
    const g = ctx.createLinearGradient(0, 0, W, H * 0.6);
    g.addColorStop(0, 'rgba(255,255,255,0)');
    g.addColorStop(0.45, 'rgba(255,255,255,0)');
    g.addColorStop(0.5, 'rgba(255,255,255,0.06)');
    g.addColorStop(0.6, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);
    ctx.restore();
}

/** Точечное табло (DMD): оранжевые цифры, поверх — сетка тёмных зазоров между точками. */
function dmd(ctx, text, x, y, w, h, color) {
    ctx.fillStyle = '#0a0a0a';
    ctx.fillRect(x - w / 2, y - h / 2, w, h);

    ctx.save();
    ctx.beginPath(); ctx.rect(x - w / 2, y - h / 2, w, h); ctx.clip();
    ctx.font = '800 ' + Math.round(h * 0.78) + 'px ui-monospace,Menlo,Consolas,monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.shadowColor = color;
    ctx.shadowBlur = 6;
    ctx.fillStyle = color;
    ctx.fillText(text, x, y + 1);
    ctx.shadowBlur = 0;

    ctx.strokeStyle = 'rgba(10,10,10,0.75)';
    ctx.lineWidth = 0.7;
    ctx.beginPath();
    for (let gx = x - w / 2; gx < x + w / 2; gx += 1.8) { ctx.moveTo(gx, y - h / 2); ctx.lineTo(gx, y + h / 2); }
    for (let gy = y - h / 2; gy < y + h / 2; gy += 1.8) { ctx.moveTo(x - w / 2, gy); ctx.lineTo(x + w / 2, gy); }
    ctx.stroke();
    ctx.restore();

    ctx.strokeStyle = 'rgba(148,163,184,0.5)';
    ctx.lineWidth = 1;
    ctx.strokeRect(x - w / 2 - 1, y - h / 2 - 1, w + 2, h + 2);
}

/** Боковые панели в духе backglass: хромовая рамка, неоновое название, табло счёта, заряд пускателя. */
function panels(ctx, sim, view, g, now) {
    if (g.side < 56) return;
    const w = g.side, top = HUD + 6, h = view.H - HUD - 12;

    for (const x of [6, view.W - 6 - w]) {
        const bg = ctx.createLinearGradient(x, top, x + w, top + h);
        bg.addColorStop(0, '#2e1065');
        bg.addColorStop(1, '#0b1026');
        ctx.fillStyle = bg;
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x, top, w, h, 8); else ctx.rect(x, top, w, h);
        ctx.fill();

        const frame = ctx.createLinearGradient(x, top, x, top + h);
        frame.addColorStop(0, '#e2e8f0');
        frame.addColorStop(0.5, '#64748b');
        frame.addColorStop(1, '#cbd5e1');
        ctx.strokeStyle = frame;
        ctx.lineWidth = 1.6;
        ctx.stroke();

        // Звёзды на стекле панели.
        ctx.fillStyle = 'rgba(255,255,255,0.5)';
        for (let i = 0; i < 9; i++) {
            ctx.globalAlpha = 0.2 + 0.3 * (0.5 + 0.5 * Math.sin(now / 600 + i * 2 + x));
            ctx.fillRect(x + 6 + (i * 37) % (w - 12), top + 6 + (i * 23) % (h - 12), 1, 1);
        }
        ctx.globalAlpha = 1;
    }

    const lx = 6 + w / 2;
    const flick = 0.75 + 0.25 * Math.sin(now / 300);
    ctx.font = 'italic 900 ' + Math.round(w * 0.19) + 'px ' + FONT;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#831843';
    ctx.strokeText('PINBALL', lx, top + h * 0.2);
    ctx.shadowColor = '#f472b6';
    ctx.shadowBlur = 10 * flick;
    ctx.fillStyle = '#fbcfe8';
    ctx.fillText('PINBALL', lx, top + h * 0.2);
    ctx.shadowBlur = 0;

    ctx.fillStyle = COLORS.muted;
    ctx.font = '600 9px ' + FONT;
    ctx.fillText('SCORE', lx, top + h * 0.46);
    dmd(ctx, String(sim.points).padStart(5, '0'), lx, top + h * 0.62, w - 16, Math.min(22, h * 0.16), '#fb923c');

    // Заряд пускателя: столбик из сегментов от зелёного к красному.
    const rx = view.W - 6 - w / 2;
    ctx.fillStyle = COLORS.muted;
    ctx.font = '600 9px ' + FONT;
    ctx.fillText('PULL', rx, top + h * 0.12);
    const segs = 12, bw = 12, bh = h * 0.74, bx = rx - bw / 2, by = top + h * 0.18, k = sim.charge / CHARGE;
    for (let i = 0; i < segs; i++) {
        const y = by + bh - (i + 1) * bh / segs, lit = i < Math.round(k * segs);
        ctx.fillStyle = lit ? (i < 6 ? '#34d399' : i < 10 ? '#facc15' : '#ef4444') : 'rgba(255,255,255,0.07)';
        ctx.fillRect(bx, y + 0.8, bw, bh / segs - 1.6);
    }
}

function render(ctx, sim, view) {
    const now = view.now;
    const a = sim.dead || sim.idle ? 0 : view.alpha;
    const g = geometry(view);
    const nb = sim.bumpers.length;

    ctx.fillStyle = COLORS.bg;
    ctx.fillRect(0, HUD, view.W, view.H - HUD);
    panels(ctx, sim, view, g, now);

    ctx.save();
    ctx.translate(g.x, g.y);
    ctx.scale(g.s, g.s);

    cabinet(ctx);
    playfield(ctx, sim, now);
    plunger(ctx, sim);

    // Вспышки: новые — всплывающие очки над бампером или рогаткой.
    for (let i = 0; i < sim.flash.length; i++) {
        if (sim.flash[i] > (fx.flash[i] || 0)) {
            const p = i < nb ? [sim.bumpers[i][0], sim.bumpers[i][1] - sim.bumpers[i][2] - 4]
                : [(SLINGS[i - nb][0] + SLINGS[i - nb][4]) / 2, SLINGS[i - nb][1] + 8];
            fx.pops.push({ x: p[0], y: p[1], text: '+' + (i < nb ? BUMP_POINTS : SLING_POINTS), born: now });
        }
        fx.flash[i] = sim.flash[i];
    }

    for (let i = 0; i < SLINGS.length; i++) sling(ctx, SLINGS[i], sim.flash[nb + i] / 8);
    for (const w of WALLS) rail(ctx, w[0], w[1], w[2], w[3]);
    gate(ctx, sim);
    for (const p of POSTS) post(ctx, p[0], p[1]);
    for (let i = 0; i < nb; i++) bumper(ctx, sim.bumpers[i], sim.flash[i] / 10, now);
    flipper(ctx, sim, 0);
    flipper(ctx, sim, 1);

    // Мяч между тиками и короткий след за ним.
    if (!sim.dead) {
        const x = (sim.bx + sim.vx * a) / FP, y = (sim.by + sim.vy * a) / FP;
        fx.trail.push({ x: x, y: y, t: now });
        fx.trail = fx.trail.filter(function (p) { return now - p.t < 90; });
        for (const p of fx.trail) {
            const k = 1 - (now - p.t) / 90;
            ctx.fillStyle = 'rgba(203,213,225,' + (0.25 * k).toFixed(3) + ')';
            ctx.beginPath(); ctx.arc(p.x, p.y, R * (0.5 + 0.5 * k), 0, Math.PI * 2); ctx.fill();
        }
        ball(ctx, x, y);
        fx.drainAt = 0;
    } else {
        fx.trail = [];
        if (!fx.drainAt) fx.drainAt = now;
        const k = Math.min(1, (now - fx.drainAt) / 300);
        ctx.save();
        ctx.translate(W / 2, H * 0.55);
        ctx.scale(0.6 + 0.4 * k, 0.6 + 0.4 * k);
        ctx.font = 'italic 900 26px ' + FONT;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#4c0519';
        ctx.strokeText('DRAIN', 0, 0);
        ctx.shadowColor = '#f43f5e';
        ctx.shadowBlur = 14;
        ctx.fillStyle = '#fda4af';
        ctx.fillText('DRAIN', 0, 0);
        ctx.restore();
    }

    // Всплывающие очки.
    fx.pops = fx.pops.filter(function (p) {
        const age = (now - p.born) / 700;
        if (age >= 1) return false;
        ctx.globalAlpha = 1 - age * age;
        ctx.font = '800 9px ' + FONT;
        ctx.textAlign = 'center';
        ctx.lineWidth = 2;
        ctx.strokeStyle = 'rgba(0,0,0,0.6)';
        ctx.strokeText(p.text, p.x, p.y - age * 10);
        ctx.fillStyle = '#fde047';
        ctx.fillText(p.text, p.x, p.y - age * 10);
        ctx.globalAlpha = 1;
        return true;
    });

    glass(ctx);
    ctx.restore();
}

createDriver({
    name: 'pinball',
    Sim:  PinballSim,
    pad:  { dpad: ['left', 'right'], big: true },
    // Флипперы ещё и на Z и /, пускатель — и на стрелке вниз.
    keys: { z: 'left', Z: 'left', 'я': 'left', 'Я': 'left', '/': 'right', m: 'right', M: 'right', 'ь': 'right', 'Ь': 'right', ArrowDown: 'big' },
    score: function (sim) { return sim.points; },
    render: render,
    controls: pinballControls,
    icon: function (ctx, x, y, r) {
        const g = ctx.createRadialGradient(x - r * 0.35, y - r * 0.4, 0.5, x, y, r);
        g.addColorStop(0, '#ffffff');
        g.addColorStop(1, '#64748b');
        ctx.fillStyle = g;
        ctx.beginPath(); ctx.arc(x, y, r * 0.85, 0, Math.PI * 2); ctx.fill();
    },
});
