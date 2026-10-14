import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { InvadersSim, H, SHIP_Y, SW, SH, SX, SY } from './sim.js';
import { invadersControls } from './controls.js';

// Противник 7 × 5 точек (точка — 2 единицы мира), два кадра: строй «шагает».
const ALIEN = [
    ['..#.#..', '.#####.', '##.#.##', '#######', '.#...#.'],
    ['..#.#..', '#.###.#', '###.###', '.#####.', '#.....#'],
];
const ROW_COLORS = ['#c084fc', '#f472b6', COLORS.mint, '#38bdf8'];

function alien(ctx, x, y, frame, color) {
    ctx.fillStyle = color;
    const rows = ALIEN[frame];
    for (let r = 0; r < rows.length; r++)
        for (let c = 0; c < rows[r].length; c++)
            if (rows[r][c] === '#') ctx.fillRect(x + c * 2, y + r * 2, 2.05, 2.05);
}

/** Корабль: корпус с носом, кабина, стреловидные крылья, два сопла с пламенем. */
function ship(ctx, cx, y, now) {
    const half = SW / 2;

    // Пламя сопел — мерцает.
    const flame = 3 + (Math.sin(now / 45) + 1) * 1.5;
    ctx.fillStyle = COLORS.orange;
    ctx.fillRect(cx - 5, y + SH, 3, flame);
    ctx.fillRect(cx + 2, y + SH, 3, flame);
    ctx.fillStyle = COLORS.yellow;
    ctx.fillRect(cx - 4.5, y + SH, 2, flame * 0.6);
    ctx.fillRect(cx + 2.5, y + SH, 2, flame * 0.6);

    // Крылья.
    ctx.fillStyle = '#64748b';
    ctx.beginPath();
    ctx.moveTo(cx - 3, y + 4);
    ctx.lineTo(cx - half, y + SH - 1);
    ctx.lineTo(cx - half, y + SH + 1);
    ctx.lineTo(cx - 3, y + SH);
    ctx.closePath();
    ctx.moveTo(cx + 3, y + 4);
    ctx.lineTo(cx + half, y + SH - 1);
    ctx.lineTo(cx + half, y + SH + 1);
    ctx.lineTo(cx + 3, y + SH);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = COLORS.red;
    ctx.fillRect(cx - half, y + SH - 3, 2, 3);
    ctx.fillRect(cx + half - 2, y + SH - 3, 2, 3);

    // Корпус с носом.
    ctx.fillStyle = '#cbd5e1';
    ctx.beginPath();
    ctx.moveTo(cx, y - 3);
    ctx.lineTo(cx + 4, y + 4);
    ctx.lineTo(cx + 4, y + SH);
    ctx.lineTo(cx - 4, y + SH);
    ctx.lineTo(cx - 4, y + 4);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = '#94a3b8';
    ctx.fillRect(cx - 4, y + SH - 3, 8, 2);

    // Кабина.
    ctx.fillStyle = '#38bdf8';
    ctx.beginPath();
    ctx.ellipse(cx, y + 4, 1.8, 3, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.7)';
    ctx.fillRect(cx - 0.8, y + 2, 0.9, 1.6);
}

function render(ctx, sim, view) {
    const s = (view.H - HUD) / H;
    const W = Math.max(sim.w, view.W / s);

    ctx.save();
    ctx.translate(0, HUD);
    ctx.scale(s, s);

    ctx.fillStyle = '#05070f';
    ctx.fillRect(0, 0, W, H);

    // Звёзды — от их номера, без случайности.
    for (let i = 0; i < 60; i++) {
        const x = (i * 97) % W, y = (i * 53 + Math.floor(view.now / (40 + i % 5 * 20))) % H;
        ctx.fillStyle = i % 7 === 0 ? 'rgba(255,255,255,0.8)' : 'rgba(148,163,184,0.45)';
        ctx.fillRect(x, y, i % 7 === 0 ? 1.4 : 0.9, i % 7 === 0 ? 1.4 : 0.9);
    }

    const frame = Math.floor((sim.fx + sim.fy) / 4) % 2 === 0 ? 0 : 1;
    for (let i = 0; i < sim.alive.length; i++) {
        if (!sim.alive[i]) continue;
        const r = Math.floor(i / sim.cols);
        alien(ctx, sim.fx + (i % sim.cols) * SX, sim.fy + r * SY, frame, ROW_COLORS[r % ROW_COLORS.length]);
    }

    for (const b of sim.bombs) {
        ctx.fillStyle = COLORS.red;
        const wig = (Math.floor(b[1] / 3) % 2) * 2 - 1;
        ctx.fillRect(b[0] - 1 + wig, b[1] - 6, 2, 2);
        ctx.fillRect(b[0] - 1 - wig, b[1] - 4, 2, 2);
        ctx.fillRect(b[0] - 1 + wig, b[1] - 2, 2, 2);
    }

    if (sim.pby >= 0) {
        ctx.fillStyle = COLORS.yellow;
        ctx.fillRect(sim.pbx - 0.75, sim.pby, 1.5, 6);
    }

    if (!sim.dead) ship(ctx, sim.px, SHIP_Y, view.now);

    ctx.restore();
}

createDriver({
    name: 'invaders',
    Sim:  InvadersSim,
    pad:  { dpad: ['left', 'right'], big: true },
    render: render,
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.translate(x - 7, y - 5);
        alien(ctx, 0, 0, 0, '#c084fc');
        ctx.restore();
    },
    controls: invadersControls,
});
