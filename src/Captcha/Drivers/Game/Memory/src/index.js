import { createDriver, COLORS, HUD } from '../../assets/engine/src/index.js';
import { MemorySim, PREVIEW, PLAY, MISS, WON, DOWN, FOUND } from './sim.js';
import { memoryControls } from './controls.js';

const EMOJI = '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Twemoji Mozilla",sans-serif';

const FLIP_MS  = 300;
const WAVE_MS  = 35;
const FOUND_MS = 700;

function rounded(ctx, x, y, w, h, r) {
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(x, y, w, h, r);
    else ctx.rect(x, y, w, h);
}

/** Скругление углов — как у настоящей карты: 3,5 мм на 63 мм ширины. */
const CORNER = 0.056;

/**
 * Рубашка игральной карты: белое поле по краю, тёмно-красная панель с двойной рамкой и косой
 * сеткой, в центре — овальный медальон с розеткой, по углам панели — ромбики.
 */
function back(ctx, w, h, hot) {
    const r = Math.max(2, w * CORNER), m = Math.max(2, w * 0.075);
    const pw = w - m * 2, ph = h - m * 2;

    // Картон.
    ctx.fillStyle = '#f8fafc';
    rounded(ctx, -w / 2, -h / 2, w, h, r);
    ctx.fill();
    ctx.strokeStyle = 'rgba(15,23,42,0.25)';
    ctx.lineWidth = 0.8;
    ctx.stroke();

    // Панель с косой сеткой.
    const g = ctx.createLinearGradient(0, -ph / 2, 0, ph / 2);
    g.addColorStop(0, hot ? '#dc2626' : '#b91c1c');
    g.addColorStop(1, hot ? '#b91c1c' : '#991b1b');
    ctx.fillStyle = g;
    rounded(ctx, -pw / 2, -ph / 2, pw, ph, r * 0.5);
    ctx.fill();

    ctx.save();
    rounded(ctx, -pw / 2, -ph / 2, pw, ph, r * 0.5);
    ctx.clip();
    ctx.strokeStyle = 'rgba(254,226,226,0.28)';
    ctx.lineWidth = Math.max(0.4, w * 0.012);
    ctx.beginPath();
    const step = Math.max(3, w / 8);
    for (let d = -ph; d <= pw + ph; d += step) {
        ctx.moveTo(-pw / 2 + d, -ph / 2); ctx.lineTo(-pw / 2 + d - ph, ph / 2);
        ctx.moveTo(-pw / 2 + d - ph, -ph / 2); ctx.lineTo(-pw / 2 + d, ph / 2);
    }
    ctx.stroke();
    ctx.restore();

    // Двойная рамка внутри панели.
    const i1 = Math.max(1.5, w * 0.045), i2 = i1 + Math.max(1.2, w * 0.03);
    ctx.strokeStyle = 'rgba(254,242,242,0.85)';
    ctx.lineWidth = Math.max(0.6, w * 0.018);
    rounded(ctx, -pw / 2 + i1, -ph / 2 + i1, pw - i1 * 2, ph - i1 * 2, r * 0.4);
    ctx.stroke();
    ctx.lineWidth = Math.max(0.4, w * 0.01);
    rounded(ctx, -pw / 2 + i2, -ph / 2 + i2, pw - i2 * 2, ph - i2 * 2, r * 0.3);
    ctx.stroke();

    // Ромбики по углам панели.
    const d = Math.max(1.2, w * 0.035), cx = pw / 2 - i2 - d * 1.6, cy = ph / 2 - i2 - d * 1.6;
    ctx.fillStyle = 'rgba(254,242,242,0.9)';
    for (const sx of [-1, 1])
        for (const sy of [-1, 1]) {
            ctx.beginPath();
            ctx.moveTo(sx * cx, sy * cy - d); ctx.lineTo(sx * cx + d * 0.7, sy * cy);
            ctx.lineTo(sx * cx, sy * cy + d); ctx.lineTo(sx * cx - d * 0.7, sy * cy);
            ctx.closePath();
            ctx.fill();
        }

    // Медальон: овал с каймой и розеткой из восьми лепестков.
    const ox = pw * 0.27, oy = ph * 0.2;
    ctx.fillStyle = '#7f1d1d';
    ctx.beginPath();
    ctx.ellipse(0, 0, ox, oy, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = 'rgba(254,242,242,0.9)';
    ctx.lineWidth = Math.max(0.6, w * 0.018);
    ctx.stroke();

    ctx.fillStyle = 'rgba(254,242,242,0.85)';
    const pr = Math.min(ox, oy) * 0.72;
    for (let k = 0; k < 8; k++) {
        ctx.save();
        ctx.rotate(k * Math.PI / 4);
        ctx.beginPath();
        ctx.ellipse(0, -pr * 0.55, pr * 0.2, pr * 0.5, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    }
    ctx.fillStyle = '#facc15';
    ctx.beginPath();
    ctx.arc(0, 0, pr * 0.22, 0, Math.PI * 2);
    ctx.fill();
}

/** Лицо: светлая карточка с эмодзи или картинкой. */
function front(ctx, w, h, card, image) {
    const r = Math.max(2, w * CORNER);

    const g = ctx.createLinearGradient(0, -h / 2, 0, h / 2);
    g.addColorStop(0, '#f8fafc');
    g.addColorStop(1, '#dbe3ee');
    ctx.fillStyle = g;
    rounded(ctx, -w / 2, -h / 2, w, h, r);
    ctx.fill();

    if (image && image.complete && image.naturalWidth) {
        ctx.save();
        rounded(ctx, -w / 2 + 2, -h / 2 + 2, w - 4, h - 4, r * 0.8);
        ctx.clip();
        ctx.drawImage(image, -w / 2 + 2, -h / 2 + 2, w - 4, h - 4);
        ctx.restore();
    } else if (card) {
        ctx.font = Math.round(w * 0.62) + 'px ' + EMOJI;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#0f172a';
        ctx.fillText(card, 0, h * 0.04);
    }

    ctx.strokeStyle = 'rgba(15,23,42,0.18)';
    ctx.lineWidth = 1;
    rounded(ctx, -w / 2 + 0.5, -h / 2 + 0.5, w - 1, h - 1, r);
    ctx.stroke();
}

/**
 * Вид Memory поверх ввода (controls.js): переворот карточек с подъёмом и тенью, волна на показе,
 * отсчёт, вспышка найденной пары, дрожь промаха, конфетти за собранное поле.
 */
function memoryView(api) {
    const input = memoryControls(api);
    const ui    = input.ui;
    const L     = ui.layout;
    const n     = ui.n;
    const cards = api.cfg.cards || [];

    // Картинки лиц грузятся один раз.
    const images = api.cfg.faces === 'images'
        ? cards.map(function (src) { const im = new Image(); im.src = src; return im; })
        : [];

    // Только для вида: что показано на каждой карточке и когда начался её переворот.
    const fx = { up: [], flipAt: [], from: [], foundAt: [], parts: [], wonAt: 0, deadAt: 0, last: 0 };
    for (let i = 0; i < n; i++) { fx.up.push(false); fx.flipAt.push(-1e9); fx.from.push(false); fx.foundAt.push(0); }

    function track(sim, now) {
        const changed = [];
        for (let i = 0; i < n; i++) {
            const up = sim.face[i] !== DOWN;
            if (up !== fx.up[i]) changed.push(i);
            if (sim.face[i] === FOUND && !fx.foundAt[i]) fx.foundAt[i] = now + FLIP_MS * 0.6;
            if (sim.face[i] !== FOUND) fx.foundAt[i] = 0;
        }

        // Много карточек сразу (показ, конец показа, новый расклад) — переворачиваются волной.
        const wave = changed.length > 2;
        changed.forEach(function (i, k) {
            fx.from[i]   = fx.up[i];
            fx.up[i]     = !fx.up[i];
            fx.flipAt[i] = now + (wave ? k * WAVE_MS : 0);
        });
    }

    function confetti(now) {
        fx.wonAt = now;
        for (let i = 0; i < 46; i++)
            fx.parts.push({ x: api.W / 2 + ((i * 29) % 60 - 30), y: HUD + (api.H - HUD) / 2, vx: ((i * 17) % 21 - 10) * 0.012,
                vy: -0.08 - (i % 7) * 0.012, born: now, life: 1500, color: [COLORS.green, COLORS.yellow, COLORS.blue, COLORS.red, COLORS.purple][i % 5], spin: i });
    }

    function render(ctx, sim, view) {
        const now = view.now;
        const dt  = fx.last ? Math.min(50, now - fx.last) : 16;
        fx.last = now;

        track(sim, now);
        if (sim.phase === WON && !fx.wonAt) confetti(now);
        if (sim.phase !== WON) fx.wonAt = 0;
        if (sim.dead > 0 && !fx.deadAt) fx.deadAt = now;
        if (!sim.dead) fx.deadAt = 0;

        // Фон: стол с мягким светом по центру.
        const bg = ctx.createRadialGradient(view.W / 2, HUD + (view.H - HUD) / 2, 10, view.W / 2, HUD + (view.H - HUD) / 2, view.W * 0.7);
        bg.addColorStop(0, '#1a2540');
        bg.addColorStop(1, COLORS.bg);
        ctx.fillStyle = bg;
        ctx.fillRect(0, HUD, view.W, view.H - HUD);

        const missing = (sim.phase === MISS || sim.dead > 0) && sim.missA >= 0;

        for (let i = 0; i < n; i++) {
            const p = L.at[i], w = L.w, h = L.h;
            const k = Math.max(0, Math.min(1, (now - fx.flipAt[i]) / FLIP_MS));
            const showUp = k < 0.5 ? fx.from[i] : fx.up[i];
            const lift = Math.sin(k * Math.PI);
            const found = sim.face[i] === FOUND;
            const hot = i === ui.hover && sim.phase === PLAY && sim.face[i] === DOWN && !sim.dead;

            let dx = 0, dy = -lift * h * 0.08 - (hot ? 2 : 0);
            if (missing && (i === sim.missA || i === sim.missB) && k >= 1) {
                const t = (now - fx.flipAt[i] - FLIP_MS) / 1000;
                dx = Math.sin(t * 60) * 2.5 * Math.max(0, 1 - t * 2.5);
            }

            // Тень растёт, пока карточка в воздухе.
            ctx.fillStyle = 'rgba(0,0,0,' + (0.28 + lift * 0.2).toFixed(3) + ')';
            rounded(ctx, p[0] + 2 + lift * 3, p[1] + 3 + lift * 5, w, h, w * CORNER);
            ctx.fill();

            ctx.save();
            ctx.translate(p[0] + w / 2 + dx, p[1] + h / 2 + dy);
            const scale = 1 + lift * 0.08;
            ctx.scale(Math.max(0.02, Math.abs(Math.cos(k * Math.PI))) * scale, scale);

            // Найденная пара: вспышка, потом карточка блекнет и чуть уменьшается.
            if (found && fx.foundAt[i] && now > fx.foundAt[i] + FOUND_MS && sim.phase !== WON) {
                ctx.globalAlpha = 0.5;
                ctx.scale(0.94, 0.94);
            }

            if (showUp) front(ctx, w, h, cards[sim.deck[i]], images[sim.deck[i]]);
            else back(ctx, w, h, hot);

            if (missing && (i === sim.missA || i === sim.missB) && showUp) {
                ctx.strokeStyle = COLORS.red;
                ctx.lineWidth = 2.5;
                rounded(ctx, -w / 2 + 1, -h / 2 + 1, w - 2, h - 2, w * CORNER);
                ctx.stroke();
            }
            ctx.restore();

            // Вспышка найденной пары: зелёное кольцо расходится от карточки.
            if (found && fx.foundAt[i]) {
                const t = (now - fx.foundAt[i]) / FOUND_MS;
                if (t > 0 && t < 1) {
                    ctx.strokeStyle = 'rgba(52,211,153,' + (0.9 * (1 - t)).toFixed(3) + ')';
                    ctx.lineWidth = 3 * (1 - t) + 0.5;
                    const e = t * w * 0.25;
                    rounded(ctx, p[0] - e, p[1] - e, w + e * 2, h + e * 2, w * CORNER + e);
                    ctx.stroke();
                }
            }

            if (ui.keyboard && i === ui.cursor && !sim.dead) {
                ctx.save();
                ctx.strokeStyle = COLORS.yellow;
                ctx.lineWidth = 2;
                ctx.setLineDash([4, 3]);
                ctx.lineDashOffset = -now / 60;
                rounded(ctx, p[0] - 3, p[1] - 3, w + 6, h + 6, w * CORNER * 1.3);
                ctx.stroke();
                ctx.restore();
            }
        }

        if (sim.phase === PREVIEW && !sim.idle && !sim.dead) countdown(ctx, sim, view);

        // Конфетти за собранное поле.
        const keep = [];
        for (const q of fx.parts) {
            const age = (now - q.born) / q.life;
            if (age >= 1) continue;
            q.vy += 0.00018 * dt;
            q.x += q.vx * dt;
            q.y += q.vy * dt;
            ctx.save();
            ctx.globalAlpha = 1 - age;
            ctx.translate(q.x, q.y);
            ctx.rotate(now / 110 + q.spin);
            ctx.fillStyle = q.color;
            ctx.fillRect(-2.5, -1, 5, 2);
            ctx.restore();
            keep.push(q);
        }
        fx.parts = keep;
    }

    /**
     * Время показа — полосой над карточками, она убывает. Число секунд — в строке счёта: поверх
     * поля оно закрыло бы карточки, которые сейчас нужно запомнить.
     */
    function countdown(ctx, sim, view) {
        ctx.fillStyle = 'rgba(159,183,219,0.25)';
        ctx.fillRect(0, HUD, view.W, 3);
        ctx.fillStyle = COLORS.yellow;
        ctx.fillRect(0, HUD, view.W * Math.max(0, sim.timer - view.alpha) / sim.show, 3);
    }

    return Object.assign({}, input, { render: render });
}

createDriver({
    name: 'memory',
    Sim:  MemorySim,
    pointer: true,
    hover: true,
    render: function () {},
    controls: memoryView,
    // В строке счёта — найденные пары (на показе — сколько секунд осталось), справа — жизни.
    score: function (sim) {
        if (sim.phase === PREVIEW && !sim.idle && !sim.dead) return 'Запомните: ' + Math.ceil(sim.timer / 10);
        return sim.found + ' / ' + sim.pairs;
    },
    status: function (sim) {
        let s = '';
        for (let i = 0; i < sim.max; i++) s += i < sim.lives ? '♥' : '♡';
        return s;
    },
    over: function (sim) { return sim.solved > 0; },
    icon: function (ctx, x, y, r) {
        ctx.save();
        ctx.translate(x - r * 0.35, y);
        ctx.rotate(-0.2);
        back(ctx, r * 1.3, r * 1.75, false);
        ctx.restore();
        ctx.save();
        ctx.translate(x + r * 0.45, y);
        ctx.rotate(0.2);
        front(ctx, r * 1.3, r * 1.75, '', null);
        ctx.restore();
    },
});
