<?php

namespace ST_system\Captcha;

final class Behavior {

    public const GROUPS = ['basic', 'env', 'pow', 'fingerprint'];

    private const AUTOMATION_MARKERS = [
        '__driver_evaluate', '__webdriver_evaluate', '__selenium_evaluate',
        '__fxdriver_evaluate', '__driver_unwrapped', '__webdriver_unwrapped',
        '__selenium_unwrapped', '__fxdriver_unwrapped', '__webdriver_script_fn',
        '_Selenium_IDE_Recorder', '_selenium', 'calledSelenium', 'callSelenium',
        '__nightmare', '__phantomas', 'callPhantom', '_phantom',
        'domAutomation', 'domAutomationController',
        '__playwright', '__pw_manual', '__puppeteer_evaluation_script__', 'cdc',
    ];

    private const SOFTWARE_RENDERERS = ['swiftshader', 'llvmpipe', 'mesa offscreen', 'softpipe', 'virgl', 'angle (google'];

    public static function score(?string $raw, array $state, array $config): array {
        $signals = array_values(array_intersect(self::GROUPS, (array)($state['signals'] ?? [])));

        if (!$signals)
            return ['score' => 1.0, 'report' => [], 'reasons' => []];

        $data = [];
        if ($raw !== null && $raw !== '') {
            $decoded = @json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded;
        }

        $report  = [];
        $reasons = [];

        if (!$data) {
            foreach ($signals as $group) $report[$group] = 0.0;
            return ['score' => 0.0, 'report' => $report, 'reasons' => ['no_behavior_data']];
        }

        foreach ($signals as $group) {
            switch ($group) {
                case 'basic':
                    $report[$group] = self::scoreBasic((array)($data['basic'] ?? []), $config, $reasons);
                    break;
                case 'env':
                    $report[$group] = self::scoreEnv((array)($data['env'] ?? []), $reasons);
                    break;
                case 'pow':
                    $report[$group] = self::scorePow((array)($data['pow'] ?? []), $state, $reasons);
                    break;
                case 'fingerprint':
                    $report[$group] = self::scoreFingerprint((array)($data['fp'] ?? []), $reasons);
                    break;
            }
        }

        $weights = (array)($config['weights'] ?? []);
        $total   = 0.0;
        $sum     = 0.0;

        foreach ($report as $group => $value) {
            $weight = (float)($weights[$group] ?? 1);
            if ($weight <= 0) continue;

            $total += $value * $weight;
            $sum   += $weight;
        }

        return [
            'score'   => $sum > 0 ? round($total / $sum, 4) : 1.0,
            'report'  => $report,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private static function scoreBasic(array $data, array $config, array &$reasons): float {
        if (!$data) {
            $reasons[] = 'no_basic_data';
            return 0.0;
        }

        if (!empty($data['hp'])) {
            $reasons[] = 'honeypot';
            return 0.0;
        }

        if (isset($data['trusted']) && !$data['trusted']) {
            $reasons[] = 'untrusted_events';
            return 0.0;
        }

        $limits = (array)($config['basic'] ?? []);
        $min    = (int)($limits['min_solve'] ?? 700);
        $score  = 1.0;

        $solve = (int)($data['solve'] ?? 0);
        $first = (int)($data['first'] ?? 0);

        if ($solve <= 0) {
            $reasons[] = 'no_interaction';
            return 0.0;
        }

        if ($solve < $min) {
            $reasons[] = 'too_fast';
            $score -= 0.45;
        }

        if ($first > 0 && $first < 80) {
            $reasons[] = 'instant_start';
            $score -= 0.15;
        }

        $events = (array)($data['ev'] ?? []);
        $moves  = (int)($events['move'] ?? 0);
        $taps   = (int)($events['touch'] ?? 0);
        $keys   = (array)($data['keys'] ?? []);

        if ($moves < 3 && $taps < 1) {
            $reasons[] = 'no_pointer';
            $score -= 0.4;
        }

        $points = [];
        foreach ((array)($data['pts'] ?? []) as $point)
            if (is_array($point) && count($point) >= 3)
                $points[] = [(float)$point[0], (float)$point[1], (float)$point[2]];

        if (count($points) >= 8) {
            if (self::straightness($points) > 0.92) {
                $reasons[] = 'linear_pointer';
                $score -= 0.4;
            }

            $speeds = [];
            for ($i = 1, $total = count($points); $i < $total; $i++) {
                $dt = $points[$i][0] - $points[$i - 1][0];
                if ($dt <= 0) continue;

                $dx = $points[$i][1] - $points[$i - 1][1];
                $dy = $points[$i][2] - $points[$i - 1][2];

                $speeds[] = sqrt($dx * $dx + $dy * $dy) / $dt;
            }

            if (self::entropy($speeds, 8) < 0.35) {
                $reasons[] = 'low_speed_entropy';
                $score -= 0.3;
            }

            if (self::reversals($points) === 0) {
                $reasons[] = 'no_tremor';
                $score -= 0.1;
            }
        } elseif ($taps < 1) {
            $reasons[] = 'short_trajectory';
            $score -= 0.2;
        }

        if (count($keys) >= 4) {
            $dwell  = array_map(fn($pair) => (float)($pair[0] ?? 0), $keys);
            $flight = array_map(fn($pair) => (float)($pair[1] ?? 0), $keys);

            if (self::variation($dwell) < 0.08 || self::variation($flight) < 0.08) {
                $reasons[] = 'robotic_typing';
                $score -= 0.35;
            }
        }

        if (!empty($data['paste'])) {
            $reasons[] = 'pasted_input';
            $score -= 0.1;
        }

        return self::clamp($score);
    }

    private static function scoreEnv(array $data, array &$reasons): float {
        if (!$data) {
            $reasons[] = 'no_env_data';
            return 0.0;
        }

        if (!empty($data['webdriver'])) {
            $reasons[] = 'webdriver';
            return 0.0;
        }

        $markers = array_values(array_intersect(self::AUTOMATION_MARKERS, (array)($data['automation'] ?? [])));
        if ($markers) {
            $reasons[] = 'automation:'.implode(',', $markers);
            return 0.0;
        }

        $score  = 1.0;
        $mobile = !empty($data['mobile']);

        if (!empty($data['headless'])) {
            $reasons[] = 'headless_ua';
            $score -= 0.6;
        }

        if (isset($data['languages']) && (int)$data['languages'] === 0) {
            $reasons[] = 'no_languages';
            $score -= 0.25;
        }

        if (!$mobile && isset($data['plugins']) && (int)$data['plugins'] === 0) {
            $reasons[] = 'no_plugins';
            $score -= 0.15;
        }

        $screen = (array)($data['screen'] ?? []);
        if ((int)($screen[0] ?? 0) <= 0 || (int)($screen[1] ?? 0) <= 0) {
            $reasons[] = 'zero_screen';
            $score -= 0.3;
        }

        $inner = (array)($data['inner'] ?? []);
        if ((int)($inner[0] ?? 0) <= 0 || (int)($inner[1] ?? 0) <= 0) {
            $reasons[] = 'zero_viewport';
            $score -= 0.2;
        }

        if (isset($data['hc']) && (int)$data['hc'] > 0 && (int)$data['hc'] < 2) {
            $reasons[] = 'low_concurrency';
            $score -= 0.1;
        }

        if (($data['tz'] ?? '') === '') {
            $reasons[] = 'no_timezone';
            $score -= 0.1;
        }

        $serverUa = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $clientUa = (string)($data['ua'] ?? '');

        if ($serverUa !== '' && $clientUa !== '' && $serverUa !== $clientUa) {
            $reasons[] = 'ua_mismatch';
            $score -= 0.4;
        }

        if (!self::platformMatches($clientUa !== '' ? $clientUa : $serverUa, (string)($data['platform'] ?? ''))) {
            $reasons[] = 'platform_mismatch';
            $score -= 0.3;
        }

        return self::clamp($score);
    }

    private static function scorePow(array $data, array $state, array &$reasons): float {
        $pow        = (array)($state['pow'] ?? []);
        $challenge  = (string)($pow['challenge'] ?? '');
        $difficulty = (int)($pow['difficulty'] ?? 0);

        if ($challenge === '' || $difficulty <= 0) return 1.0;

        $nonce = (string)($data['nonce'] ?? '');

        if ($nonce === '' || strlen($nonce) > 32) {
            $reasons[] = 'pow_missing';
            return 0.0;
        }

        $binary = hash('sha256', $challenge.$nonce, true);
        $bits   = 0;

        for ($i = 0, $length = strlen($binary); $i < $length; $i++) {
            $byte = ord($binary[$i]);

            if ($byte === 0) {
                $bits += 8;
                continue;
            }

            for ($mask = 7; $mask >= 0; $mask--) {
                if ($byte & (1 << $mask)) break 2;
                $bits++;
            }
        }

        if ($bits < $difficulty) {
            $reasons[] = 'pow_invalid';
            return 0.0;
        }

        return 1.0;
    }

    private static function scoreFingerprint(array $data, array &$reasons): float {
        if (!$data) {
            $reasons[] = 'no_fingerprint';
            return 0.0;
        }

        $score    = 1.0;
        $renderer = strtolower((string)($data['renderer'] ?? ''));

        foreach (self::SOFTWARE_RENDERERS as $needle)
            if ($renderer !== '' && strpos($renderer, $needle) !== false) {
                $reasons[] = 'software_renderer';
                $score -= 0.7;
                break;
            }

        if ($renderer === '') {
            $reasons[] = 'no_webgl';
            $score -= 0.3;
        }

        if ((string)($data['canvas'] ?? '') === '') {
            $reasons[] = 'no_canvas';
            $score -= 0.4;
        }

        if ((string)($data['audio'] ?? '') === '') {
            $reasons[] = 'no_audio';
            $score -= 0.2;
        }

        if ((int)($data['fonts'] ?? 0) <= 2) {
            $reasons[] = 'few_fonts';
            $score -= 0.2;
        }

        return self::clamp($score);
    }

    private static function platformMatches(string $ua, string $platform): bool {
        if ($ua === '' || $platform === '') return true;

        $expected = [
            'Windows'  => ['win'],
            'Android'  => ['linux', 'android', 'arm'],
            'iPhone'   => ['iphone', 'ipad', 'ipod', 'mac'],
            'iPad'     => ['iphone', 'ipad', 'ipod', 'mac'],
            'Mac OS X' => ['mac'],
            'CrOS'     => ['cros', 'linux'],
            'Linux'    => ['linux', 'arm'],
        ];

        $platform = strtolower($platform);

        foreach ($expected as $needle => $allowed) {
            if (stripos($ua, $needle) === false) continue;

            foreach ($allowed as $prefix)
                if (strpos($platform, $prefix) !== false) return true;

            return false;
        }

        return true;
    }

    private static function straightness(array $points): float {
        $count    = count($points);
        $straight = 0;
        $total    = 0;

        for ($i = 2; $i < $count; $i++) {
            $ax = $points[$i - 1][1] - $points[$i - 2][1];
            $ay = $points[$i - 1][2] - $points[$i - 2][2];
            $bx = $points[$i][1]     - $points[$i - 1][1];
            $by = $points[$i][2]     - $points[$i - 1][2];

            $la = sqrt($ax * $ax + $ay * $ay);
            $lb = sqrt($bx * $bx + $by * $by);

            if ($la < 0.5 || $lb < 0.5) continue;

            $total++;
            if (abs($ax * $by - $ay * $bx) / ($la * $lb) < 0.03) $straight++;
        }

        return $total > 0 ? $straight / $total : 0.0;
    }

    private static function reversals(array $points): int {
        $count     = count($points);
        $reversals = 0;

        for ($i = 2; $i < $count; $i++) {
            $ax = $points[$i - 1][1] - $points[$i - 2][1];
            $ay = $points[$i - 1][2] - $points[$i - 2][2];
            $bx = $points[$i][1]     - $points[$i - 1][1];
            $by = $points[$i][2]     - $points[$i - 1][2];

            if (($ax * $bx + $ay * $by) < 0) $reversals++;
        }

        return $reversals;
    }

    private static function entropy(array $values, int $bins): float {
        $count = count($values);
        if ($count < 2 || $bins < 2) return 1.0;

        $min = min($values);
        $max = max($values);
        if ($max - $min <= 0) return 0.0;

        $hist = array_fill(0, $bins, 0);
        foreach ($values as $value) {
            $index = (int)floor(($value - $min) / ($max - $min) * $bins);
            $hist[min($bins - 1, max(0, $index))]++;
        }

        $entropy = 0.0;
        foreach ($hist as $n) {
            if ($n <= 0) continue;
            $p = $n / $count;
            $entropy -= $p * log($p, 2);
        }

        return $entropy / log($bins, 2);
    }

    private static function variation(array $values): float {
        $count = count($values);
        if ($count < 2) return 1.0;

        $mean = array_sum($values) / $count;
        if ($mean <= 0) return 0.0;

        $variance = 0.0;
        foreach ($values as $value) $variance += ($value - $mean) ** 2;

        return sqrt($variance / $count) / $mean;
    }

    private static function clamp(float $value): float {
        return round(max(0.0, min(1.0, $value)), 4);
    }

    public static function bootstrapJs(): string {
        $markers = json_encode(array_values(array_diff(self::AUTOMATION_MARKERS, ['cdc'])), JSON_UNESCAPED_SLASHES);

        return <<<JS
    var AUTOMATION = {$markers};

    function h32(value) {
        var hash = 2166136261 >>> 0;
        for (var i = 0; i < value.length; i++) {
            hash ^= value.charCodeAt(i);
            hash = Math.imul(hash, 16777619) >>> 0;
        }
        return ('00000000' + hash.toString(16)).slice(-8);
    }

    function now() {
        return (global.performance && global.performance.now) ? global.performance.now() : Date.now();
    }

    function leadingZeroBits(buffer) {
        var bytes = new Uint8Array(buffer), bits = 0;
        for (var i = 0; i < bytes.length; i++) {
            if (bytes[i] === 0) { bits += 8; continue; }
            for (var mask = 7; mask >= 0; mask--) {
                if (bytes[i] & (1 << mask)) return bits;
                bits++;
            }
        }
        return bits;
    }

    class Behavior {

        constructor(root, cfg) {
            this.root     = root;
            this.signals  = (cfg && cfg.signals) || [];
            this.powCfg   = (cfg && cfg.pow) || null;
            this.started  = false;
            this.t0       = 0;
            this.first    = 0;
            this.solvedAt = 0;
            this.pts      = [];
            this.keys     = [];
            this.ev       = { down: 0, up: 0, move: 0, key: 0, wheel: 0, touch: 0 };
            this.ptypes   = {};
            this.trusted  = true;
            this.paste    = false;
            this.powDone  = false;
            this.powValue = null;
            this.powTask  = null;
            this.holding  = {};
            this.lastUp   = 0;
            this.unbind   = [];
        }

        has(name) { return this.signals.indexOf(name) !== -1; }

        start() {
            if (this.started) return;
            this.started = true;
            this.t0 = now();

            if (this.has('basic')) this.bindBasic();
            this.powTask = this.has('pow') ? this.solvePow() : Promise.resolve();
        }

        stop() {
            this.unbind.splice(0).forEach(function (off) { off(); });
        }

        solved() {
            this.solvedAt = Math.round(now() - this.t0);
        }

        isReady() { return !this.has('pow') || this.powDone; }

        ready() { return this.powTask || Promise.resolve(); }

        on(target, type, handler, options) {
            target.addEventListener(type, handler, options || false);
            this.unbind.push(function () { target.removeEventListener(type, handler, options || false); });
        }

        bindBasic() {
            var self = this;

            function mark(e) {
                if (e && e.isTrusted === false) self.trusted = false;
                if (!self.first) self.first = Math.round(now() - self.t0);
            }

            this.on(document, 'pointermove', function (e) {
                mark(e);
                self.ev.move++;
                self.ptypes[e.pointerType || 'mouse'] = 1;
                if (self.pts.length < 400 && (self.ev.move % 2) === 0)
                    self.pts.push([Math.round(now() - self.t0), Math.round(e.clientX), Math.round(e.clientY)]);
            }, { passive: true });

            this.on(document, 'pointerdown', function (e) {
                mark(e);
                self.ev.down++;
                self.ptypes[e.pointerType || 'mouse'] = 1;
            }, { passive: true });

            this.on(document, 'pointerup',   function (e) { mark(e); self.ev.up++; }, { passive: true });
            this.on(document, 'wheel',       function (e) { mark(e); self.ev.wheel++; }, { passive: true });
            this.on(document, 'touchstart',  function (e) { mark(e); self.ev.touch++; }, { passive: true });

            this.on(document, 'keydown', function (e) {
                mark(e);
                var key = e.code || e.key;
                if (self.holding[key] === undefined) self.holding[key] = now();
            }, { passive: true });

            this.on(document, 'keyup', function (e) {
                mark(e);
                self.ev.key++;

                var key = e.code || e.key, at = now();
                var dwell = self.holding[key] !== undefined ? Math.round(at - self.holding[key]) : 0;
                delete self.holding[key];

                var flight = self.lastUp ? Math.round(at - self.lastUp) : 0;
                self.lastUp = at;

                if (self.keys.length < 200) self.keys.push([dwell, flight]);
            }, { passive: true });

            this.on(this.root, 'paste', function () { self.paste = true; }, { passive: true });
        }

        solvePow() {
            var self = this;
            var cfg  = this.powCfg;

            if (!cfg || !cfg.challenge || !cfg.difficulty
                || !(global.crypto && global.crypto.subtle && global.TextEncoder)) {
                this.powDone = true;
                return Promise.resolve();
            }

            var encoder = new TextEncoder();
            var startAt = now();

            function batch(base) {
                var jobs = [];

                for (var i = 0; i < 256; i++)
                    jobs.push((function (nonce) {
                        return global.crypto.subtle
                            .digest('SHA-256', encoder.encode(cfg.challenge + nonce))
                            .then(function (buffer) {
                                return leadingZeroBits(buffer) >= cfg.difficulty ? String(nonce) : null;
                            });
                    })(base + i));

                return Promise.all(jobs).then(function (found) {
                    for (var i = 0; i < found.length; i++)
                        if (found[i] !== null) return found[i];

                    return base > 8000000 ? null : batch(base + 256);
                });
            }

            return batch(0).then(function (nonce) {
                self.powDone  = true;
                self.powValue = nonce === null ? null : { nonce: nonce, ms: Math.round(now() - startAt) };
            }, function () {
                self.powDone = true;
            });
        }

        collectBasic() {
            var trap = this.root.querySelector('.st-captcha-hp');

            return {
                first:   this.first,
                solve:   this.solvedAt || Math.round(now() - this.t0),
                pts:     this.pts,
                keys:    this.keys,
                ev:      this.ev,
                ptypes:  Object.keys(this.ptypes),
                trusted: this.trusted ? 1 : 0,
                paste:   this.paste ? 1 : 0,
                hp:      trap && trap.value !== '' ? 1 : 0
            };
        }

        collectEnv() {
            var nav = global.navigator || {};
            var ua  = String(nav.userAgent || '');
            var found = [];

            AUTOMATION.forEach(function (key) {
                try { if (key in global || (global.document && key in global.document)) found.push(key); } catch (e) {}
            });

            try {
                for (var key in document)
                    if (/^\\\$?cdc_|^\\\$chrome_asyncScriptInfo/.test(key)) { found.push('cdc'); break; }
            } catch (e) {}

            var timezone = '';
            try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}

            return {
                webdriver:  nav.webdriver ? 1 : 0,
                automation: found,
                headless:   /Headless/i.test(ua) ? 1 : 0,
                ua:         ua,
                platform:   String(nav.platform || ''),
                vendor:     String(nav.vendor || ''),
                languages:  (nav.languages || []).length,
                plugins:    (nav.plugins || []).length,
                mobile:     /Mobi|Android|iPhone|iPad|iPod/i.test(ua) ? 1 : 0,
                hc:         nav.hardwareConcurrency || 0,
                mem:        nav.deviceMemory || 0,
                touch:      nav.maxTouchPoints || 0,
                screen:     [global.screen ? global.screen.width : 0, global.screen ? global.screen.height : 0, global.screen ? global.screen.colorDepth : 0],
                inner:      [global.innerWidth || 0, global.innerHeight || 0],
                outer:      [global.outerWidth || 0, global.outerHeight || 0],
                tz:         timezone,
                tzo:        new Date().getTimezoneOffset()
            };
        }

        collectFingerprint() {
            return {
                canvas:   this.canvasHash(),
                renderer: this.webglRenderer(),
                audio:    this.audioSignature(),
                fonts:    this.fontCount()
            };
        }

        canvasHash() {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = 220;
                canvas.height = 40;

                var ctx = canvas.getContext('2d');
                if (!ctx) return '';

                ctx.textBaseline = 'top';
                ctx.font = "14px 'Arial'";
                ctx.fillStyle = '#f60';
                ctx.fillRect(100, 5, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText('ST_system \\u2601 captcha', 2, 15);
                ctx.fillStyle = 'rgba(102,204,0,0.7)';
                ctx.fillText('ST_system \\u2601 captcha', 4, 17);
                ctx.globalCompositeOperation = 'multiply';
                ctx.beginPath();
                ctx.arc(50, 20, 18, 0, Math.PI * 2, true);
                ctx.fill();

                return h32(canvas.toDataURL());
            } catch (e) { return ''; }
        }

        webglRenderer() {
            try {
                var canvas = document.createElement('canvas');
                var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                if (!gl) return '';

                var info = gl.getExtension('WEBGL_debug_renderer_info');
                if (info) return String(gl.getParameter(info.UNMASKED_RENDERER_WEBGL) || '');

                return String(gl.getParameter(gl.RENDERER) || '');
            } catch (e) { return ''; }
        }

        audioSignature() {
            try {
                var Ctor = global.OfflineAudioContext || global.webkitOfflineAudioContext;
                if (!Ctor) return '';

                var ctx = new Ctor(1, 4096, 44100);
                var analyser = ctx.createAnalyser();

                return h32([
                    ctx.sampleRate,
                    ctx.destination.maxChannelCount,
                    analyser.frequencyBinCount,
                    analyser.channelCount,
                    ctx.createDynamicsCompressor().threshold.value
                ].join('|'));
            } catch (e) { return ''; }
        }

        fontCount() {
            try {
                var probes = ['Arial', 'Courier New', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Impact', 'Comic Sans MS', 'Trebuchet MS'];
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                if (!ctx) return 0;

                ctx.font = '48px monospace';
                var base = ctx.measureText('mmmmmmmmmmlli').width;
                var count = 0;

                probes.forEach(function (font) {
                    ctx.font = "48px '" + font + "', monospace";
                    if (ctx.measureText('mmmmmmmmmmlli').width !== base) count++;
                });

                return count;
            } catch (e) { return 0; }
        }

        dump() {
            var out = { v: 1 };

            if (this.has('basic'))       out.basic = this.collectBasic();
            if (this.has('env'))         out.env   = this.collectEnv();
            if (this.has('pow'))         out.pow   = this.powValue || {};
            if (this.has('fingerprint')) out.fp    = this.collectFingerprint();

            try { return JSON.stringify(out); } catch (e) { return '{}'; }
        }
    }
JS;
    }
}
