import { createDriver, fitGrid, COLORS, HUD } from '../../assets/engine/src/index.js';
import { BattleCitySim, BRICK, STEEL, PLAYER_PERIOD, ENEMY_PERIOD, BULLET_PERIOD } from './sim.js';
import { battleCityControls } from './controls.js';

// Рисуем в клетках по C единиц, под холст масштабирует fitGrid.
const C   = 16;
const TPS = 20;

/** Плавный переход между клетками: симуляция двигает танки и пули рывками по клетке. */
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

const playerPos = smoother(PLAYER_PERIOD * 1000 / TPS);
const enemyPos  = smoother(ENEMY_PERIOD * 1000 / TPS);

function tank(ctx, x, y, dir, body, track) {
    ctx.save();
    ctx.translate(x + C / 2, y + C / 2);
    ctx.rotate(dir * Math.PI / 2);
    ctx.fillStyle = track;
    ctx.fillRect(-7, -7, 4, 14);
    ctx.fillRect(3, -7, 4, 14);
    ctx.fillStyle = body;
    ctx.fillRect(-4, -5, 8, 10);
    ctx.fillRect(-1.5, -8, 3, 6);
    ctx.fillStyle = 'rgba(0,0,0,0.25)';
    ctx.beginPath();
    ctx.arc(0, 0.5, 2.5, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
}

function render(ctx, sim, view) {
    const g = fitGrid(sim.cols, sim.rows, view.W, view.H);

    ctx.fillStyle = '#05070d';
    ctx.fillRect(0, HUD, view.W, view.H - HUD);

    ctx.save();
    ctx.translate(g.x, g.y);
    ctx.scale(g.c / C, g.c / C);

    for (let i = 0; i < sim.map.length; i++) {
        const x = (i % sim.cols) * C, y = Math.floor(i / sim.cols) * C;

        if (sim.map[i] === BRICK) {
            ctx.fillStyle = '#b45309';
            ctx.fillRect(x, y, C, C);
            ctx.fillStyle = '#78350f';
            for (let r = 0; r < 4; r++) {
                ctx.fillRect(x, y + r * 4 + 3, C, 1);
                ctx.fillRect(x + (r % 2 ? 4 : 10), y + r * 4, 1, 3);
            }
        } else if (sim.map[i] === STEEL) {
            ctx.fillStyle = '#94a3b8';
            ctx.fillRect(x, y, C, C);
            ctx.fillStyle = '#e2e8f0';
            ctx.fillRect(x + 3, y + 3, C / 2 - 4, C / 2 - 4);
            ctx.fillRect(x + C / 2 + 1, y + C / 2 + 1, C / 2 - 4, C / 2 - 4);
        }
    }

    const moving = !sim.idle && !sim.dead;

    sim.enemies.forEach(function (e, i) {
        if (!e[3]) return;
        const q = moving ? enemyPos(i, e[0], e[1], view.now) : [e[0], e[1]];
        tank(ctx, q[0] * C, q[1] * C, e[2], '#e5e7eb', '#9ca3af');
    });

    if (!sim.dead) {
        const p = moving ? playerPos('p', sim.player[0], sim.player[1], view.now) : [sim.player[0], sim.player[1]];
        tank(ctx, p[0] * C, p[1] * C, sim.player[2], COLORS.yellow, '#a16207');
    }

    const lead = moving ? (sim.tick % BULLET_PERIOD + view.alpha) / BULLET_PERIOD : 0;

    for (const b of sim.bullets) {
        const dx = [0, 1, 0, -1][b[2]], dy = [-1, 0, 1, 0][b[2]];
        ctx.fillStyle = b[3] < 0 ? COLORS.yellow : COLORS.text;
        ctx.fillRect((b[0] + dx * lead) * C + C / 2 - 1.5, (b[1] + dy * lead) * C + C / 2 - 1.5, 3, 3);
    }

    ctx.restore();
}

createDriver({
    name: 'battlecity',
    Sim:  BattleCitySim,
    pad:  { dpad: ['up', 'right', 'down', 'left'], big: true },
    render: render,
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.scale(0.55, 0.55);
        tank(ctx, x / 0.55 - C / 2, y / 0.55 - C / 2, 0, '#e5e7eb', '#9ca3af');
        ctx.restore();
    },
    controls: battleCityControls,
});
