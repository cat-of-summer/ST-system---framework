import { createDriver } from '../../assets/engine/src/index.js';
import { OrbitSim, FP, STEPS, ROCKET, ORBIT, FLIGHT, ALONG_SHOW, ALONG_CATCH, FAST_MAX, launchVelocity, preview } from './sim.js';
import { orbitControls, frame } from './controls.js';

const ROCKET_COLOR = '255, 59, 48';

/** Игровое время идёт в 0,6 от реального при полном темпе (60 тиков, шаг 1/100 с игры). */
const GAME_SPEED = 0.6;

const clamp  = function (v, lo, hi) { return Math.min(hi, Math.max(lo, v)); };
const smooth = function (t) { return t * t * (3 - 2 * t); };

/**
 * Насколько полёт похож на орбиту планеты p (только для вида, во float): along — доля скорости
 * вдоль планеты, fast — скорость к круговой на этой высоте, d — расстояние в единицах; null —
 * ракета вне поля.
 */
function likeness(sim, p) {
    const rx = (sim.x - p.x) / FP, ry = (sim.y - p.y) / FP, d = Math.hypot(rx, ry);
    if (d > p.field || d < 1) return null;
    const vx = sim.vx / FP, vy = sim.vy / FP, v = Math.hypot(vx, vy);
    return { d: d, along: v < 1 ? 1 : Math.abs(rx * vy - ry * vx) / (d * v), fast: v / Math.sqrt(300 * d) };
}

/**
 * Вид орбит поверх ввода (controls.js), как мини-игра 404 aerospace: белые планеты, красный
 * квадрат ракеты по направлению скорости, кольцо орбиты — пунктир, бегущий по направлению
 * вращения; в полёте кольцо проступает у планеты, когда траектория становится похожей на орбиту.
 * Пока тянут рогатку — линия натяжения, якорь, подсказка траектории точками и круг в точке удара.
 *
 * Размеры, которые в исходной игре заданы в пикселях экрана с короткой стороной 1000, здесь — в
 * единицах мира (× масштаб), но не тоньше читаемого на маленьком холсте.
 */
function orbitView(api) {
    const input = orbitControls(api);
    const ui    = input.ui;

    // Только для вида: кольца орбит (радиус и видимость 0…1) и время прошлого кадра.
    const fx = { sim: null, rings: [], last: 0 };

    function rings(sim, dt, running) {
        if (fx.sim !== sim) {
            fx.sim   = sim;
            fx.rings = sim.planets.map(function (p) { return { r: p.orbit, a: 0 }; });
        }

        sim.planets.forEach(function (p, i) {
            const ring = fx.rings[i];
            let target = 0;
            if (running && sim.mode === ORBIT && sim.planet === i) {
                target = 1;
                ring.r = sim.radius / FP;
            } else if (running && sim.mode === FLIGHT && i !== sim.ignore) {
                const like = likeness(sim, p);
                if (like && like.fast <= FAST_MAX / 10) {
                    target = smooth(clamp((like.along - ALONG_SHOW / 10) / ((ALONG_CATCH - ALONG_SHOW) / 10), 0, 1));
                    if (target > 0) ring.r = like.d;
                }
            }
            ring.a += (target - ring.a) * (1 - Math.exp(-dt * 6));
        });
    }

    function render(ctx, sim, view) {
        const now = view.now;
        const real = fx.last ? Math.min(50, now - fx.last) / 1000 : 0;
        fx.last = now;

        const live    = !sim.dead && !sim.idle;
        const a       = live ? view.alpha : 0;
        // Доля пути между двумя последними шагами физики: в замедлении шаг идёт несколько тиков.
        const k       = clamp((sim.tacc + a * sim.rate) / 1000, 0, 1);
        const time    = (sim.steps + k) / STEPS;
        const dt      = real * GAME_SPEED * (live ? sim.rate / 1000 : 1);
        const f       = frame(sim, view.W, view.H);
        const s       = f.s;
        const X       = function (x) { return f.x + x / FP * s; };
        const Y       = function (y) { return f.y + y / FP * s; };

        rings(sim, dt, !sim.dead);

        // Орбиты: прерывистое кольцо бежит по направлению вращения ракеты.
        ctx.save();
        ctx.lineWidth = Math.max(0.6, 1.5 * s);
        ctx.setLineDash([10 * s, 12 * s]);
        ctx.lineDashOffset = -time * 60 * s * sim.dir;
        sim.planets.forEach(function (p, i) {
            const ring = fx.rings[i];
            if (ring.a < 0.01) return;
            ctx.strokeStyle = 'rgba(255, 255, 255, ' + (0.6 * ring.a).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(X(p.x), Y(p.y), ring.r * s, 0, Math.PI * 2);
            ctx.stroke();
        });
        ctx.restore();

        // Планеты.
        ctx.fillStyle = '#fff';
        sim.planets.forEach(function (p) {
            ctx.beginPath();
            ctx.arc(X(p.x), Y(p.y), p.r * s, 0, Math.PI * 2);
            ctx.fill();
        });

        // Рогатка и подсказка траектории — пока ракету можно запустить.
        if (ui.aim && live && sim.mode === ORBIT) {
            const pull = input.pull(ui.aim);
            const tip  = { x: ui.aim.ax - pull.x * s, y: ui.aim.ay - pull.y * s };

            ctx.strokeStyle = 'rgba(255, 255, 255, ' + (pull.live ? 0.4 : 0.15) + ')';
            ctx.lineWidth = Math.max(0.5, s);
            ctx.beginPath();
            ctx.moveTo(ui.aim.ax, ui.aim.ay);
            ctx.lineTo(tip.x, tip.y);
            ctx.stroke();
            ctx.fillStyle = 'rgba(255, 255, 255, 0.5)';
            ctx.beginPath();
            ctx.arc(ui.aim.ax, ui.aim.ay, Math.max(1.5, 4 * s), 0, Math.PI * 2);
            ctx.fill();

            const v = pull.live && launchVelocity(pull.qx, pull.qy);
            if (v) {
                const path = preview(sim, sim.x, sim.y, v[0], v[1]);
                const n = path.points.length;
                path.points.forEach(function (pt, i) {
                    ctx.fillStyle = 'rgba(255, 255, 255, ' + (0.85 * (1 - i / n)).toFixed(3) + ')';
                    ctx.beginPath();
                    ctx.arc(X(pt[0]), Y(pt[1]), Math.max(0.8, 2 * s), 0, Math.PI * 2);
                    ctx.fill();
                });
                if (path.hit) {
                    ctx.strokeStyle = 'rgba(' + ROCKET_COLOR + ', 0.9)';
                    ctx.lineWidth = Math.max(0.6, 1.5 * s);
                    ctx.beginPath();
                    ctx.arc(X(path.hit[0]), Y(path.hit[1]), Math.max(3, 7 * s), 0, Math.PI * 2);
                    ctx.stroke();
                }
            }
        }

        // Ракета: красный квадрат по направлению скорости, между шагами физики; при смерти гаснет.
        const alpha = sim.dead ? sim.dead / sim.cooldown : 1;
        if (alpha > 0.01) {
            let vx = sim.vx, vy = sim.vy;
            if (sim.mode === ORBIT) {
                vx = (sim.radialV * sim.ox - sim.dir * sim.speed * sim.oy) / sim.radius;
                vy = (sim.radialV * sim.oy + sim.dir * sim.speed * sim.ox) / sim.radius;
            }
            const size = ROCKET * s;
            ctx.save();
            ctx.translate(X(sim.px + (sim.x - sim.px) * k), Y(sim.py + (sim.y - sim.py) * k));
            ctx.rotate(Math.atan2(vy, vx));
            ctx.fillStyle = 'rgba(' + ROCKET_COLOR + ', ' + alpha.toFixed(3) + ')';
            ctx.fillRect(-size / 2, -size / 2, size, size);
            ctx.restore();
        }
    }

    return Object.assign({}, input, { render: render });
}

createDriver({
    name: 'orbit',
    Sim:  OrbitSim,
    pointer: true,
    render: function () {},
    controls: orbitView,
    // Планета, пунктир орбиты и ракета на нём.
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.6)';
        ctx.lineWidth = 1;
        ctx.setLineDash([1.6, 1.4]);
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = '#fff';
        ctx.beginPath();
        ctx.arc(x, y, r * 0.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.translate(x + r * 0.71, y - r * 0.71);
        ctx.rotate(Math.PI / 4);
        ctx.fillStyle = 'rgb(' + ROCKET_COLOR + ')';
        ctx.fillRect(-1.6, -1.6, 3.2, 3.2);
        ctx.restore();
    },
});
