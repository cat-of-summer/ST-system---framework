import { createDriver, fitGrid, COLORS, HUD } from '../../assets/engine/src/index.js';
import { SnakeSim, DX, DY } from './sim.js';

const KEYS = {
    ArrowUp: 0, w: 0, W: 0, 'ц': 0, 'Ц': 0,
    ArrowRight: 1, d: 1, D: 1, 'в': 1, 'В': 1,
    ArrowDown: 2, s: 2, S: 2, 'ы': 2, 'Ы': 2,
    ArrowLeft: 3, a: 3, A: 3, 'ф': 3, 'Ф': 3,
};

function apple(ctx, x, y, r) {
    ctx.fillStyle = COLORS.red;
    ctx.beginPath();
    ctx.arc(x, y + r * 0.1, r, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = COLORS.green;
    ctx.fillRect(x - r * 0.08, y - r * 1.15, Math.max(1.5, r * 0.25), r * 0.5);
}

function render(ctx, sim, view) {
    const g = fitGrid(sim.cols, sim.rows, view.W, view.H);

    ctx.fillStyle = COLORS.board;
    ctx.fillRect(0, HUD, view.W, view.H - HUD);

    ctx.fillStyle = COLORS.grid;
    for (let i = 1; i < sim.cols; i++) ctx.fillRect(g.x + i * g.c, g.y, 1, sim.rows * g.c);
    for (let i = 1; i < sim.rows; i++) ctx.fillRect(g.x, g.y + i * g.c, sim.cols * g.c, 1);

    apple(ctx, g.x + (sim.ax + 0.5) * g.c, g.y + (sim.ay + 0.5) * g.c, g.c / 2 - 2);

    for (let i = sim.body.length - 1; i >= 0; i--) {
        ctx.fillStyle = i === 0 ? COLORS.green : COLORS.mint;
        ctx.beginPath();
        const x = g.x + sim.body[i][0] * g.c + 1, y = g.y + sim.body[i][1] * g.c + 1;
        if (ctx.roundRect) ctx.roundRect(x, y, g.c - 2, g.c - 2, g.c / 5);
        else ctx.rect(x, y, g.c - 2, g.c - 2);
        ctx.fill();
    }

    const head = sim.body[0], dx = DX[sim.dir], dy = DY[sim.dir], e = g.c / 7;
    const hx = g.x + (head[0] + 0.5) * g.c + dx * e, hy = g.y + (head[1] + 0.5) * g.c + dy * e;

    ctx.fillStyle = COLORS.board;
    ctx.beginPath();
    ctx.arc(hx - dy * e, hy + dx * e, g.c / 14, 0, Math.PI * 2);
    ctx.arc(hx + dy * e, hy - dx * e, g.c / 14, 0, Math.PI * 2);
    ctx.fill();
}

createDriver({
    name: 'snake',
    Sim:  SnakeSim,
    pad:  { dpad: ['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft'] },
    render: render,
    icon: function (ctx, x, y, r) { apple(ctx, x, y, r - 1); },
    controls: function (api) {
        return {
            key: function (key, down) {
                if (!(key in KEYS)) return false;
                if (down) api.record(KEYS[key]);
                return true;
            },
            swipe: function (dir) { api.record(dir); },
            tap: function (x, y) {
                // Тап — поворот к точке касания относительно головы.
                const sim = api.sim(), g = fitGrid(sim.cols, sim.rows, api.W, api.H), head = sim.body[0];
                const dx = x - (g.x + (head[0] + 0.5) * g.c), dy = y - (g.y + (head[1] + 0.5) * g.c);
                api.record(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 1 : 3) : (dy > 0 ? 2 : 0));
            },
        };
    },
});
