import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { AsteroidsSim, H, FP, RX, ROCKET, NOSE, PSPEED, SHOT, HIT, SHRINK, BOOM, DODGE } from './sim.js';
import { asteroidsControls } from './controls.js';

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

// Скорость мира в единицах за миллисекунду: тиков 30 в секунду.
const PER_MS = 30 / 1000;
const TAU    = Math.PI * 2;

// Камень: [основной, светлый, тёмный] — у каждого астероида свой оттенок от id.
const ROCK = [
    ['#8b7d6b', '#c2b39b', '#3f362c'],
    ['#7c7f86', '#b9bcc4', '#33363c'],
    ['#8a6f5a', '#c9a88c', '#3d2e24'],
];

/** Псевдослучайное 0…1 от двух чисел — для вида, без влияния на игру. */
function hash(a, b) {
    const s = Math.sin(a * 127.1 + b * 311.7) * 43758.5453;
    return s - Math.floor(s);
}

/** Контур астероида в единицах мира между тиками: целочисленный hull() сдвинут на v·a и довёрнут на spin·a. */
function contour(r, a) {
    const cx = r.x / FP, cy = r.y / FP;
    const dx = r.vx * a / FP, dy = r.vy * a / FP;
    const da = r.spin * a / 8 / 256 * TAU, c = Math.cos(da), s = Math.sin(da);

    return AsteroidsSim.hull(r).map(function (p) {
        const x = p[0] / FP - cx, y = p[1] / FP - cy;
        return [cx + dx + x * c - y * s, cy + dy + x * s + y * c];
    });
}

function path(ctx, pts) {
    ctx.beginPath();
    ctx.moveTo(pts[0][0], pts[0][1]);
    for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
    ctx.closePath();
}

/**
 * Астероид по контуру: свет сверху-слева (в мире, не вращается), кратеры и трещины вращаются
 * с телом. cracks — сколько попаданий принял в нынешнем размере.
 */
function rock(ctx, pts, cx, cy, radius, angle, id, cracks, flash) {
    const pal = ROCK[id % ROCK.length];

    path(ctx, pts);
    const g = ctx.createRadialGradient(cx - radius * 0.45, cy - radius * 0.5, radius * 0.1, cx, cy, radius * 1.25);
    g.addColorStop(0, pal[1]);
    g.addColorStop(0.5, pal[0]);
    g.addColorStop(1, pal[2]);
    ctx.fillStyle = g;
    ctx.fill();

    ctx.save();
    ctx.clip();

    // Кратеры: тёмная чаша и светлый край с освещённой стороны.
    const n = 3 + id % 3;
    for (let k = 0; k < n; k++) {
        const t = angle + hash(id, k) * TAU, d = radius * (0.15 + hash(k, id) * 0.5), cr = radius * (0.12 + hash(id + 7, k) * 0.14);
        const x = cx + Math.cos(t) * d, y = cy + Math.sin(t) * d;
        ctx.fillStyle = 'rgba(0,0,0,0.28)';
        ctx.beginPath();
        ctx.arc(x, y, cr, 0, TAU);
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,0.16)';
        ctx.lineWidth = cr * 0.35;
        ctx.beginPath();
        ctx.arc(x, y, cr, Math.PI * 0.1, Math.PI * 1.1, true);
        ctx.stroke();
    }

    // Трещины от края к центру — по одной на попадание.
    ctx.strokeStyle = 'rgba(20,12,6,0.55)';
    ctx.lineWidth = 0.7;
    for (let k = 0; k < cracks; k++) {
        const t = angle + hash(id * 3 + k, 9) * TAU, len = radius * (0.45 + hash(k, id * 5) * 0.35);
        let x = cx + Math.cos(t) * radius, y = cy + Math.sin(t) * radius;
        ctx.beginPath();
        ctx.moveTo(x, y);
        for (let s = 1; s <= 3; s++) {
            const tt = t + (hash(id + s, k) - 0.5) * 0.9;
            x -= Math.cos(tt) * len / 3;
            y -= Math.sin(tt) * len / 3;
            ctx.lineTo(x, y);
        }
        ctx.stroke();
    }

    // Тень с неосвещённой стороны.
    const sh = ctx.createLinearGradient(cx - radius, cy - radius, cx + radius, cy + radius);
    sh.addColorStop(0.55, 'rgba(0,0,0,0)');
    sh.addColorStop(1, 'rgba(0,0,0,0.35)');
    ctx.fillStyle = sh;
    ctx.fillRect(cx - radius * 1.5, cy - radius * 1.5, radius * 3, radius * 3);

    if (flash > 0) {
        ctx.fillStyle = 'rgba(255,240,220,' + (0.7 * flash).toFixed(3) + ')';
        ctx.fillRect(cx - radius * 1.5, cy - radius * 1.5, radius * 3, radius * 3);
    }
    ctx.restore();

    path(ctx, pts);
    ctx.strokeStyle = pal[2];
    ctx.lineWidth = 0.8;
    ctx.stroke();
}

/** Ракета точно по контуру ROCKET: корпус, красный нос, иллюминатор, крылья; пламя и дюзы — снаружи контура. */
function rocket(ctx, x, y, now, move, firing) {
    ctx.save();
    ctx.translate(x, y);

    // Пламя основной дюзы.
    const f = 6 + Math.sin(now / 37) * 1.5 + Math.sin(now / 13) * 1;
    const fl = ctx.createLinearGradient(-11, 0, -11 - f, 0);
    fl.addColorStop(0, '#fff7cc');
    fl.addColorStop(0.35, COLORS.yellow);
    fl.addColorStop(0.7, COLORS.orange);
    fl.addColorStop(1, 'rgba(251,146,60,0)');
    ctx.fillStyle = fl;
    ctx.beginPath();
    ctx.moveTo(-11, -3);
    ctx.quadraticCurveTo(-11 - f * 0.6, -2.5, -11 - f, 0);
    ctx.quadraticCurveTo(-11 - f * 0.6, 2.5, -11, 3);
    ctx.closePath();
    ctx.fill();

    // Боковая дюза: ракета идёт вверх — выхлоп вниз, и наоборот.
    if (move) {
        const side = move < 0 ? 1 : -1, p = 2.5 + Math.sin(now / 25) * 0.8;
        ctx.fillStyle = 'rgba(186,230,253,0.75)';
        ctx.beginPath();
        ctx.moveTo(-3, side * 4);
        ctx.lineTo(-1.5, side * (4 + p));
        ctx.lineTo(0, side * 4);
        ctx.closePath();
        ctx.fill();
    }

    // Крылья.
    for (const part of [ROCKET[1], ROCKET[2]]) {
        path(ctx, part);
        ctx.fillStyle = '#dc2f45';
        ctx.fill();
        ctx.strokeStyle = '#7f1d2d';
        ctx.lineWidth = 0.5;
        ctx.stroke();
    }

    // Корпус: металл с бликом сверху.
    path(ctx, ROCKET[0]);
    const g = ctx.createLinearGradient(0, -4, 0, 4);
    g.addColorStop(0, '#f8fafc');
    g.addColorStop(0.45, '#cbd5e1');
    g.addColorStop(1, '#64748b');
    ctx.fillStyle = g;
    ctx.fill();

    ctx.save();
    ctx.clip();
    ctx.fillStyle = '#e11d48';
    ctx.fillRect(8, -5, 8, 10);
    ctx.fillStyle = '#475569';
    ctx.fillRect(-11, -5, 2.2, 10);
    ctx.restore();

    path(ctx, ROCKET[0]);
    ctx.strokeStyle = '#334155';
    ctx.lineWidth = 0.5;
    ctx.stroke();

    // Иллюминатор.
    ctx.fillStyle = '#1e3a8a';
    ctx.beginPath();
    ctx.arc(2, 0, 2.3, 0, TAU);
    ctx.fill();
    ctx.fillStyle = '#7dd3fc';
    ctx.beginPath();
    ctx.arc(2, 0, 1.6, 0, TAU);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.8)';
    ctx.beginPath();
    ctx.arc(1.4, -0.6, 0.55, 0, TAU);
    ctx.fill();

    // Вспышка у носа на выстреле.
    if (firing > 0) {
        ctx.globalAlpha = firing;
        ctx.fillStyle = '#fef9c3';
        ctx.shadowColor = '#fde047';
        ctx.shadowBlur = 6;
        ctx.beginPath();
        ctx.arc(NOSE + 1, 0, 1.8 + firing, 0, TAU);
        ctx.fill();
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
    }

    ctx.restore();
}

/**
 * Вид поверх ввода: звёзды с параллаксом, туманность, астероиды и эффекты — искры попаданий,
 * обломки при уменьшении, взрывы с ударной волной, «+1», гибель ракеты с тряской кадра. Эффекты
 * читают события симуляции (sim.events) и последний виденный контур астероида.
 */
function asteroidsView(api) {
    const input = asteroidsControls(api);

    const fx = {
        last: 0, clock: -1, seen: new Map(), start: new Map(), flash: new Map(),
        parts: [], sparks: [], dust: [], rings: [], texts: [], deadAt: 0, shotAt: 0,
    };

    /** Куски контура pts с центром (cx, cy): треугольники «две вершины — точка к центру», разлетаются. */
    function shatter(pts, cx, cy, vx, vy, every, speed, pal, now) {
        for (let k = 0; k < pts.length; k += every) {
            const a = pts[k], b = pts[(k + 1) % pts.length], inner = 0.35 + hash(k, cx) * 0.3;
            const mx = (a[0] + b[0]) / 2, my = (a[1] + b[1]) / 2;
            const nx = mx - cx, ny = my - cy, len = Math.hypot(nx, ny) || 1;
            const poly = [[a[0] - mx, a[1] - my], [b[0] - mx, b[1] - my], [cx + nx * inner - mx, cy + ny * inner - my]];
            const sp = speed * (0.6 + hash(cy, k) * 0.8);
            fx.parts.push({ poly: poly, x: mx, y: my, vx: vx + nx / len * sp, vy: vy + ny / len * sp, a: 0,
                spin: (hash(k, cy) - 0.5) * 0.02, color: pal, born: now, life: 700 + hash(cx, k) * 500 });
        }
    }

    function sparks(x, y, n, speed, color, now, back) {
        for (let i = 0; i < n; i++) {
            const t = back ? Math.PI + (hash(x + i, y) - 0.5) * 2.2 : hash(i, x + y) * TAU;
            const sp = speed * (0.4 + hash(y, i) * 0.9);
            fx.sparks.push({ x: x, y: y, vx: Math.cos(t) * sp, vy: Math.sin(t) * sp, color: color, born: now, life: 220 + hash(i, y) * 260 });
        }
    }

    function dust(x, y, r, n, now) {
        for (let i = 0; i < n; i++) {
            const t = hash(i, x) * TAU, d = r * hash(y, i) * 0.6;
            fx.dust.push({ x: x + Math.cos(t) * d, y: y + Math.sin(t) * d, vx: Math.cos(t) * 0.006, vy: Math.sin(t) * 0.006,
                r: r * (0.25 + hash(x, i) * 0.25), born: now, life: 700 + hash(i, y) * 400 });
        }
    }

    function text(x, y, label, color, now) {
        fx.texts.push({ x: x, y: y, text: label, color: color, born: now });
    }

    /** Новые события симуляции — в эффекты; новая жизнь (clock пошёл заново) — начать сначала. */
    function events(sim, now) {
        if (sim.clock < fx.clock) fx.clock = -1;

        for (const e of sim.events) {
            if (e[0] <= fx.clock) continue;
            const x = e[2] / FP, y = e[3] / FP, seen = fx.seen.get(e[4]);

            if (e[1] === HIT) {
                sparks(x, y, 7, 0.09, '#fde68a', now, true);
                fx.flash.set(e[4], now);
            } else if (e[1] === SHRINK && seen) {
                shatter(seen.pts, seen.cx, seen.cy, seen.vx, seen.vy, 2, 0.025, ROCK[e[4] % ROCK.length], now);
                dust(seen.cx, seen.cy, seen.r, 6, now);
            } else if (e[1] === BOOM && seen) {
                shatter(seen.pts, seen.cx, seen.cy, seen.vx, seen.vy, 1, 0.05, ROCK[e[4] % ROCK.length], now);
                dust(seen.cx, seen.cy, seen.r, 10, now);
                sparks(seen.cx, seen.cy, 16, 0.12, '#fdba74', now, false);
                fx.rings.push({ x: seen.cx, y: seen.cy, r: seen.r, born: now, flash: true });
                text(seen.cx, seen.cy - seen.r, '+1', COLORS.yellow, now);
            } else if (e[1] === DODGE) {
                fx.rings.push({ x: x, y: y, r: 10, born: now, flash: false });
                text(x + 4, y - 14, '+1', COLORS.mint, now);
            }
        }

        fx.clock = sim.clock;
    }

    function stars(ctx, W, now) {
        const sky = ctx.createLinearGradient(0, 0, 0, H);
        sky.addColorStop(0, '#04060f');
        sky.addColorStop(1, '#0a1022');
        ctx.fillStyle = sky;
        ctx.fillRect(0, 0, W, H);

        // Туманность: два мягких пятна, медленно плывут.
        const drift = now * 0.002;
        for (const n of [[0.7, 0.3, 'rgba(124,58,237,0.16)', 90], [0.25, 0.75, 'rgba(14,165,233,0.12)', 70]]) {
            const nx = ((n[0] * W - drift) % (W + 120) + W + 120) % (W + 120) - 60, ny = n[1] * H;
            const g = ctx.createRadialGradient(nx, ny, 0, nx, ny, n[3]);
            g.addColorStop(0, n[2]);
            g.addColorStop(1, 'rgba(0,0,0,0)');
            ctx.fillStyle = g;
            ctx.fillRect(0, 0, W, H);
        }

        // Три слоя звёзд: дальние медленнее и тусклее.
        const layers = [[50, 0.006, 0.5, 0.35], [28, 0.016, 0.8, 0.6], [12, 0.04, 1.2, 0.9]];
        layers.forEach(function (l, li) {
            for (let i = 0; i < l[0]; i++) {
                const x = ((hash(i, li) * (W + 4) - now * l[1]) % (W + 4) + W + 4) % (W + 4) - 2;
                const y = hash(li, i) * H;
                const tw = 0.7 + 0.3 * Math.sin(now / 300 + i * 1.7);
                ctx.fillStyle = 'rgba(226,232,240,' + (l[3] * tw).toFixed(3) + ')';
                ctx.fillRect(x, y, l[2], l[2]);
            }
        });
    }

    function render(ctx, sim, view) {
        const now = view.now;
        const dt  = fx.last ? Math.min(50, now - fx.last) : 16;
        fx.last = now;
        const a = sim.dead || sim.idle ? 0 : view.alpha;
        const s = (view.H - HUD) / H;
        const W = Math.max(sim.w, view.W / s);

        events(sim, now);

        // Гибель ракеты: обломки корпуса, огненный шар, тряска.
        if (sim.dead && !fx.deadAt) {
            fx.deadAt = now;
            const ry = sim.y / FP;
            ROCKET.forEach(function (part, i) {
                fx.parts.push({ poly: part, x: RX, y: ry, vx: (i - 1) * 0.01 - 0.02, vy: (i === 1 ? -1 : (i === 2 ? 1 : 0)) * 0.04, a: 0,
                    spin: (i - 1) * 0.006 + 0.003, color: i ? ['#dc2f45', '#f87171', '#7f1d2d'] : ['#cbd5e1', '#f8fafc', '#475569'], born: now, life: 1400 });
            });
            sparks(RX, ry, 22, 0.13, COLORS.orange, now, false);
            dust(RX, ry, 12, 10, now);
            fx.rings.push({ x: RX, y: ry, r: 14, born: now, flash: true });
        }
        if (!sim.dead) fx.deadAt = 0;

        const shake = fx.deadAt && now - fx.deadAt < 350 ? (1 - (now - fx.deadAt) / 350) * 2.5 : 0;

        ctx.save();
        ctx.translate(0, HUD);
        ctx.scale(s, s);
        ctx.beginPath();
        ctx.rect(0, 0, W, H);
        ctx.clip();
        if (shake) ctx.translate(Math.sin(now * 0.11) * shake, Math.cos(now * 0.13) * shake);

        stars(ctx, W, now);

        // Пыль — позади астероидов.
        fx.dust = fx.dust.filter(function (d) {
            const age = (now - d.born) / d.life;
            if (age >= 1) return false;
            d.x += d.vx * dt;
            d.y += d.vy * dt;
            ctx.fillStyle = 'rgba(168,150,128,' + (0.28 * (1 - age)).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r * (1 + age * 1.5), 0, TAU);
            ctx.fill();
            return true;
        });

        // Астероиды.
        const live = new Set();
        for (const r of sim.rocks) {
            const pts = contour(r, a);
            const cx = (r.x + r.vx * a) / FP, cy = (r.y + r.vy * a) / FP;
            const radius = (r.tier === 2 ? 20 : (r.tier === 1 ? 13 : 8));
            const angle = (r.rot + r.spin * a) / 2048 * TAU;

            const prev = fx.seen.get(r.id);
            if (!prev || prev.tier !== r.tier) fx.start.set(r.id, r.hp);
            fx.seen.set(r.id, { pts: pts, cx: cx, cy: cy, r: radius, tier: r.tier, vx: r.vx / FP * PER_MS, vy: r.vy / FP * PER_MS });
            live.add(r.id);

            const hit = fx.flash.get(r.id), flash = hit ? Math.max(0, 1 - (now - hit) / 120) : 0;
            rock(ctx, pts, cx, cy, radius, angle, r.id, Math.max(0, fx.start.get(r.id) - r.hp), flash);

            if (view.cfg.show_hitbox) {
                path(ctx, pts);
                ctx.strokeStyle = '#a3e635';
                ctx.lineWidth = 0.5;
                ctx.stroke();
            }
        }
        fx.seen.forEach(function (v, id) { if (!live.has(id)) { fx.seen.delete(id); fx.start.delete(id); fx.flash.delete(id); } });

        // Пули: светящиеся болты с хвостом.
        ctx.save();
        ctx.shadowColor = '#67e8f9';
        ctx.shadowBlur = 5;
        ctx.lineCap = 'round';
        for (const sh of sim.shots) {
            const x = (sh[0] + SHOT * FP * a) / FP, y = sh[1] / FP;
            const g = ctx.createLinearGradient(x - 8, y, x, y);
            g.addColorStop(0, 'rgba(103,232,249,0)');
            g.addColorStop(1, '#ecfeff');
            ctx.strokeStyle = g;
            ctx.lineWidth = 1.6;
            ctx.beginPath();
            ctx.moveTo(x - 8, y);
            ctx.lineTo(x, y);
            ctx.stroke();
        }
        ctx.restore();

        // Вспышка у носа — пуля вылетела на этом тике (за тик она уже прошла SHOT).
        if (sim.shots.some(function (sh) { return sh[0] === (RX + NOSE + SHOT) * FP; })) fx.shotAt = now;
        const muzzle = Math.max(0, 1 - (now - fx.shotAt) / 90);

        // Ракета: между тиками — шаг к цели.
        if (!sim.dead) {
            const d = sim.target - sim.y, step = PSPEED * FP;
            const y = (sim.y + Math.max(-step, Math.min(step, d)) * a) / FP + (sim.idle ? Math.sin(now / 300) * 1.5 : 0);
            rocket(ctx, RX, y, now, sim.idle ? 0 : Math.sign(d), sim.firing ? muzzle : 0);

            if (view.cfg.show_hitbox) {
                ctx.strokeStyle = '#a3e635';
                ctx.lineWidth = 0.5;
                for (const part of ROCKET) {
                    path(ctx, part.map(function (p) { return [RX + p[0], y + p[1]]; }));
                    ctx.stroke();
                }
            }
        }

        // Обломки.
        fx.parts = fx.parts.filter(function (p) {
            const age = (now - p.born) / p.life;
            if (age >= 1) return false;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.a += p.spin * dt;
            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(p.a);
            ctx.globalAlpha = 1 - age * age;
            path(ctx, p.poly);
            ctx.fillStyle = p.color[0];
            ctx.fill();
            ctx.strokeStyle = p.color[2];
            ctx.lineWidth = 0.5;
            ctx.stroke();
            ctx.restore();
            return true;
        });

        // Ударные волны и вспышки.
        fx.rings = fx.rings.filter(function (r) {
            const age = (now - r.born) / 550;
            if (age >= 1) return false;
            if (r.flash && age < 0.4) {
                const k = age / 0.4, fr = r.r * (0.6 + k * 0.9);
                const g = ctx.createRadialGradient(r.x, r.y, 0, r.x, r.y, fr);
                g.addColorStop(0, 'rgba(255,251,235,' + (1 - k).toFixed(3) + ')');
                g.addColorStop(0.4, 'rgba(253,186,116,' + (0.8 * (1 - k)).toFixed(3) + ')');
                g.addColorStop(1, 'rgba(234,88,12,0)');
                ctx.fillStyle = g;
                ctx.beginPath();
                ctx.arc(r.x, r.y, fr, 0, TAU);
                ctx.fill();
            }
            ctx.strokeStyle = (r.flash ? 'rgba(254,215,170,' : 'rgba(110,231,183,') + (0.7 * (1 - age)).toFixed(3) + ')';
            ctx.lineWidth = 1.2 * (1 - age) + 0.2;
            ctx.beginPath();
            ctx.arc(r.x, r.y, r.r * (0.8 + age * 1.6), 0, TAU);
            ctx.stroke();
            return true;
        });

        // Искры.
        ctx.lineCap = 'round';
        fx.sparks = fx.sparks.filter(function (p) {
            const age = (now - p.born) / p.life;
            if (age >= 1) return false;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.vx *= 0.97;
            p.vy *= 0.97;
            ctx.strokeStyle = p.color;
            ctx.globalAlpha = 1 - age;
            ctx.lineWidth = 0.8;
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            ctx.lineTo(p.x - p.vx * 25, p.y - p.vy * 25);
            ctx.stroke();
            ctx.globalAlpha = 1;
            return true;
        });

        // «+1».
        fx.texts = fx.texts.filter(function (t) {
            const age = (now - t.born) / 750;
            if (age >= 1) return false;
            const k = 1 + 0.35 * Math.max(0, 1 - age * 5);
            ctx.save();
            ctx.translate(t.x, t.y - age * 12);
            ctx.scale(k, k);
            ctx.globalAlpha = 1 - age * age;
            ctx.font = '800 9px ' + FONT;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.lineWidth = 2;
            ctx.strokeStyle = 'rgba(0,0,0,0.6)';
            ctx.strokeText(t.text, 0, 0);
            ctx.fillStyle = t.color;
            ctx.fillText(t.text, 0, 0);
            ctx.restore();
            return true;
        });

        ctx.restore();
    }

    return Object.assign({}, input, { render: render });
}

/** Значок счёта: маленький астероид. */
function icon(ctx, x, y, r) {
    const pts = [];
    for (let k = 0; k < 9; k++) {
        const t = k / 9 * TAU, rr = r * (0.8 + hash(k, 3) * 0.3);
        pts.push([x + Math.cos(t) * rr, y + Math.sin(t) * rr]);
    }
    rock(ctx, pts, x, y, r, 0, 4, 0, 0);
}

createDriver({
    name: 'asteroids',
    Sim:  AsteroidsSim,
    pad:  { dpad: ['up', 'down'], big: true },
    render: function () {},
    icon: icon,
    controls: asteroidsView,
});
