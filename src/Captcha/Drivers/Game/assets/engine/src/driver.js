import { START, collectMet } from './sim.js';

/**
 * Клиентская обвязка игры: canvas в закрытом shadow root, фиксированный тик симуляции,
 * ввод с клавиатуры и пальцем, запись лога и HUD. Состояние партии живёт в замыкании mount(),
 * а не на экземпляре драйвера: снаружи до него не дотянуться через STCaptcha.instances.
 *
 * Цель браузер не знает. Галочка и ответ форме — когда данных достаточно (cfg.collect) или вышло
 * время (cfg.max_ticks); засчитывать или нет, решает сервер. Смерть в игре — не провал: после
 * паузы кнопка перезапуска, статистика копится дальше.
 *
 * Мышь в игре не участвует: клик только даёт фокус и нажимает кнопку старта или перезапуска. На
 * сенсорных устройствах виджет — кнопка запуска: игра разворачивается поверх страницы с геймпадом.
 *
 * spec:
 *   name      — имя драйвера (как в CaptchaManager drivers.available)
 *   Sim       — класс симуляции (наследник Sim)
 *   (размер холста W × H приходит в cfg.size из PHP-драйвера; render и controls получают его во view и api;
 *    подсказка по управлению — в cfg.hint {keys, touch}, её задаёт PHP-драйвер и конфиг капчи)
 *   render    — (ctx, sim, view) рисует поле в логических координатах
 *   icon      — (ctx, x, y, r) значок счёта в HUD
 *   score     — (sim) → число в HUD; по умолчанию sim.reactions
 *   pad       — {dpad: [клавиши] | null, actions: [{key, label, big}], repeat: [клавиши]}
 *   controls  — (api) → { key(key, down, repeat), press(x, y), move(x, y), tap(x, y), swipe(dir), release() }
 *               key() получает имена клавиш KeyboardEvent.key; кнопки геймпада шлют те же имена.
 */

export const COLORS = {
    bg:     '#0f1724',
    board:  '#071025',
    grid:   'rgba(255,255,255,0.04)',
    text:   '#e6eef8',
    muted:  '#9fb7db',
    green:  '#34d399',
    mint:   '#6ee7b7',
    red:    '#fb7185',
    yellow: '#facc15',
    orange: '#fb923c',
    purple: '#7c3aed',
    blue:   '#3b82f6',
};

export const HUD = 20;

/** Клетка и отступы: сетка cols × rows вписана в игровую зону холста W × H по центру. */
export function fitGrid(cols, rows, W, H) {
    const c = Math.min(W / cols, (H - HUD) / rows);
    return { c: c, x: (W - c * cols) / 2, y: HUD + (H - HUD - c * rows) / 2 };
}

const SWIPE        = 18;
const TAP          = 12;
const REPEAT_DELAY = 180;
const REPEAT_EVERY = 70;

const FONT = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif';

const INLINE_CSS = ':host{display:block}'
    + '.slot{width:100%;height:100%}'
    + 'canvas{display:block;width:100%;height:100%;touch-action:none;outline:none;border-radius:8px;cursor:pointer;background:' + COLORS.bg + '}'
    + 'canvas:focus-visible{box-shadow:0 0 0 3px rgba(59,130,246,.6)}';

const OVERLAY_CSS = ':host{all:initial}'
    + '.screen{position:fixed;inset:0;box-sizing:border-box;display:grid;gap:12px;'
    + 'grid-template-columns:1fr 1fr;grid-template-rows:auto minmax(0,1fr) auto;'
    + 'grid-template-areas:"bar bar" "stage stage" "act dpad";'
    + 'padding:max(8px,env(safe-area-inset-top)) max(12px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(12px,env(safe-area-inset-left));'
    + 'background:#05080f;touch-action:none;overscroll-behavior:contain;'
    + 'user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;-webkit-tap-highlight-color:transparent;font-family:' + FONT + '}'
    + '.screen.solo{grid-template-areas:"bar bar" "stage stage" "act act"}'
    + '.bar{grid-area:bar;display:flex;justify-content:flex-end}'
    + '.close{width:40px;height:40px;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:' + COLORS.text + ';font-size:18px;line-height:40px;padding:0}'
    + '.stage{grid-area:stage;display:flex;align-items:center;justify-content:center;min-width:0;min-height:0}'
    + '.stage canvas{display:block;border-radius:10px;touch-action:none;outline:none;background:' + COLORS.bg + '}'
    + '.dpad{grid-area:dpad;justify-self:end;align-self:center;display:grid;gap:6px;grid-template:repeat(3,56px)/repeat(3,56px)}'
    + '.dpad.h{grid-template:64px/repeat(2,72px)}'
    + '.dpad:empty{display:none}'
    + '.actions{grid-area:act;justify-self:start;align-self:center;display:flex;gap:14px;align-items:center}'
    + '.solo .actions{justify-self:center}'
    + '.btn{box-sizing:border-box;border:0;border-radius:14px;padding:0;background:rgba(255,255,255,.14);'
    + 'color:' + COLORS.text + ';font:600 22px/1 ' + FONT + ';touch-action:none;display:flex;align-items:center;justify-content:center}'
    + '.btn.on{background:rgba(52,211,153,.55)}'
    + '.up{grid-area:1/2}.left{grid-area:2/1}.right{grid-area:2/3}.down{grid-area:3/2}'
    + '.h .left{grid-area:1/1}.h .right{grid-area:1/2}'
    + '.act{width:80px;height:80px;border-radius:50%;font-size:26px}'
    + '.act.big{width:108px;height:108px;font-size:36px}'
    + '@media (orientation:landscape){'
    + '.screen{grid-template-columns:auto minmax(0,1fr) auto;grid-template-rows:auto minmax(0,1fr);grid-template-areas:"bar bar bar" "act stage dpad"}'
    + '.screen.solo{grid-template-columns:auto minmax(0,1fr);grid-template-areas:"bar bar" "act stage"}'
    + '.actions{flex-direction:column}}';

const ARROWS = {
    ArrowUp:    ['up', '▲'],
    ArrowRight: ['right', '▶'],
    ArrowDown:  ['down', '▼'],
    ArrowLeft:  ['left', '◀'],
};

export function createDriver(spec) {
    const api = window.STCaptcha;
    if (!api || !api.Driver) return;

    api.register(spec.name, class extends api.Driver {

        onMount() {
            this.restartGame = mount(this, spec);
        }

        reset() {
            super.reset();
            if (this.restartGame) this.restartGame();
        }
    });
}

function coarsePointer() {
    try { return window.matchMedia('(pointer: coarse)').matches; } catch (_) { return false; }
}

/** Смена режима ввода (подключили мышь, повернули планшет) — cb(); без matchMedia — ничего. */
function onPointerChange(cb) {
    try {
        const mq = window.matchMedia('(pointer: coarse)');
        if (mq.addEventListener) mq.addEventListener('change', cb);
        else if (mq.addListener) mq.addListener(cb);
    } catch (_) {}
}

function el(tag, cls) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    return node;
}

function mount(driver, spec) {
    const host = driver.root.querySelector('.st-captcha-game');
    if (!host || !host.attachShadow) return null;

    const cfg       = driver.cfg;
    const W         = (cfg.size && cfg.size[0]) || 320;
    const H         = (cfg.size && cfg.size[1]) || 200;
    const tps       = cfg.tps | 0 || 20;
    const maxTicks  = cfg.max_ticks | 0 || tps * 20;
    const maxEvents = cfg.max_events | 0 || 4000;
    const collect   = cfg.collect || null;
    const stepMs    = 1000 / tps;
    const hint      = cfg.hint || {};

    let touchUi = coarsePointer();

    const shadow = host.attachShadow({ mode: 'closed', delegatesFocus: true });
    const style  = el('style');
    const slot   = el('div', 'slot');
    const canvas = el('canvas');

    style.textContent = INLINE_CSS;
    canvas.tabIndex = 0;
    canvas.setAttribute('role', 'application');
    canvas.setAttribute('aria-label', spec.name);
    slot.append(canvas);
    shadow.append(style, slot);

    const ctx = canvas.getContext('2d');

    let sim, log, started, done, acc, pending, dying;
    let last     = performance.now();
    let onScreen = true;
    let paused   = false;
    let pointer  = null;
    let overlay  = null;
    let expanded = false;
    let overflow = '';

    function fresh() {
        sim     = new spec.Sim(cfg.seed, cfg.params || {});
        log     = [];
        started = false;
        done    = false;
        acc     = 0;
        pending = null;
        dying   = false;
        offered = false;
    }

    function finish() {
        done = true;
        if (controls.release) controls.release();
        driver.solve(JSON.stringify({ t: sim.tick, log: log }));
        if (expanded) setTimeout(collapse, 900);
    }

    function record(code, value) {
        if (done || sim.dead > 0 || log.length >= maxEvents) return;

        started = true;

        const event = [sim.tick, code];
        if (value) event.push(value | 0);
        log.push(event);

        sim.input(code, value | 0);
        died();

        if (spec.open) offer();
    }

    /** Кнопка старта или перезапуска на экране: до первого хода и после смерти. */
    function startable() {
        return spec.startScreen !== false && !done && sim.dead === 0 && (!started || sim.idle);
    }

    /**
     * Смерть в игре: зажатые клавиши и отложенный ввод прошлой жизни в следующую не переходят.
     * Вызывается после каждого ввода и шага.
     */
    function died() {
        if (sim.dead > 0 && !dying) {
            dying   = true;
            pending = null;
            if (controls.release) controls.release();
        } else if (sim.dead === 0) {
            dying = false;
        }
    }

    /**
     * Открытая игра (spec.open): клиент не знает, верно ли решено, — судит сервер. Когда
     * spec.ready(sim), ответ отдаётся форме, но игра не замирает; стало не готово — ответ снят.
     */
    let offered = false;

    function offer() {
        if (spec.ready(sim)) {
            offered = true;
            driver.solve(JSON.stringify({ t: sim.tick, log: log }));
        } else if (offered) {
            offered = false;
            driver.answer('');
            driver.solvedFlag = false;
            driver.setState('pending');
        }
    }

    /** Ввод, которому хватает раза в тик (позиция пальца): применяется на границе тика. */
    function queue(code, value) {
        if (!started) record(code, value);
        else pending = [code, value];
    }

    const controls = spec.controls({
        record:  record,
        queue:   queue,
        sim:     function () { return sim; },
        started: function () { return started; },
        cfg:     cfg,
        W: W,
        H: H,
    });

    function running() {
        return started && !done && !paused && (expanded || onScreen);
    }

    function frame(now) {
        if (!driver.root.isConnected) { teardown(); return; }

        const dt = Math.min(Math.max(0, now - last), 250);
        last = now;

        if (running()) {
            acc += dt;

            while (acc >= stepMs) {
                acc -= stepMs;

                if (pending) {
                    const p = pending;
                    pending = null;
                    record(p[0], p[1]);
                    if (done) break;
                }

                sim.advance();
                died();

                // Данных достаточно или время вышло — ответ форме, решает сервер.
                if ((!spec.open && collectMet(sim, collect, tps)) || sim.tick >= maxTicks) { finish(); break; }
            }
        }

        draw(now);
        requestAnimationFrame(frame);
    }

    // --- размер ---------------------------------------------------------------------------------

    function layout() {
        if (expanded && overlay) {
            const rect  = overlay.stage.getBoundingClientRect();
            const scale = Math.max(0.1, Math.min(rect.width / W, rect.height / H));
            canvas.style.width  = Math.floor(W * scale) + 'px';
            canvas.style.height = Math.floor(H * scale) + 'px';
        } else {
            canvas.style.width  = '';
            canvas.style.height = '';
        }

        resize();
    }

    function resize() {
        const rect = canvas.getBoundingClientRect();
        const dpr  = window.devicePixelRatio || 1;
        const w    = Math.max(1, Math.round(rect.width * dpr));
        const h    = Math.max(1, Math.round(rect.height * dpr));

        if (canvas.width !== w || canvas.height !== h) {
            canvas.width  = w;
            canvas.height = h;
        }
    }

    // --- отрисовка ------------------------------------------------------------------------------

    function draw(now) {
        if (typeof ResizeObserver === 'undefined') resize();

        ctx.setTransform(canvas.width / W, 0, 0, canvas.height / H, 0, 0);
        ctx.clearRect(0, 0, W, H);

        const view = {
            W: W, H: H, now: now,
            alpha:   running() ? Math.min(1, acc / stepMs) : 0,
            started: started,
            done:    done,
            cfg:     cfg,
        };

        (controls.render || spec.render)(ctx, sim, view);
        hud();
        overlayScreen(view);
    }

    /** Значок и счёт. Цели здесь нет — её знает только сервер; у пазла своя шкала (spec.progress). */
    function hud() {
        const text = spec.progress ? spec.progress(sim).join(' / ') : String(spec.score ? spec.score(sim) : sim.reactions);

        ctx.fillStyle = 'rgba(0,0,0,0.45)';
        ctx.fillRect(0, 0, W, HUD);

        spec.icon(ctx, 11, HUD / 2, 6);

        ctx.fillStyle    = COLORS.text;
        ctx.font         = '600 11px ' + FONT;
        ctx.textBaseline = 'middle';
        ctx.textAlign    = 'left';
        ctx.fillText(text, 22, HUD / 2 + 1);
    }

    // Кнопки на поле — круг R и знак внутри, в одном стиле: старт, перезапуск, готово.
    const R = 22;

    function overlayScreen(view) {
        const cx = W / 2;
        const cy = (H + HUD) / 2;

        if (done) {
            veil('rgba(6,40,30,0.55)');
            badge(cx, cy, COLORS.green, 1);
            ctx.strokeStyle = COLORS.board;
            ctx.lineWidth   = 4;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ctx.beginPath();
            ctx.moveTo(cx - 10, cy);
            ctx.lineTo(cx - 3, cy + 7);
            ctx.lineTo(cx + 10, cy - 7);
            ctx.stroke();
            return;
        }

        if (sim.dead > 0) {
            veil('rgba(251,113,133,' + (0.08 + 0.25 * sim.dead / sim.cooldown).toFixed(3) + ')');
            return;
        }

        const launcher = touchUi && !expanded;
        const restart  = started && sim.idle && !launcher && !paused;

        if (!launcher && !paused && !startable()) return;

        const pulse = 0.92 + 0.08 * Math.sin(view.now / 300);
        const text  = restart ? '' : (touchUi ? hint.touch : hint.keys);
        const lines = text ? wrap(text, W - 32) : [];
        const top   = cy - lines.length * 8;

        veil(started ? 'rgba(7,16,37,0.45)' : 'rgba(7,16,37,0.6)');
        badge(cx, top, COLORS.blue, pulse);

        ctx.fillStyle   = COLORS.text;
        ctx.strokeStyle = COLORS.text;

        if (restart) {
            // Скруглённая стрелка: дуга почти по кругу и наконечник на её конце.
            const r = 10;
            const a = -Math.PI / 2;
            ctx.lineWidth = 3.5;
            ctx.lineCap   = 'round';
            ctx.beginPath();
            ctx.arc(cx, top, r, a + 0.55, a + Math.PI * 2 - 0.15);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(cx + 1, top - r - 5.5);
            ctx.lineTo(cx + 7.5, top - r);
            ctx.lineTo(cx + 1, top - r + 5.5);
            ctx.closePath();
            ctx.fill();
        } else {
            ctx.beginPath();
            ctx.moveTo(cx - 6, top - 10);
            ctx.lineTo(cx + 11, top);
            ctx.lineTo(cx - 6, top + 10);
            ctx.closePath();
            ctx.fill();
        }

        ctx.font         = '600 12px ' + FONT;
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'middle';
        lines.forEach(function (line, i) { ctx.fillText(line, cx, top + R + 14 + i * 15); });
    }

    function badge(x, y, color, scale) {
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(x, y, R * scale, 0, Math.PI * 2);
        ctx.fill();
    }

    /** Строки подсказки по ширине: переносы по словам, не больше трёх строк. */
    function wrap(text, width) {
        ctx.font = '600 12px ' + FONT;

        const lines = [];
        let line = '';

        for (const word of String(text).split(/\s+/)) {
            const next = line ? line + ' ' + word : word;
            if (line && ctx.measureText(next).width > width) { lines.push(line); line = word; }
            else line = next;
        }

        if (line) lines.push(line);
        return lines.slice(0, 3);
    }

    function veil(color) {
        ctx.fillStyle = color;
        ctx.fillRect(0, HUD, W, H - HUD);
    }

    function roundRect(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    // --- клавиатура -----------------------------------------------------------------------------

    function key(name, down, repeat) {
        if (done) return false;
        return controls.key ? controls.key(name, down, repeat) : false;
    }

    canvas.addEventListener('keydown', function (e) {
        if (!e.isTrusted || e.ctrlKey || e.metaKey || e.altKey) return;
        paused = false;
        if (key(e.key, true, e.repeat)) { e.preventDefault(); return; }

        // Пробел и Enter нажимают кнопку старта, даже если игре они не нужны.
        if (!e.repeat && (e.key === ' ' || e.key === 'Enter') && startable()) {
            record(START);
            e.preventDefault();
        }
    });

    canvas.addEventListener('keyup', function (e) {
        if (!e.isTrusted) return;
        if (controls.key && controls.key(e.key, false, false)) e.preventDefault();
    });

    canvas.addEventListener('blur', function () {
        if (controls.release) controls.release();
    });

    // --- пальцы (мышь только фокусирует и жмёт кнопку старта) -------------------------------------

    function at(e) {
        const rect = canvas.getBoundingClientRect();
        return [
            (e.clientX - rect.left) / Math.max(1, rect.width) * W,
            (e.clientY - rect.top) / Math.max(1, rect.height) * H,
        ];
    }

    canvas.addEventListener('pointerdown', function (e) {
        if (!e.isTrusted) return;

        try { canvas.focus({ preventScroll: true }); } catch (_) { canvas.focus(); }

        if (touchUi && !expanded && e.pointerType !== 'mouse') { expand(); return; }
        if (done) return;
        if (paused) { paused = false; return; }
        if (startable()) { record(START); return; }
        if (e.pointerType === 'mouse' && !spec.mouse) return;

        const p = at(e);
        pointer = { x: p[0], y: p[1], cx: e.clientX, cy: e.clientY, swiped: false, t: performance.now() };

        try { canvas.setPointerCapture(e.pointerId); } catch (_) {}

        if (controls.press) controls.press(p[0], p[1]);
    });

    canvas.addEventListener('pointermove', function (e) {
        if (!e.isTrusted || !pointer || (e.pointerType === 'mouse' && !spec.mouse)) return;

        const p = at(e);
        if (controls.move) controls.move(p[0], p[1]);

        if (!controls.swipe) return;

        const dx = e.clientX - pointer.cx;
        const dy = e.clientY - pointer.cy;

        if (Math.abs(dx) < SWIPE && Math.abs(dy) < SWIPE) return;

        controls.swipe(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 1 : 3) : (dy > 0 ? 2 : 0));

        pointer.cx     = e.clientX;
        pointer.cy     = e.clientY;
        pointer.swiped = true;
    });

    function release(e) {
        if (!pointer) return;

        const dx = e.clientX - pointer.cx;
        const dy = e.clientY - pointer.cy;
        const p  = at(e);

        if (e.type === 'pointerup' && !pointer.swiped && Math.abs(dx) < TAP && Math.abs(dy) < TAP
            && performance.now() - pointer.t < 600 && controls.tap)
            controls.tap(pointer.x, pointer.y);

        // Отпускание с координатами — для перетаскивания; при отмене — без них.
        if (controls.up) controls.up(p[0], p[1], e.type === 'pointerup');

        pointer = null;
    }

    canvas.addEventListener('pointerup', release);
    canvas.addEventListener('pointercancel', release);

    // --- телефон: игра поверх страницы и геймпад --------------------------------------------------

    function buildOverlay() {
        const oHost   = el('div');
        const oShadow = oHost.attachShadow({ mode: 'closed' });
        const oStyle  = el('style');
        const screen  = el('div', 'screen');
        const bar     = el('div', 'bar');
        const close   = el('button', 'close');
        const stage   = el('div', 'stage');
        const dpad    = el('div', 'dpad');
        const actions = el('div', 'actions');
        const pad     = spec.pad || {};

        oHost.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:none';
        oStyle.textContent  = OVERLAY_CSS;
        close.type          = 'button';
        close.textContent   = '✕';
        close.setAttribute('aria-label', 'close');
        close.addEventListener('click', collapse);

        const repeatKeys = pad.repeat || [];

        (pad.dpad || []).forEach(function (name) {
            const arrow = ARROWS[name];
            dpad.append(button(name, 'btn ' + arrow[0], arrow[1], repeatKeys.indexOf(name) >= 0));
        });

        if (pad.dpad && pad.dpad.length === 2) dpad.classList.add('h');
        if (!pad.dpad || !pad.dpad.length) screen.classList.add('solo');

        (pad.actions || []).forEach(function (a) {
            actions.append(button(a.key, 'btn act' + (a.big ? ' big' : ''), a.label, repeatKeys.indexOf(a.key) >= 0));
        });

        bar.append(close);
        screen.append(bar, stage, dpad, actions);
        oShadow.append(oStyle, screen);
        document.body.appendChild(oHost);

        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(layout).observe(stage);

        return { host: oHost, stage: stage };
    }

    /** Кнопка геймпада: нажата/отпущена — как клавиша; repeat — автоповтор, пока держат. */
    function button(name, cls, label, repeat) {
        const b = el('button', cls);
        let timer = null;
        let held  = false;

        b.type        = 'button';
        b.textContent = label;
        b.setAttribute('aria-label', name === ' ' ? 'action' : name);

        function stop() {
            if (timer) { clearTimeout(timer); clearInterval(timer); timer = null; }
            if (!held) return;
            held = false;
            b.classList.remove('on');
            key(name, false, false);
        }

        b.addEventListener('pointerdown', function (e) {
            if (!e.isTrusted) return;
            e.preventDefault();
            try { b.setPointerCapture(e.pointerId); } catch (_) {}

            held = true;
            b.classList.add('on');
            if (!key(name, true, false) && startable()) record(START);

            if (repeat)
                timer = setTimeout(function () {
                    timer = setInterval(function () { key(name, true, true); }, REPEAT_EVERY);
                }, REPEAT_DELAY);
        });

        b.addEventListener('pointerup', stop);
        b.addEventListener('pointercancel', stop);
        b.addEventListener('lostpointercapture', stop);
        b.addEventListener('contextmenu', function (e) { e.preventDefault(); });

        return b;
    }

    function expand() {
        if (expanded) return;
        if (!overlay) overlay = buildOverlay();

        overlay.stage.append(canvas);
        overlay.host.style.display = 'block';

        overflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';

        expanded = true;
        paused   = false;
        layout();
    }

    function collapse() {
        if (!expanded) return;

        if (controls.release) controls.release();

        slot.append(canvas);
        overlay.host.style.display = 'none';
        document.documentElement.style.overflow = overflow;

        expanded = false;
        if (!done) paused = true;
        layout();
    }

    function teardown() {
        if (expanded) document.documentElement.style.overflow = overflow;
        if (overlay && overlay.host.parentNode) overlay.host.parentNode.removeChild(overlay.host);
    }

    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(layout).observe(host);
    window.addEventListener('resize', layout);

    // Подключили мышь или включили касания — другой режим запуска и другая подсказка.
    onPointerChange(function () {
        touchUi = coarsePointer();
        if (!touchUi && expanded) collapse();
    });

    if (typeof IntersectionObserver !== 'undefined')
        new IntersectionObserver(function (entries) {
            onScreen = entries[entries.length - 1].isIntersecting;
        }).observe(host);

    fresh();
    layout();
    requestAnimationFrame(frame);

    return fresh;
}
