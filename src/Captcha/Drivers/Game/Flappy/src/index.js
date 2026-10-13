import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { FlappySim, H, GROUND, FP, BX, R, PW, GAP, SPEED } from './sim.js';

const KEYS = { ' ': 1, ArrowUp: 1, w: 1, W: 1, 'ц': 1, 'Ц': 1, Enter: 1 };

function bird(ctx, x, y, r, tilt) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(tilt);
    ctx.fillStyle = COLORS.yellow;
    ctx.beginPath();
    ctx.arc(0, 0, r, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = COLORS.orange;
    ctx.beginPath();
    ctx.moveTo(r - 1, -2);
    ctx.lineTo(r + 5, 1);
    ctx.lineTo(r - 1, 4);
    ctx.fill();
    ctx.fillStyle = COLORS.board;
    ctx.beginPath();
    ctx.arc(r * 0.35, -r * 0.35, r * 0.18, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.55)';
    ctx.beginPath();
    ctx.ellipse(-r * 0.3, r * 0.15, r * 0.45, r * 0.3, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
}

function render(ctx, sim, view) {
    const a = sim.dead || sim.idle ? 0 : view.alpha;
    const W = sim.w;

    // Мир H единиц высотой — во всю игровую зону, его ширина под пропорции холста уже подобрана.
    const s = (view.H - HUD) / H;
    ctx.save();
    ctx.translate(0, HUD);
    ctx.scale(s, s);

    const sky = ctx.createLinearGradient(0, 0, 0, H);
    sky.addColorStop(0, '#0b1d3a');
    sky.addColorStop(1, '#173a63');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, Math.max(W, view.W / s), H);

    for (const pipe of sim.pipes) {
        const x = pipe[0] - SPEED * a;

        ctx.fillStyle = COLORS.green;
        ctx.fillRect(x, 0, PW, pipe[1]);
        ctx.fillRect(x, pipe[1] + GAP, PW, H - GROUND - pipe[1] - GAP);

        ctx.fillStyle = '#10b981';
        ctx.fillRect(x - 3, pipe[1] - 10, PW + 6, 10);
        ctx.fillRect(x - 3, pipe[1] + GAP, PW + 6, 10);
    }

    ctx.fillStyle = '#334155';
    ctx.fillRect(0, H - GROUND, W, GROUND);
    ctx.fillStyle = '#475569';
    const shift = ((sim.tick + a) * SPEED) % 16;
    for (let x = -shift; x < W; x += 16) ctx.fillRect(x, H - GROUND, 8, 4);

    const y    = (sim.y + sim.vy * a) / FP;
    const tilt = Math.max(-0.5, Math.min(1.1, sim.vy / FP / 9));
    bird(ctx, BX, sim.idle ? y + Math.sin(view.now / 200) * 3 : y, R + 1, tilt);

    ctx.restore();
}

createDriver({
    name: 'flappy',
    Sim:  FlappySim,
    pad:  { actions: [{ key: ' ', label: '▲', big: true }] },
    render: render,
    icon: function (ctx, x, y, r) { bird(ctx, x, y, r - 1, 0); },
    controls: function (api) {
        return {
            key: function (key, down, repeat) {
                if (!(key in KEYS)) return false;
                if (down && !repeat) api.record(0);
                return true;
            },
            press: function () { api.record(0); },
        };
    },
});
