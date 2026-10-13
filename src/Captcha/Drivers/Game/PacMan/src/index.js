import { createDriver, fitGrid, COLORS, HUD } from '../../assets/engine/src/index.js';
import { PacManSim } from './sim.js';

const GHOST_COLORS = [COLORS.red, '#f9a8d4'];

const KEYS = {
    ArrowUp: 0, w: 0, W: 0, 'ц': 0,
    ArrowRight: 1, d: 1, D: 1, 'в': 1,
    ArrowDown: 2, s: 2, S: 2, 'ы': 2,
    ArrowLeft: 3, a: 3, A: 3, 'ф': 3,
};

/** Плавный переход между клетками: симуляция двигает фигуры рывками по клетке. */
function smoother(ms) {
    const state = {};

    return function (key, x, y, now) {
        let s = state[key];

        if (!s || Math.abs(s.x - x) + Math.abs(s.y - y) > 1) s = state[key] = { x: x, y: y, fx: x, fy: y, t: now };
        else if (s.x !== x || s.y !== y) state[key] = s = { x: x, y: y, fx: s.x, fy: s.y, t: now };

        const k = Math.min(1, (now - s.t) / ms);
        return [s.fx + (x - s.fx) * k, s.fy + (y - s.fy) * k];
    };
}

const pacPos   = smoother(200);
const ghostPos = smoother(300);

function cherry(ctx, x, y, r) {
    ctx.strokeStyle = COLORS.green;
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.moveTo(x - r * 0.45, y + r * 0.1);
    ctx.quadraticCurveTo(x, y - r * 1.1, x + r * 0.6, y - r);
    ctx.moveTo(x + r * 0.45, y + r * 0.2);
    ctx.lineTo(x + r * 0.6, y - r);
    ctx.stroke();
    ctx.fillStyle = COLORS.red;
    ctx.beginPath();
    ctx.arc(x - r * 0.45, y + r * 0.4, r * 0.5, 0, Math.PI * 2);
    ctx.arc(x + r * 0.45, y + r * 0.5, r * 0.5, 0, Math.PI * 2);
    ctx.fill();
}

function pac(ctx, x, y, r, dir, mouth) {
    const angle = [-Math.PI / 2, 0, Math.PI / 2, Math.PI][dir < 0 ? 1 : dir];
    ctx.fillStyle = COLORS.yellow;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.arc(x, y, r, angle + mouth, angle + Math.PI * 2 - mouth);
    ctx.closePath();
    ctx.fill();
}

function ghost(ctx, x, y, r, color) {
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(x, y - r * 0.15, r, Math.PI, 0);
    ctx.lineTo(x + r, y + r);
    for (let i = 0; i < 3; i++) {
        ctx.lineTo(x + r - (i * 2 + 1) * r / 3, y + r * 0.6);
        ctx.lineTo(x + r - (i * 2 + 2) * r / 3, y + r);
    }
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.arc(x - r * 0.35, y - r * 0.2, r * 0.28, 0, Math.PI * 2);
    ctx.arc(x + r * 0.35, y - r * 0.2, r * 0.28, 0, Math.PI * 2);
    ctx.fill();
}

function render(ctx, sim, view) {
    const g = fitGrid(sim.cols, sim.rows, view.W, view.H);
    const C = g.c;

    ctx.fillStyle = COLORS.board;
    ctx.fillRect(0, HUD, view.W, view.H - HUD);

    for (let i = 0; i < sim.walls.length; i++) {
        const x = g.x + (i % sim.cols) * C, y = g.y + Math.floor(i / sim.cols) * C;

        if (sim.walls[i]) {
            ctx.fillStyle = '#1e3a8a';
            ctx.fillRect(x, y, C + 0.5, C + 0.5);
            ctx.fillStyle = '#2563eb';
            ctx.fillRect(x + C * 0.2, y + C * 0.2, C * 0.6, C * 0.6);
        } else if (sim.dots[i]) {
            ctx.fillStyle = '#fde68a';
            ctx.fillRect(x + C / 2 - 1, y + C / 2 - 1, 2, 2);
        }
    }

    cherry(ctx, g.x + sim.fx * C + C / 2, g.y + sim.fy * C + C / 2, C / 2 - 2);

    const moving = !sim.idle && !sim.dead;
    const p = moving ? pacPos('p', sim.px, sim.py, view.now) : [sim.px, sim.py];
    const mouth = moving ? 0.1 + 0.35 * Math.abs(Math.sin(view.now / 90)) : 0.3;
    pac(ctx, g.x + p[0] * C + C / 2, g.y + p[1] * C + C / 2, C / 2 - 1, sim.dir, mouth);

    sim.ghosts.forEach(function (gh, i) {
        const q = moving ? ghostPos(i, gh[0], gh[1], view.now) : [gh[0], gh[1]];
        ghost(ctx, g.x + q[0] * C + C / 2, g.y + q[1] * C + C / 2, C / 2 - 1, GHOST_COLORS[i % GHOST_COLORS.length]);
    });
}

createDriver({
    name: 'pacman',
    Sim:  PacManSim,
    pad:  { dpad: ['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft'] },
    render: render,
    icon: function (ctx, x, y, r) { cherry(ctx, x, y + 1, r); },
    controls: function (api) {
        return {
            key: function (key, down) {
                if (!(key in KEYS)) return false;
                if (down) api.record(KEYS[key]);
                return true;
            },
            swipe: function (dir) { api.record(dir); },
            tap: function (x, y) {
                const sim = api.sim(), g = fitGrid(sim.cols, sim.rows, api.W, api.H);
                const dx = x - (g.x + (sim.px + 0.5) * g.c), dy = y - (g.y + (sim.py + 0.5) * g.c);
                api.record(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 1 : 3) : (dy > 0 ? 2 : 0));
            },
        };
    },
});
