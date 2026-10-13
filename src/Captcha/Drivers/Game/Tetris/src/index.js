import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { TetrisSim, SHAPES, COLS, ROWS } from './sim.js';

const PIECE_COLORS = [null, '#22d3ee', COLORS.yellow, COLORS.purple, COLORS.green, COLORS.red, COLORS.blue, COLORS.orange, '#475569'];

const KEYS = {
    ArrowUp: 0, w: 0, W: 0, x: 0, X: 0, 'ц': 0, 'ч': 0,
    ArrowRight: 1, d: 1, D: 1, 'в': 1,
    ArrowDown: 2, s: 2, S: 2, 'ы': 2,
    ArrowLeft: 3, a: 3, A: 3, 'ф': 3,
    ' ': 4,
};

function block(ctx, x, y, size, color) {
    ctx.fillStyle = color;
    ctx.fillRect(x + 1, y + 1, size - 2, size - 2);
    ctx.fillStyle = 'rgba(255,255,255,0.22)';
    ctx.fillRect(x + 1, y + 1, size - 2, 2);
}

/**
 * Стакан у тетриса всегда 8 × 14 — шире он только облегчит линию. Под холст подбирается
 * размер клетки: стакан и окошко следующей фигуры справа от него — по центру игровой зоны.
 */
function render(ctx, sim, view) {
    const C  = Math.max(4, Math.floor(Math.min((view.H - HUD - 4) / ROWS, view.W / (COLS + 5))));
    const P  = Math.round(C * 0.75);
    const bw = COLS * C;
    const bh = ROWS * C;
    const x0 = Math.floor((view.W - bw - C - 5 * P) / 2);
    const y0 = HUD + Math.floor((view.H - HUD - bh) / 2);

    ctx.fillStyle = COLORS.bg;
    ctx.fillRect(0, HUD, view.W, view.H - HUD);
    ctx.fillStyle = COLORS.board;
    ctx.fillRect(x0, y0, bw, bh);

    ctx.fillStyle = COLORS.grid;
    for (let i = 1; i < COLS; i++) ctx.fillRect(x0 + i * C, y0, 1, bh);
    for (let i = 1; i < ROWS; i++) ctx.fillRect(x0, y0 + i * C, bw, 1);

    for (let i = 0; i < COLS * ROWS; i++)
        if (sim.grid[i]) block(ctx, x0 + (i % COLS) * C, y0 + Math.floor(i / COLS) * C, C, PIECE_COLORS[sim.grid[i]]);

    if (!sim.dead) {
        let ghost = sim.y;
        while (!sim.collide(sim.x, ghost + 1, sim.rot)) ghost++;

        ctx.strokeStyle = 'rgba(230,238,248,0.25)';
        ctx.lineWidth = 1;
        for (const c of SHAPES[sim.type][sim.rot])
            ctx.strokeRect(x0 + (sim.x + c[0]) * C + 1.5, y0 + (ghost + c[1]) * C + 1.5, C - 3, C - 3);

        for (const c of SHAPES[sim.type][sim.rot])
            block(ctx, x0 + (sim.x + c[0]) * C, y0 + (sim.y + c[1]) * C, C, PIECE_COLORS[sim.type + 1]);
    }

    const px = x0 + bw + C;

    ctx.fillStyle = 'rgba(255,255,255,0.04)';
    ctx.fillRect(px, y0, 5 * P, 4 * P);
    for (const c of SHAPES[sim.next][0])
        block(ctx, px + P / 2 + c[0] * P, y0 + P + c[1] * P, P, PIECE_COLORS[sim.next + 1]);
}

createDriver({
    name: 'tetris',
    Sim:  TetrisSim,
    pad:  {
        dpad:    ['ArrowLeft', 'ArrowDown', 'ArrowRight'],
        actions: [{ key: 'ArrowUp', label: '⟳', big: true }, { key: ' ', label: '⤓' }],
        repeat:  ['ArrowLeft', 'ArrowRight', 'ArrowDown'],
    },
    render: render,
    icon: function (ctx, x, y, r) {
        ctx.fillStyle = COLORS.purple;
        ctx.fillRect(x - r, y, r * 2, r);
        ctx.fillRect(x - r / 3, y - r, r * 2 / 3, r);
    },
    controls: function (api) {
        return {
            key: function (key, down, repeat) {
                if (!(key in KEYS)) return false;
                if (down && !(repeat && (KEYS[key] === 0 || KEYS[key] === 4))) api.record(KEYS[key]);
                return true;
            },
            tap: function () { api.record(0); },
            swipe: function (dir) { api.record([4, 1, 2, 3][dir]); },
        };
    },
});
