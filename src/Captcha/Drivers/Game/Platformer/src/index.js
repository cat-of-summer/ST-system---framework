import { createDriver, fitGrid, COLORS } from '../../assets/engine/src/index.js';
import { PlatformerSim, T, FP, PW, PH } from './sim.js';
import { platformerControls } from './controls.js';

// Герой 10 × 14 точек (точка — единица мира), смотрит вправо: большая голова в кепке с козырьком,
// глаза-точки, красный плащ. b — кепка, s — лицо, e — глаз, r — плащ, w — рубашка, d — штаны,
// k — ботинки. Ноги — по кадрам: стоит, два шага бега, прыжок.
const HEAD = [
    '...bbbbb..',
    '..bbbbbbbb',
    '..sssssss.',
    '..ssssese.',
    '..sssssss.',
    '...sssss..',
];
const BODY = {
    still: ['.rrwwwww..', 'rrrwwwwws.', 'rrrwwwww..', 'rr.wwwww..'],
    jump:  ['rrrwwwww..', 'rr.wwwwws.', 'r..wwwww..', '...wwwww..'],
};
const LEGS = {
    still: ['...ddddd..', '...dd.dd..', '...dd.dd..', '..kkk.kkk.'],
    run1:  ['...ddddd..', '..dd..dd..', '.dd....dd.', 'kk......kk'],
    run2:  ['...ddddd..', '....ddd...', '....dd....', '...kkk....'],
    jump:  ['...ddddd..', '..dd..dd..', '..kk..kk..', '..........'],
};
const HERO = { b: '#2563eb', s: '#fde4c8', e: '#0f172a', r: COLORS.red, w: '#f8fafc', d: '#334155', k: '#78350f' };

function hero(ctx, x, y, face, pose) {
    const rows = HEAD.concat(BODY[pose === 'jump' ? 'jump' : 'still'], LEGS[pose]);

    ctx.save();
    ctx.translate(x + (face < 0 ? PW : 0), y);
    if (face < 0) ctx.scale(-1, 1);
    for (let r = 0; r < rows.length; r++)
        for (let c = 0; c < rows[r].length; c++) {
            const ch = rows[r][c];
            if (ch === '.') continue;
            ctx.fillStyle = HERO[ch];
            ctx.fillRect(c, r, 1.03, 1.03);
        }
    ctx.restore();
}

/** Столбец земли: верхний ряд — трава, ниже — кирпичи. */
function ground(ctx, sim, c) {
    const top = (sim.rows - sim.g[c]) * T;
    for (let r = sim.rows - sim.g[c]; r < sim.rows; r++) {
        const y = r * T, x = c * T;
        ctx.fillStyle = '#7c4a2a';
        ctx.fillRect(x, y, T, T);
        ctx.fillStyle = '#5b341c';
        const shift = r % 2 === 0 ? 0 : T / 2;
        ctx.fillRect(x, y + T / 2 - 0.5, T, 1);
        ctx.fillRect(x + (shift + 4) % T, y, 1, T / 2);
        ctx.fillRect(x + (shift + 12) % T, y + T / 2, 1, T / 2);
    }
    ctx.fillStyle = '#22c55e';
    ctx.fillRect(c * T, top, T, 4);
    ctx.fillStyle = '#16a34a';
    ctx.fillRect(c * T, top + 4, T, 1.5);

    // Края столбца темнее, если сосед ниже: видна ступень.
    ctx.fillStyle = 'rgba(0,0,0,0.25)';
    if (c > 0 && sim.g[c - 1] < sim.g[c]) ctx.fillRect(c * T, top, 1.5, sim.g[c] * T);
    if (c < sim.len - 1 && sim.g[c + 1] < sim.g[c]) ctx.fillRect(c * T + T - 1.5, top, 1.5, sim.g[c] * T);
}

/** Шипы на земле: три зубца высотой 9. */
function spikes(ctx, x, top) {
    ctx.fillStyle = '#e2e8f0';
    ctx.beginPath();
    for (let i = 0; i < 3; i++) {
        const x0 = x + 1 + i * 14 / 3;
        ctx.moveTo(x0, top);
        ctx.lineTo(x0 + 7 / 3, top - 9);
        ctx.lineTo(x0 + 14 / 3, top);
    }
    ctx.fill();
    ctx.fillStyle = '#94a3b8';
    ctx.fillRect(x + 1, top - 1.5, T - 2, 1.5);
}

function coin(ctx, cx, cy, r, now) {
    const w = Math.max(0.15, Math.abs(Math.cos(now / 260)));
    ctx.fillStyle = COLORS.orange;
    ctx.beginPath();
    ctx.ellipse(cx, cy, r * w, r, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = COLORS.yellow;
    ctx.beginPath();
    ctx.ellipse(cx, cy, r * w * 0.75, r * 0.75, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.fillRect(cx - r * w * 0.35, cy - r * 0.45, Math.max(0.6, r * w * 0.2), r * 0.6);
}

/**
 * Позиция героя сглаживается между тиками: рисуется путь от прошлого тика к текущему (на тик
 * позже симуляции). Скачок дальше клетки — перезапуск уровня, его не сглаживаем.
 */
function smoother() {
    let tick = -1, px = 0, py = 0, cx = 0, cy = 0;

    return function (sim, alpha) {
        if (sim.tick !== tick) {
            const jump = Math.abs(sim.x - cx) > T * FP || Math.abs(sim.y - cy) > T * FP;
            px = jump ? sim.x : cx;
            py = jump ? sim.y : cy;
            cx = sim.x;
            cy = sim.y;
            tick = sim.tick;
        }
        const a = sim.dead || sim.idle ? 1 : alpha;
        return [(px + (cx - px) * a) / FP, (py + (cy - py) * a) / FP];
    };
}

const smooth = smoother();

/**
 * Камера: после монетки экран плавно уезжает вправо на следующий уровень (SCROLL мс), после
 * последнего — сразу на первый.
 */
const SCROLL = 600;
const camera = { from: 0, to: 0, at: 0 };

function cameraX(sim, now) {
    const to = sim.base() * T;
    if (to !== camera.to) {
        camera.from = to > camera.to ? cameraNow(now) : to;
        camera.to   = to;
        camera.at   = now;
    }
    return cameraNow(now);
}

function cameraNow(now) {
    const k = Math.min(1, (now - camera.at) / SCROLL);
    const e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
    return camera.from + (camera.to - camera.from) * e;
}

function render(ctx, sim, view) {
    const g   = fitGrid(sim.cols, sim.rows, view.W, view.H);
    const s   = g.c / T;
    const cam = cameraX(sim, view.now);

    const sky = ctx.createLinearGradient(0, g.y, 0, g.y + sim.rows * g.c);
    sky.addColorStop(0, '#0b1324');
    sky.addColorStop(1, '#1e3a5f');
    ctx.fillStyle = sky;
    ctx.fillRect(0, g.y, view.W, sim.rows * g.c);

    ctx.save();
    ctx.beginPath();
    ctx.rect(g.x, g.y, sim.cols * g.c, sim.rows * g.c);
    ctx.clip();
    ctx.translate(g.x, g.y);
    ctx.scale(s, s);

    // Звёзды и далёкие холмы — от номера, без случайности, с параллаксом.
    const W = sim.cols * T;
    for (let i = 0; i < 40; i++) {
        ctx.fillStyle = i % 6 === 0 ? 'rgba(255,255,255,0.7)' : 'rgba(148,163,184,0.4)';
        const x = ((i * 83 - cam * 0.15) % W + W) % W;
        ctx.fillRect(x, (i * 37) % (sim.rows * T * 0.5), 1, 1);
    }
    ctx.fillStyle = 'rgba(51,65,85,0.55)';
    ctx.beginPath();
    ctx.moveTo(0, sim.rows * T);
    for (let x = 0; x <= W + 8; x += 8) {
        const wx = x + cam * 0.4;
        ctx.lineTo(x, sim.rows * T * 0.62 + Math.sin(wx / 37) * 9 + Math.sin(wx / 13) * 3);
    }
    ctx.lineTo(W + 8, sim.rows * T);
    ctx.fill();

    ctx.translate(-cam, 0);

    const c0 = Math.max(0, Math.floor(cam / T)), c1 = Math.min(sim.len - 1, Math.ceil(cam / T) + sim.cols);
    for (let c = c0; c <= c1; c++) {
        if (sim.g[c] > 0) ground(ctx, sim, c);
        if (sim.spikes[c]) spikes(ctx, c * T, (sim.rows - sim.g[c]) * T);
    }

    // Монетки текущего экрана и следующего — пока экран едет, новая уже видна.
    for (let k = 0; k < 2; k++) {
        const coinC = sim.coin() + k * sim.shift;
        if (coinC >= sim.len || (k > 0 && coinC * T > cam + W)) continue;
        const cy = (sim.rows - sim.g[coinC] - 1) * T;
        coin(ctx, coinC * T + T / 2, cy + T / 2 + Math.sin(view.now / 300) * 1.5, 5, view.now);
    }

    if (!sim.dead) {
        const p    = smooth(sim, view.alpha);
        const pose = !sim.floor ? 'jump' : (sim.dir && !sim.idle ? ((view.now / 110 | 0) % 2 ? 'run1' : 'run2') : 'still');
        hero(ctx, p[0], p[1] - PH, sim.face, pose);
    }

    ctx.restore();
}

createDriver({
    name: 'platformer',
    Sim:  PlatformerSim,
    pad:  { dpad: ['left', 'right'], big: true },
    // Кроме пробела и Enter (большая кнопка) — прыжок стрелкой вверх, как привыкли.
    keys: { ArrowUp: 'big', w: 'big', W: 'big', 'ц': 'big', 'Ц': 'big' },
    render: render,
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.translate(x - r * 10 / 14, y - r);
        ctx.scale(r * 2 / 14, r * 2 / 14);
        hero(ctx, 0, 0, 1, 'still');
        ctx.restore();
    },
    controls: platformerControls,
});
