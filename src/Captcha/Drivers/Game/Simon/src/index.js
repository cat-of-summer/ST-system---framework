import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { SimonSim, WAIT, SHOW, INPUT, DONE, SUCCESS, TIMEOUT } from './sim.js';
import { simonControls, sectorAngle } from './controls.js';

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

// Цвета секторов по порядку: классические четыре и ещё два для пяти и шести кнопок.
const PALETTE = ['#22c55e', '#ef4444', '#3b82f6', '#eab308', '#a855f7', '#06b6d4'];

function rgb(hex) {
    const v = parseInt(hex.slice(1), 16);
    return [v >> 16, (v >> 8) & 255, v & 255];
}

function rgba(c, a, k) {
    k = k === undefined ? 1 : k;
    return 'rgba(' + Math.round(c[0] * k) + ',' + Math.round(c[1] * k) + ',' + Math.round(c[2] * k) + ',' + a.toFixed(3) + ')';
}

/** Контур сектора i: внешняя и внутренняя дуги с ровным зазором до соседей. */
function wedge(ctx, g, i, R, r) {
    const step = Math.PI * 2 / g.n, a = sectorAngle(g, i);
    const go = g.gap / R, gi = g.gap / r;

    ctx.beginPath();
    ctx.arc(g.x, g.y, R, a - step / 2 + go, a + step / 2 - go);
    ctx.arc(g.x, g.y, r, a + step / 2 - gi, a - step / 2 + gi, true);
    ctx.closePath();
}

/**
 * Вид Simon: поверх ввода (controls.js) — свечение секторов, расходящаяся рябь при загорании,
 * ступица с раундом и таймером ввода, пробег по кругу после верного раунда, дрожь при ошибке.
 */
function simonView(api) {
    const input = simonControls(api);
    const ui    = input.ui;
    const g     = ui.g;
    const n     = g.n;
    const COL   = PALETTE.slice(0, n).map(rgb);

    // Только для вида: яркость каждого сектора плавно идёт к цели, рябь — от каждого загорания.
    const fx = { glow: [], on: [], ripples: [], last: 0, deadAt: 0, doneAt: 0 };
    for (let i = 0; i < n; i++) { fx.glow.push(0); fx.on.push(false); }

    /** Должен ли сектор гореть сейчас: показ, нажатие, ошибка, пробег после раунда. */
    function wanted(sim, i, now) {
        if (sim.dead > 0) return i === sim.wrong ? 1 : 0;
        if (sim.idle) return 0.12 + 0.12 * Math.sin(now / 380 - i * Math.PI * 2 / n);
        if (sim.lit() === i || sim.flash === i) return 1;
        if (sim.phase === DONE) {
            const k = Math.floor((SUCCESS - sim.timer) / 2);
            return k < n * 2 && k % n === i ? 0.9 : 0;
        }
        // Нажатие вне ввода: сектор чуть отзывается, но не загорается.
        if (ui.pressed === i && now - ui.pressAt < 160) return 0.25;
        return i === ui.hover && sim.phase === INPUT ? 0.12 : 0;
    }

    function render(ctx, sim, view) {
        const now = view.now;
        const dt  = fx.last ? Math.min(50, now - fx.last) : 16;
        fx.last = now;

        if (sim.dead > 0 && !fx.deadAt) fx.deadAt = now;
        if (!sim.dead) fx.deadAt = 0;
        if (sim.phase === DONE && !sim.dead && !fx.doneAt) fx.doneAt = now;
        if (sim.phase !== DONE) fx.doneAt = 0;

        // Фон: тёмная виньетка под кругом.
        const bg = ctx.createRadialGradient(g.x, g.y, g.r, g.x, g.y, Math.max(view.W, view.H) * 0.7);
        bg.addColorStop(0, '#16213a');
        bg.addColorStop(1, COLORS.bg);
        ctx.fillStyle = bg;
        ctx.fillRect(0, HUD, view.W, view.H - HUD);

        ctx.save();

        // Ошибка — круг дрожит, затихая.
        const shake = fx.deadAt ? Math.max(0, 1 - (now - fx.deadAt) / 450) : 0;
        if (shake) ctx.translate(Math.sin(now / 22) * 4 * shake, 0);

        // Корпус: кольцо-обод с бликом сверху.
        const rim = ctx.createLinearGradient(0, g.y - g.R - 8, 0, g.y + g.R + 8);
        rim.addColorStop(0, '#334155');
        rim.addColorStop(1, '#0b1220');
        ctx.fillStyle = rim;
        ctx.beginPath();
        ctx.arc(g.x, g.y, g.R + 6, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#05080f';
        ctx.beginPath();
        ctx.arc(g.x, g.y, g.R + 1.5, 0, Math.PI * 2);
        ctx.fill();

        for (let i = 0; i < n; i++) {
            const target = wanted(sim, i, now);
            const k = target > fx.glow[i] ? Math.min(1, dt / 45) : Math.min(1, dt / 200);
            fx.glow[i] += (target - fx.glow[i]) * k;

            // Загорелся — от сектора наружу расходится рябь.
            const on = target >= 0.9;
            if (on && !fx.on[i]) fx.ripples.push({ i: i, born: now });
            fx.on[i] = on;
        }

        for (let i = 0; i < n; i++) {
            const c = COL[i], glow = fx.glow[i], a = sectorAngle(g, i);
            const press = sim.flash === i ? 1 : 0;
            const dying = sim.dead > 0 && i === sim.wrong;

            ctx.save();
            // Нажатый сектор утапливается к центру.
            if (press) {
                ctx.translate(g.x, g.y);
                ctx.scale(0.97, 0.97);
                ctx.translate(-g.x, -g.y);
            }

            // Тёмная основа сектора: объём — светлее к внешнему краю.
            const base = ctx.createRadialGradient(g.x, g.y, g.r, g.x, g.y, g.R);
            base.addColorStop(0, rgba(c, 1, 0.14));
            base.addColorStop(1, rgba(c, 1, 0.30));
            ctx.fillStyle = base;
            wedge(ctx, g, i, g.R, g.r);
            ctx.fill();

            // Свечение: яркий цвет с ореолом и белым бликом в середине сектора.
            if (glow > 0.01) {
                ctx.save();
                ctx.shadowColor = dying ? '#f43f5e' : PALETTE[i];
                ctx.shadowBlur  = 28 * glow;
                const lit = ctx.createRadialGradient(g.x, g.y, g.r, g.x, g.y, g.R);
                lit.addColorStop(0, rgba(c, glow, 0.85));
                lit.addColorStop(1, rgba(c, glow, 1));
                ctx.fillStyle = lit;
                wedge(ctx, g, i, g.R, g.r);
                ctx.fill();
                ctx.restore();

                const mr = (g.R + g.r) / 2, mx = g.x + Math.cos(a) * mr, my = g.y + Math.sin(a) * mr;
                const shine = ctx.createRadialGradient(mx, my, 0, mx, my, (g.R - g.r) * 0.9);
                shine.addColorStop(0, 'rgba(255,255,255,' + (0.45 * glow).toFixed(3) + ')');
                shine.addColorStop(1, 'rgba(255,255,255,0)');
                ctx.save();
                wedge(ctx, g, i, g.R, g.r);
                ctx.clip();
                ctx.fillStyle = shine;
                ctx.fillRect(g.x - g.R, g.y - g.R, g.R * 2, g.R * 2);
                ctx.restore();
            }

            // Ошибка: неверный сектор перечёркнут красным.
            if (dying) {
                ctx.fillStyle = 'rgba(244,63,94,' + (0.35 + 0.25 * Math.sin(now / 60)).toFixed(3) + ')';
                wedge(ctx, g, i, g.R, g.r);
                ctx.fill();
            }

            // Фаска: светлая кромка по внешней дуге.
            ctx.strokeStyle = 'rgba(255,255,255,' + (0.10 + 0.25 * glow).toFixed(3) + ')';
            ctx.lineWidth = 1.2;
            const step = Math.PI * 2 / n, go = (g.gap + 1) / g.R;
            ctx.beginPath();
            ctx.arc(g.x, g.y, g.R - 1.5, a - step / 2 + go, a + step / 2 - go);
            ctx.stroke();

            // Номер для клавиатуры — когда ею пользуются.
            if (ui.keyboard) {
                const lr = g.R - (g.R - g.r) * 0.22;
                ctx.fillStyle = 'rgba(255,255,255,' + (0.35 + 0.4 * glow).toFixed(3) + ')';
                ctx.font = '700 ' + Math.round(g.R * 0.12) + 'px ' + FONT;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(String(i + 1), g.x + Math.cos(a) * lr, g.y + Math.sin(a) * lr);
            }

            ctx.restore();
        }

        // Рябь: дуга сектора уходит наружу, тает и истончается.
        const keep = [];
        for (const rp of fx.ripples) {
            const k = (now - rp.born) / 520;
            if (k >= 1) continue;
            const step = Math.PI * 2 / n, a = sectorAngle(g, rp.i), e = 1 - Math.pow(1 - k, 2);
            ctx.strokeStyle = rgba(COL[rp.i], 0.7 * (1 - k));
            ctx.lineWidth = 5 * (1 - k) + 0.5;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.arc(g.x, g.y, g.R + 4 + e * g.R * 0.22, a - step / 2 + 0.1, a + step / 2 - 0.1);
            ctx.stroke();
            keep.push(rp);
        }
        fx.ripples = keep;

        hub(ctx, sim, now);

        ctx.restore();
    }

    /** Ступица: раунд и что делать, прогресс ввода точками, таймер ввода кольцом. */
    function hub(ctx, sim, now) {
        const r = g.r - g.gap;

        const face = ctx.createRadialGradient(g.x, g.y - r * 0.4, r * 0.1, g.x, g.y, r);
        face.addColorStop(0, '#1e293b');
        face.addColorStop(1, '#0b1220');
        ctx.fillStyle = face;
        ctx.beginPath();
        ctx.arc(g.x, g.y, r, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = 'rgba(148,163,184,0.25)';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        if (sim.idle && !sim.dead) return;

        let title = '', color = COLORS.text;

        if (sim.dead > 0) {
            title = '✕';
            color = COLORS.red;
        } else if (sim.phase === DONE) {
            title = '✓';
            color = COLORS.green;
        } else if (sim.phase === INPUT) {
            title = 'Повторите';
            // Таймер ввода: кольцо убывает, к концу краснеет.
            const left = sim.timer / TIMEOUT;
            ctx.strokeStyle = left < 0.3 ? COLORS.red : 'rgba(159,183,219,0.55)';
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.arc(g.x, g.y, r - 3, -Math.PI / 2, -Math.PI / 2 + left * Math.PI * 2);
            ctx.stroke();
        } else {
            title = 'Смотрите';
        }

        // Номер раунда сверху.
        ctx.fillStyle = COLORS.muted;
        ctx.font = '600 ' + Math.max(8, Math.round(r * 0.2)) + 'px ' + FONT;
        ctx.fillText('Раунд ' + (sim.round + (sim.phase === DONE ? 0 : 1)), g.x, g.y - r * 0.42);

        // Знак результата появляется с подскоком, слово — обычным шрифтом.
        const big = title.length === 1;
        let scale = 1;
        if (sim.phase === DONE && fx.doneAt) {
            const k = Math.min(1, (now - fx.doneAt) / 260);
            scale = 1 + 2.7 * Math.pow(k - 1, 3) + 1.7 * Math.pow(k - 1, 2);
        }
        ctx.save();
        ctx.translate(g.x, g.y + (big ? r * 0.05 : 0));
        ctx.scale(scale, scale);
        ctx.fillStyle = color;
        ctx.font = (big ? '800 ' + Math.round(r * 0.7) : '700 ' + Math.max(8, Math.round(r * 0.26))) + 'px ' + FONT;
        // Слово — не шире ступицы: на маленьком холсте ужимается.
        const fit = Math.min(1, r * 1.55 / Math.max(1, ctx.measureText(title).width));
        ctx.scale(fit, fit);
        ctx.fillText(title, 0, 0);
        ctx.restore();

        // Точки шагов: сколько уже повторено из скольких.
        if (sim.phase === INPUT || sim.phase === SHOW || sim.phase === WAIT) {
            const len = sim.seq.length, dot = Math.max(1.5, r * 0.06), gapX = dot * 3;
            // На показе закрашены показанные шаги и текущий — по одному, без мигания; на вводе — повторённые.
            const done = sim.phase === INPUT ? sim.pos : (sim.phase === SHOW ? sim.step + 1 : 0);
            const x0 = g.x - (len - 1) * gapX / 2, y = g.y + r * 0.42;
            for (let k = 0; k < len; k++) {
                ctx.fillStyle = k < done ? (sim.phase === INPUT ? COLORS.green : COLORS.text) : 'rgba(159,183,219,0.3)';
                ctx.beginPath();
                ctx.arc(x0 + k * gapX, y, dot, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    }

    return Object.assign({}, input, { render: render });
}

createDriver({
    name: 'simon',
    Sim:  SimonSim,
    pointer: true,
    hover: true,
    render: function () {},
    controls: simonView,
    over: function (sim) { return sim.round >= sim.rounds; },
    icon: function (ctx, x, y, r) {
        for (let i = 0; i < 4; i++) {
            ctx.fillStyle = PALETTE[i];
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.arc(x, y, r, -Math.PI * 3 / 4 + i * Math.PI / 2 + 0.12, -Math.PI / 4 + i * Math.PI / 2 - 0.12);
            ctx.closePath();
            ctx.fill();
        }
        ctx.fillStyle = COLORS.board;
        ctx.beginPath();
        ctx.arc(x, y, r * 0.35, 0, Math.PI * 2);
        ctx.fill();
    },
});
