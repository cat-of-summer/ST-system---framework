import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { ArkanoidSim, H, FP, PY, PW, PH, R, BW, BH, BGAP, BY0 } from './sim.js';
import { arkanoidControls } from './controls.js';

const ROW_COLORS = [COLORS.red, COLORS.orange, COLORS.yellow, COLORS.mint];

function render(ctx, sim, view) {
    const a    = sim.dead || sim.idle ? 0 : view.alpha;
    const cols = sim.layout.cols;

    // Мир H единиц высотой — во всю игровую зону, его ширина под пропорции холста уже подобрана.
    const s = (view.H - HUD) / H;
    ctx.save();
    ctx.translate(0, HUD);
    ctx.scale(s, s);

    ctx.fillStyle = COLORS.board;
    ctx.fillRect(0, 0, Math.max(sim.w, view.W / s), H);

    sim.bricks.forEach(function (on, i) {
        if (!on) return;
        const x = sim.layout.x0 + (i % cols) * (BW + BGAP);
        const y = BY0 + Math.floor(i / cols) * (BH + BGAP);
        ctx.fillStyle = ROW_COLORS[Math.floor(i / cols) % ROW_COLORS.length];
        ctx.fillRect(x, y, BW, BH);
        ctx.fillStyle = 'rgba(255,255,255,0.18)';
        ctx.fillRect(x, y, BW, 2);
    });

    ctx.fillStyle = COLORS.blue;
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(sim.px - PW / 2, PY, PW, PH, 4);
    else ctx.rect(sim.px - PW / 2, PY, PW, PH);
    ctx.fill();

    if (!sim.dead) {
        const bx = sim.idle ? sim.px : (sim.bx + sim.vx * a) / FP;
        const by = sim.idle ? PY - R : (sim.by + sim.vy * a) / FP;

        ctx.fillStyle = COLORS.text;
        ctx.beginPath();
        ctx.arc(bx, by, R, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.restore();
}

createDriver({
    name: 'arkanoid',
    Sim:  ArkanoidSim,
    pad:  { dpad: ['ArrowLeft', 'ArrowRight'], actions: [{ key: ' ', label: '●', big: true }] },
    // В HUD — разбитые кирпичи; цель (отбитые мячи) знает только сервер.
    score: function (sim) { return sim.broken; },
    render: render,
    icon: function (ctx, x, y, r) {
        ctx.fillStyle = COLORS.blue;
        ctx.fillRect(x - r, y + r / 2, r * 2, 3);
        ctx.fillStyle = COLORS.text;
        ctx.beginPath();
        ctx.arc(x, y - 1, r / 2, 0, Math.PI * 2);
        ctx.fill();
    },
    controls: arkanoidControls,
});
