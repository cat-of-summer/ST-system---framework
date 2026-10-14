import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { DinoSim, H, FP, GROUND, DX, DH, KINDS } from './sim.js';

// Динозавр 18 × 20 (единица мира — точка спрайта), смотрит вправо: # — тело, d — тень, l — брюхо,
// o — белок, p — зрачок. Хвост и ноги — по кадрам, поверх туловища.
const BODY = [
    '..........#######.',
    '.........##op#####',
    '.........##oo#####',
    '.........#########',
    '.........#####ddd.',
    '.........######...',
    '........######....',
    '.......#######dd..',
    '......########.d..',
    '...ll#########....',
    '...lllll#####.....',
    '...lllll####......',
    '..#lllll###.......',
    '...#######........',
    '....######........',
    '....#####.........',
];
// Хвост: опущен и поднят — машет на бегу.
const TAILS = [
    ['#.....', '#.....', '##....', '###...', '###...', '.##...', '..#...'],
    ['......', '#.....', '##....', '###...', '###...', '.##...', '..#...'],
];
const TAIL_ROW = 6;
// Ноги: четыре кадра бега, толчок вверх и приземление.
const LEGS = {
    run: [
        ['....##..##', '...##....#', '...#.....#', '..##.....#'],
        ['....####..', '....##.#..', '....#..##.', '...##.....'],
        ['....##..##', '....#...#.', '...##...#.', '...#....##'],
        ['....####..', '.....###..', '.....#.#..', '....##.##.'],
    ],
    up:   ['....######', '....#....#', '....##....', '..........'],
    down: ['....##..##', '....#....#', '....#....#', '...##...##'],
};
const PALETTE = { '#': '#84cc16', d: '#4d7c0f', l: '#d9f99d', o: '#f8fafc', p: '#0f172a' };

function pixels(ctx, rows, x, y, blink) {
    for (let r = 0; r < rows.length; r++)
        for (let c = 0; c < rows[r].length; c++) {
            let ch = rows[r][c];
            if (ch === '.') continue;
            if (blink && (ch === 'o' || ch === 'p')) ch = r === 2 ? 'd' : '#';
            ctx.fillStyle = PALETTE[ch];
            ctx.fillRect(x + c, y + r, 1.03, 1.03);
        }
}

/**
 * Динозавр с левым верхним углом в (x, y). pose — {legs, tail, blink, dead, tilt, squash}.
 * tilt — наклон в прыжке (радианы, вокруг середины), squash — сплющивание при приземлении.
 */
function dino(ctx, x, y, pose) {
    ctx.save();
    ctx.translate(x + 9, y + 20);
    if (pose.squash) ctx.scale(1 + pose.squash * 0.5, 1 - pose.squash);
    if (pose.tilt) ctx.rotate(pose.tilt);
    ctx.translate(-9, -20);

    pixels(ctx, BODY, 0, 0, pose.blink && !pose.dead);
    pixels(ctx, TAILS[pose.tail], 0, TAIL_ROW, false);
    pixels(ctx, pose.legs, 0, 16, false);

    if (pose.dead) {
        ctx.fillStyle = PALETTE.o;
        ctx.fillRect(11, 1, 2, 2);
        ctx.strokeStyle = PALETTE.p;
        ctx.lineWidth = 0.7;
        ctx.beginPath();
        ctx.moveTo(10.8, 0.8); ctx.lineTo(13.2, 3.2);
        ctx.moveTo(13.2, 0.8); ctx.lineTo(10.8, 3.2);
        ctx.stroke();
    }

    ctx.restore();
}

function pill(ctx, x, y, w, h) {
    const r = Math.min(w, h) / 2;
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(x, y, w, h, [r, r, 0.6, 0.6]);
    else ctx.rect(x, y, w, h);
    ctx.fill();
}

/**
 * Кактус по тем же размерам, что хитбоксы (DinoSim.boxes): стволы по 12 единиц (маленький — 10),
 * каждый второй ниже на 4; ствол шириной 4 по центру, руки — в поясе от 30 % до 60 % высоты.
 */
function cactus(ctx, x0, bottom, k) {
    const n = Math.max(1, Math.floor(k[1] / 12)), sw = Math.floor(k[1] / n);

    for (let i = 0; i < n; i++) {
        const x = x0 + i * sw, sh = k[2] - (i % 2) * 4, top = bottom - sh, tx = x + Math.floor((sw - 4) / 2);
        const a0 = top + Math.floor(sh * 3 / 10) + 2, a1 = top + Math.floor(sh * 6 / 10);

        ctx.fillStyle = '#16a34a';
        // Левая рука ниже, правая выше — живее, но в пределах пояса.
        pill(ctx, x + 0.5, a0 + 2, 2, a1 - a0 - 2);
        ctx.fillRect(x + 0.5, a1 - 2, tx - x, 2);
        pill(ctx, x + sw - 2.5, a0, 2, a1 - a0 - 3);
        ctx.fillRect(tx + 3, a1 - 4, x + sw - 2.5 - tx - 2, 2);

        ctx.fillStyle = '#22c55e';
        pill(ctx, tx, top, 4, sh);

        ctx.fillStyle = '#4ade80';
        ctx.fillRect(tx + 1, top + 2, 0.8, sh - 3);
        ctx.fillStyle = '#15803d';
        ctx.fillRect(tx + 3, top + 2, 0.7, sh - 3);

        // Колючки.
        ctx.fillStyle = 'rgba(240,253,244,0.75)';
        for (let yy = top + 3; yy < bottom - 2; yy += 4) {
            ctx.fillRect(tx - 0.6, yy, 0.6, 0.6);
            ctx.fillRect(tx + 4, yy + 2, 0.6, 0.6);
        }
    }

    ctx.fillStyle = 'rgba(148,163,184,0.35)';
    ctx.beginPath();
    ctx.ellipse(x0 + k[1] / 2, bottom, k[1] / 2 + 2, 1.5, 0, 0, Math.PI * 2);
    ctx.fill();
}

/** Птица летит влево: туловище в хитбоксе (без крыльев), крылья машут. */
function bird(ctx, x0, y0, w, h, flap) {
    const by = y0 + h - 5;

    ctx.fillStyle = '#94a3b8';
    ctx.beginPath();
    if (flap) { ctx.moveTo(x0 + 6, by); ctx.lineTo(x0 + 11, y0 - 2); ctx.lineTo(x0 + 13, by); }
    else      { ctx.moveTo(x0 + 6, by); ctx.lineTo(x0 + 11, by + 7); ctx.lineTo(x0 + 13, by); }
    ctx.fill();

    ctx.fillStyle = '#e2e8f0';
    ctx.beginPath();
    ctx.ellipse(x0 + 8, by, 7, 3, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(x0 + 14, by - 1); ctx.lineTo(x0 + w, by - 3); ctx.lineTo(x0 + w - 1, by + 1);
    ctx.fill();
    ctx.beginPath();
    ctx.arc(x0 + 2.5, by - 2, 2.5, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = COLORS.orange;
    ctx.beginPath();
    ctx.moveTo(x0 + 0.5, by - 2.5); ctx.lineTo(x0 - 3, by - 1.5); ctx.lineTo(x0 + 0.5, by - 0.5);
    ctx.fill();
    ctx.fillStyle = COLORS.board;
    ctx.fillRect(x0 + 1.6, by - 3, 1, 1);
}

/**
 * Состояние анимации между кадрами: пыль из-под ног и при приземлении, сплющивание, моргание.
 * Только для вида — на симуляцию не влияет.
 */
const fx = { last: 0, air: false, landAt: -1e9, dust: [], step: 0 };

function dust(x, y, n, spread) {
    for (let i = 0; i < n; i++)
        fx.dust.push({ x: x + (i - n / 2) * spread, y: y, vx: -0.02 - i * 0.008, vy: -0.015 - (i % 3) * 0.008, born: fx.last, r: 1 + (i % 2) * 0.6 });
}

function render(ctx, sim, view) {
    const a   = sim.dead || sim.idle ? 0 : view.alpha;
    const s   = (view.H - HUD) / H;
    const W   = Math.max(sim.w, view.W / s);
    const dt  = fx.last ? Math.min(50, view.now - fx.last) : 0;
    fx.last   = view.now;

    ctx.save();
    ctx.translate(0, HUD);
    ctx.scale(s, s);

    const sky = ctx.createLinearGradient(0, 0, 0, H);
    sky.addColorStop(0, '#0b1324');
    sky.addColorStop(1, '#1e293b');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, W, H);

    const run = (sim.clock * sim.speed + sim.speed * a) / FP;

    // Луна, облака и горы — медленнее земли: глубина.
    ctx.fillStyle = 'rgba(226,232,240,0.85)';
    ctx.beginPath();
    ctx.arc(W - 40, 30, 9, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#0b1324';
    ctx.beginPath();
    ctx.arc(W - 36, 27, 8, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = 'rgba(148,163,184,0.18)';
    for (let i = 0; i < 4; i++) {
        const x = ((i * 113 - run * 0.1) % (W + 60) + W + 60) % (W + 60) - 30, y = 22 + (i * 17) % 40;
        ctx.beginPath();
        ctx.ellipse(x, y, 14, 3.5, 0, 0, Math.PI * 2);
        ctx.ellipse(x + 8, y - 2.5, 8, 3, 0, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.fillStyle = 'rgba(51,65,85,0.6)';
    ctx.beginPath();
    ctx.moveTo(0, GROUND);
    for (let x = 0; x <= W + 6; x += 6) {
        const wx = x + run * 0.25;
        ctx.lineTo(x, GROUND - 22 - Math.abs(Math.sin(wx / 47)) * 18 - Math.sin(wx / 19) * 4);
    }
    ctx.lineTo(W + 6, GROUND);
    ctx.fill();

    // Земля: линия, полоса песка и бегущие камешки — по пройденному пути.
    ctx.fillStyle = '#1e293b';
    ctx.fillRect(0, GROUND, W, H - GROUND);
    ctx.fillStyle = '#64748b';
    ctx.fillRect(0, GROUND, W, 1.5);
    ctx.fillStyle = '#334155';
    for (let x = -(run % 23); x < W; x += 23) ctx.fillRect(x, GROUND + 5 + ((x + run) * 7 % 9 | 0) % 6, 3, 1.5);
    for (let x = -(run % 41); x < W; x += 41) ctx.fillRect(x + 11, GROUND + 12, 6, 1);

    for (const o of sim.obs) {
        const b = DinoSim.box([o[0] - sim.speed * a, o[1], 0]);
        const k = KINDS[o[1]];
        if (k[0] === 1) bird(ctx, b[0], b[1], k[1], k[2], (view.now / 140 | 0) % 2 === 0);
        else cactus(ctx, b[0], b[3], k);
    }

    // Динозавр: прыжок с наклоном, бег — четыре кадра по пройденному пути, корпус качается.
    const air   = sim.y < GROUND * FP || sim.vy < 0;
    const y     = (sim.y + (air ? sim.vy * a : 0)) / FP;
    const frame = Math.floor(run / 7) % 4;

    if (fx.air && !air && !sim.idle) { fx.landAt = view.now; dust(DX + 8, GROUND, 6, 2.2); }
    fx.air = air;
    if (!air && !sim.idle && !sim.dead && Math.floor(run / 14) !== fx.step) {
        fx.step = Math.floor(run / 14);
        if (fx.step % 2 === 0) dust(DX + 4, GROUND - 0.5, 1, 0);
    }

    const land  = (view.now - fx.landAt) / 140;
    const pose  = {
        legs:   air ? (sim.vy < 0 ? LEGS.up : LEGS.down) : (sim.idle || sim.dead ? LEGS.down : LEGS.run[frame]),
        tail:   sim.idle ? ((view.now / 600 | 0) % 2) : (air ? 1 : (frame >> 1)),
        blink:  (view.now % 3200) < 140,
        dead:   sim.dead > 0,
        tilt:   air ? Math.max(-0.22, Math.min(0.2, sim.vy / FP / 40)) : 0,
        squash: land < 1 ? 0.12 * (1 - land) : 0,
    };
    const bob   = !air && !sim.idle && !sim.dead && frame % 2 === 1 ? -1 : 0;
    const idle  = sim.idle ? Math.sin(view.now / 300) * 0.4 : 0;

    ctx.fillStyle = 'rgba(0,0,0,0.25)';
    ctx.beginPath();
    ctx.ellipse(DX + 9, GROUND + 0.5, 7 * Math.max(0.4, 1 - (GROUND - y) / 60), 1.2, 0, 0, Math.PI * 2);
    ctx.fill();

    dino(ctx, DX, y - DH + bob + idle, pose);

    // Пыль: уносится назад со скоростью земли и тает.
    const keep = [];
    for (const p of fx.dust) {
        const age = (view.now - p.born) / 450;
        if (age >= 1) continue;
        p.x += (p.vx * dt) - (sim.idle || sim.dead ? 0 : sim.speed / FP * dt / 33);
        p.y += p.vy * dt;
        ctx.fillStyle = 'rgba(203,213,225,' + (0.5 * (1 - age)).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r + age * 1.5, 0, Math.PI * 2);
        ctx.fill();
        keep.push(p);
    }
    fx.dust = keep;

    ctx.restore();
}

createDriver({
    name: 'dino',
    Sim:  DinoSim,
    pad:  { big: true },
    // Кроме пробела и Enter (большая кнопка) — прыжок стрелкой вверх, как привыкли.
    keys: { ArrowUp: 'big', w: 'big', W: 'big', 'ц': 'big', 'Ц': 'big' },
    render: render,
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.translate(x - r, y - r);
        ctx.scale(r * 2 / 20, r * 2 / 20);
        dino(ctx, 0, 0, { legs: LEGS.down, tail: 0 });
        ctx.restore();
    },
    controls: function (api) {
        return {
            button: function (name, down, repeat) {
                if (name !== 'big') return false;
                if (down && !repeat) api.record(0);
                return true;
            },
        };
    },
});
