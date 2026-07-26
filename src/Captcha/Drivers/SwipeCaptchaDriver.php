<?php

namespace ST_system\Captcha\Drivers;

use ST_system\Captcha\CaptchaDriver;

class SwipeCaptchaDriver extends CaptchaDriver {

    protected static function getDefaultConfig(): array {
        return array_merge(static::baseConfig(), [
            'target'    => 100,
            'tolerance' => 4,
        ]);
    }

    protected function __init(array $config): void {
        $this->attributes['target']    = max(10, min(100, (int)($config['target'] ?? 100)));
        $this->attributes['tolerance'] = max(1,  min(50,  (int)($config['tolerance'] ?? 4)));
    }

    protected function __rebind(array $override): void {
        if (isset($override['target']))    $this->attributes['target']    = max(10, min(100, (int)$override['target']));
        if (isset($override['tolerance'])) $this->attributes['tolerance'] = max(1,  min(50,  (int)$override['tolerance']));
    }

    public function isAvailable(): bool {
        return true;
    }

    protected function forcedSignals(): array {
        return ['basic'];
    }

    protected function issue(array $params): array {
        $secret = [
            'target'    => (int)$this->attributes['target'],
            'tolerance' => (int)$this->attributes['tolerance'],
        ];

        return [$secret, ['target' => $secret['target']]];
    }

    protected function render(array $public, string $id): string {
        return '<div class="st-captcha-track"><div class="st-captcha-handle"></div></div>';
    }

    protected function verify(array $payload, array $state): bool {
        $answer = @json_decode($this->answer($payload), true);

        if (!is_array($answer)) return false;

        $target    = (float)($state['secret']['target']    ?? 100);
        $tolerance = (float)($state['secret']['tolerance'] ?? 4);
        $percent   = isset($answer['pct']) ? (float)$answer['pct'] : -1.0;

        if ($percent < 0 || abs($percent - $target) > $tolerance) return false;

        return static::monotonic((array)($answer['path'] ?? []));
    }

    private static function monotonic(array $path): bool {
        $count = count($path);
        if ($count < 4) return false;

        $backwards = 0;

        for ($i = 1; $i < $count; $i++) {
            $previous = (float)($path[$i - 1][1] ?? 0);
            $current  = (float)($path[$i][1] ?? 0);

            if ($current < $previous - 2) $backwards++;
        }

        return $backwards <= (int)ceil($count * 0.25);
    }

    protected function driverJs(): string {
        return <<<'JS'
window.STCaptcha && STCaptcha.register('swipe', class extends STCaptcha.Driver {

    onMount() {
        var self   = this;
        var track  = this.root.querySelector('.st-captcha-track');
        var handle = this.root.querySelector('.st-captcha-handle');

        if (!track || !handle) return;

        var dragging = false, origin = 0, offset = 0, path = [], startedAt = 0;

        function span() { return Math.max(1, track.clientWidth - handle.offsetWidth); }

        function place(value) {
            offset = Math.max(0, Math.min(span(), value));
            handle.style.left = offset + 'px';
        }

        handle.addEventListener('pointerdown', function (e) {
            if (e.isTrusted === false || self.solvedFlag) return;

            dragging  = true;
            origin    = e.clientX - offset;
            path      = [];
            startedAt = Date.now();

            self.setState('pending');
            if (handle.setPointerCapture) handle.setPointerCapture(e.pointerId);
        });

        handle.addEventListener('pointermove', function (e) {
            if (!dragging) return;

            place(e.clientX - origin);
            if (path.length < 300) path.push([Date.now() - startedAt, Math.round(offset)]);
        });

        function release() {
            if (!dragging) return;
            dragging = false;

            var percent = Math.round((offset / span()) * 1000) / 10;

            if (Math.abs(percent - (self.cfg.target || 100)) > 20) {
                place(0);
                path = [];
                self.fail('incomplete');
                return;
            }

            handle.style.cursor = 'default';

            self.solve(JSON.stringify({ pct: percent, path: path }));
        }

        handle.addEventListener('pointerup', release);
        handle.addEventListener('pointercancel', release);
        handle.addEventListener('lostpointercapture', release);

        this.onReset = function () {
            place(0);
            path = [];
            handle.style.cursor = '';
        };
    }

    reset() {
        super.reset();
        if (this.onReset) this.onReset();
    }
});
JS;
    }
}
