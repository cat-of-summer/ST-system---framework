<?php

namespace ST_system\Captcha\Drivers\Service;

use ST_system\Access;

class ReCaptchaDriver extends ServiceCaptchaDriver {

    private const ENUMS = [
        'version' => ['v2', 'v3'],
        'mode'    => ['js', 'html'],
        'theme'   => ['light', 'dark'],
        'size'    => ['', 'normal', 'compact'],
        'badge'   => ['', 'bottomright', 'bottomleft', 'inline'],
    ];

    protected static function getDefaultConfig(): array {
        return static::serviceConfig([
            'endpoint'  => 'https://www.google.com/recaptcha/',
            'version'   => 'v2',
            'mode'      => 'js',
            'hl'        => 'ru',
            'invisible' => false,
            'theme'     => 'light',
            'size'      => '',
            'badge'     => '',
            'action'    => 'submit',
        ]);
    }

    protected function initWidget(array $config): array {
        return self::normalize([
            'version'   => strtolower((string)($config['version'] ?? 'v2')),
            'mode'      => strtolower((string)($config['mode'] ?? 'js')),
            'hl'        => (string)($config['hl'] ?? 'ru'),
            'invisible' => (bool)($config['invisible'] ?? false),
            'theme'     => strtolower((string)($config['theme'] ?? 'light')),
            'size'      => strtolower((string)($config['size'] ?? '')),
            'badge'     => strtolower((string)($config['badge'] ?? '')),
            'action'    => (string)($config['action'] ?? 'submit'),
        ]);
    }

    protected function rebindWidget(array $override): void {
        foreach (array_keys(self::ENUMS) as $key)
            $this->widget[$key] = strtolower((string)$this->widget[$key]);

        $this->widget = array_merge($this->widget, self::normalize($this->widget));
    }

    protected function issue(array $params): array {
        if (!$this->isAvailable())
            throw new \LogicException('ReCaptchaDriver: client_key and secret are required');

        $v3 = $this->widget['version'] === 'v3';

        $options = ['sitekey' => $this->clientKey, 'theme' => $this->widget['theme']];

        if ($this->widget['invisible'])       $options['size'] = 'invisible';
        elseif ($this->widget['size'] !== '') $options['size'] = $this->widget['size'];

        if ($this->widget['badge'] !== '') $options['badge'] = $this->widget['badge'];

        return [
            $v3 ? ['action' => $this->widget['action']] : [],
            [
                'version'   => $this->widget['version'],
                'mode'      => $this->widget['mode'],
                'invisible' => $this->widget['invisible'],
                'sitekey'   => $this->clientKey,
                'action'    => $v3 ? $this->widget['action'] : '',
                'cdn'       => $this->cdn(),
                'widget'    => !$v3 && $this->widget['mode'] === 'js' ? $options : null,
            ],
        ];
    }

    protected function render(array $public, string $id): string {
        $host = 'rc_'.$id;

        if ($this->widget['mode'] === 'html')
            return '<div id="'.self::esc($host).'"'.$this->hostClass('st-recaptcha g-recaptcha')
                 . ' data-sitekey="'.self::esc($this->clientKey).'"'
                 . ' data-theme="'.self::esc($this->widget['theme']).'"'
                 . ($this->widget['size']  !== '' ? ' data-size="'.self::esc($this->widget['size']).'"'   : '')
                 . ($this->widget['badge'] !== '' ? ' data-badge="'.self::esc($this->widget['badge']).'"' : '')
                 . $this->hostStyle().'></div>';

        return '<div id="'.self::esc($host).'"'.$this->hostClass('st-recaptcha').$this->hostStyle().'></div>';
    }

    protected function verify(array $payload, array $state): bool {
        $this->attributes['remote_score']  = null;
        $this->attributes['remote_errors'] = [];

        $token = $this->answer($payload);

        if ($token === '') $token = (string)($payload['g-recaptcha-response'] ?? '');
        if ($token === '') return false;

        $body = $this->ask($this->url('api/siteverify'), [
            'secret'   => $this->secret,
            'response' => $token,
            'remoteip' => Access::getClientIp(),
        ]);

        if (array_key_exists('score', $body))
            $this->attributes['remote_score'] = (float)$body['score'];

        if (array_key_exists('error-codes', $body))
            $this->attributes['remote_errors'] = array_values((array)$body['error-codes']);

        if (($body['success'] ?? false) !== true) return false;

        if ($this->widget['version'] !== 'v3') return true;

        $action = (string)($state['secret']['action'] ?? '');

        if ($action !== '' && (string)($body['action'] ?? '') !== $action) return false;

        return (float)($body['score'] ?? 0) >= (float)$this->attributes['min_score'];
    }

    private function cdn(): string {
        $query = [];

        if ($this->widget['version'] === 'v3')  $query['render'] = $this->clientKey;
        elseif ($this->widget['mode'] === 'js') $query['render'] = 'explicit';

        if ($this->widget['hl'] !== '') $query['hl'] = $this->widget['hl'];

        return $this->url('api.js').($query ? '?'.http_build_query($query) : '');
    }

    private static function normalize(array $widget): array {
        foreach (self::ENUMS as $key => $allowed)
            if (!in_array($widget[$key], $allowed, true))
                throw new \InvalidArgumentException(
                    "ReCaptchaDriver: {$key} must be one of '".implode("', '", $allowed)."'"
                );

        if ($widget['version'] === 'v3') $widget['invisible'] = true;
        if ($widget['invisible'])        $widget['mode']      = 'js';

        return $widget;
    }

    protected function driverJs(): string {
        return <<<'JS'
window.STCaptcha && (function (global) {

    if (!global.STReCaptcha) {
        var queue = [], widgets = {}, ready = false;

        function emit(container, name, detail) {
            try {
                container.dispatchEvent(new CustomEvent('recaptcha:' + name, {
                    detail:  Object.assign({ containerId: container.id }, detail || {}),
                    bubbles: true
                }));
            } catch (e) {}
        }

        function render(id, options) {
            var container = document.getElementById(id);
            if (!container || !global.grecaptcha) return;

            widgets[id] = global.grecaptcha.render(container, Object.assign({}, options, {
                'callback':         function (token) { emit(container, 'success', { token: token }); },
                'error-callback':   function ()      { emit(container, 'fail', {}); },
                'expired-callback': function ()      { emit(container, 'expired', {}); }
            }));

            emit(container, 'ready', { widgetId: widgets[id] });
        }

        function whenReady(fn) {
            if (ready) return Promise.resolve().then(fn);

            return new Promise(function (resolve) {
                global.addEventListener('recaptcha:cdn-ready', function () { resolve(fn()); }, { once: true });
            });
        }

        global.STReCaptcha = {
            get ready() { return ready; },
            widgets: widgets,
            load: function (src) {
                if (ready || !src) return;

                if (global.grecaptcha && global.grecaptcha.ready) {
                    global.grecaptcha.ready(function () { global.STReCaptcha._onCdnReady(); });
                    return;
                }

                if (document.getElementById('st-recaptcha-cdn')) return;

                var cdn = document.createElement('script');
                cdn.id     = 'st-recaptcha-cdn';
                cdn.src    = src;
                cdn.defer  = true;
                cdn.async  = true;
                cdn.onload = function () {
                    global.grecaptcha.ready(function () { global.STReCaptcha._onCdnReady(); });
                };
                document.head.appendChild(cdn);
            },
            mount:       function (id, options) { ready ? render(id, options) : queue.push([id, options]); },
            execute:     function (id) { if (widgets[id] !== undefined) global.grecaptcha.execute(widgets[id]); },
            reset:       function (id) { if (widgets[id] !== undefined) global.grecaptcha.reset(widgets[id]); },
            getResponse: function (id) { return widgets[id] !== undefined ? global.grecaptcha.getResponse(widgets[id]) : null; },
            token:       function (sitekey, action) {
                return whenReady(function () {
                    return global.grecaptcha.execute(sitekey, { action: action || 'submit' });
                });
            },
            _onCdnReady: function () {
                if (ready) return;

                ready = true;
                queue.splice(0).forEach(function (item) { render(item[0], item[1]); });
                global.dispatchEvent(new CustomEvent('recaptcha:cdn-ready'));
            }
        };
    }

    STCaptcha.register('recaptcha', class extends STCaptcha.Driver {

        get deferSubmit() { return this.cfg.version === 'v3' || !!this.cfg.invisible; }

        onMount() {
            var self = this;
            var host = this.root.querySelector('.st-recaptcha');

            if (!host) return;

            this.hostId = host.id;

            function bind(name, handler) {
                global.addEventListener('recaptcha:' + name, function (e) {
                    if (e.detail && e.detail.containerId === self.hostId) handler(e.detail);
                });
            }

            bind('success', function (detail) { self.solve(detail.token || ''); });
            bind('fail',    function ()       { self.fail('rejected'); });
            bind('expired', function ()       { self.fail('expired'); });

            global.STReCaptcha.load(this.cfg.cdn);

            if (this.cfg.widget) global.STReCaptcha.mount(this.hostId, this.cfg.widget);
        }

        execute() {
            var self = this;

            if (this.cfg.version === 'v3')
                return global.STReCaptcha.token(this.cfg.sitekey, this.cfg.action).then(
                    function (token) { self.solve(token); },
                    function ()      { self.fail('rejected'); }
                );

            return new Promise(function (resolve) {
                var done = false;

                function finish() { if (!done) { done = true; resolve(); } }

                ['success', 'fail', 'expired'].forEach(function (name) {
                    global.addEventListener('recaptcha:' + name, finish, { once: true });
                });

                setTimeout(finish, 15000);
                global.STReCaptcha.execute(self.hostId);
            });
        }
    });

})(window);
JS;
    }
}
