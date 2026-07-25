<?php

namespace ST_system\Captcha\Drivers;

use ST_system\Captcha\CaptchaDriver;
use ST_system\Captcha\CaptchaManager;
use ST_system\HTTP\WebClient;
use ST_system\Access;

class SmartCaptchaDriver extends CaptchaDriver {

    private const KEY_REGEX = '/^[a-zA-Z0-9_-]{20,100}$/';

    private string $endpoint  = '';
    private string $clientKey = '';
    private string $secret    = '';
    private array  $widget    = [];

    protected static function getDefaultConfig(): array {
        return array_merge(static::baseConfig(), [
            'endpoint'       => 'https://smartcaptcha.yandexcloud.net/',
            'client_key'     => '',
            'secret'         => '',
            'mode'           => 'js',
            'hl'             => 'ru',
            'invisible'      => false,
            'hideShield'     => false,
            'test'           => false,
            'webview'        => false,
            'shieldPosition' => '',
            'class'          => '',
            'style'          => '',
            'behavior'       => array_merge(
                (array)CaptchaManager::config('default.behavior'),
                ['signals' => []]
            ),
        ]);
    }

    protected function __init(array $config): void {
        $this->endpoint  = (string)($config['endpoint'] ?? '');
        $this->clientKey = (string)($config['client_key'] ?? '');
        $this->secret    = (string)($config['secret'] ?? '');

        foreach (['client_key' => $this->clientKey, 'secret' => $this->secret] as $name => $value)
            if ($value !== '' && !preg_match(self::KEY_REGEX, $value))
                throw new \InvalidArgumentException("SmartCaptchaDriver: invalid {$name} format");

        $mode = strtolower((string)($config['mode'] ?? 'js'));

        if (!in_array($mode, ['js', 'html'], true))
            throw new \InvalidArgumentException("SmartCaptchaDriver: mode must be 'js' or 'html'");

        $this->widget = [
            'mode'           => $mode,
            'hl'             => (string)($config['hl'] ?? 'ru'),
            'invisible'      => (bool)($config['invisible'] ?? false),
            'hideShield'     => (bool)($config['hideShield'] ?? false),
            'test'           => (bool)($config['test'] ?? false),
            'webview'        => (bool)($config['webview'] ?? false),
            'shieldPosition' => (string)($config['shieldPosition'] ?? ''),
            'class'          => (string)($config['class'] ?? ''),
            'style'          => (string)($config['style'] ?? ''),
        ];
    }

    protected function __rebind(array $override): void {
        foreach (['endpoint', 'client_key', 'secret'] as $key) {
            if (!isset($override[$key])) continue;

            $value = (string)$override[$key];

            if ($key !== 'endpoint' && $value !== '' && !preg_match(self::KEY_REGEX, $value))
                throw new \InvalidArgumentException("SmartCaptchaDriver: invalid {$key} format");

            if ($key === 'endpoint')   $this->endpoint  = $value;
            if ($key === 'client_key') $this->clientKey = $value;
            if ($key === 'secret')     $this->secret    = $value;
        }

        foreach (array_keys($this->widget) as $key)
            if (isset($override[$key]))
                $this->widget[$key] = is_bool($this->widget[$key]) ? (bool)$override[$key] : (string)$override[$key];
    }

    public function isAvailable(): bool {
        return $this->clientKey !== '' && $this->secret !== '' && $this->endpoint !== '';
    }

    protected function forcedSignals(): array {
        return [];
    }

    protected function issue(array $params): array {
        if (!$this->isAvailable())
            throw new \LogicException('SmartCaptchaDriver: client_key and secret are required');

        $options = ['sitekey' => $this->clientKey, 'hl' => $this->widget['hl']];

        foreach (['invisible', 'hideShield', 'test', 'webview'] as $flag)
            if ($this->widget[$flag]) $options[$flag] = true;

        if ($this->widget['shieldPosition'] !== '')
            $options['shieldPosition'] = $this->widget['shieldPosition'];

        return [
            [],
            [
                'mode'      => $this->widget['mode'],
                'invisible' => $this->widget['invisible'],
                'widget'    => $this->widget['mode'] === 'js' ? $options : null,
            ],
        ];
    }

    protected function render(array $public, string $id): string {
        $host  = 'sc_'.$id;
        $class = $this->widget['class'] !== '' ? ' '.self::esc($this->widget['class']) : '';
        $style = $this->widget['style'] !== '' ? ' style="'.self::esc($this->widget['style']).'"' : '';

        if ($this->widget['mode'] === 'html')
            return '<div id="'.self::esc($host).'" class="smart-captcha'.$class.'"'
                 . ' data-sitekey="'.self::esc($this->clientKey).'"'
                 . ' data-hl="'.self::esc($this->widget['hl']).'"'
                 . ($this->widget['invisible']  ? ' data-invisible="true"'    : '')
                 . ($this->widget['hideShield'] ? ' data-hide-shield="true"'  : '')
                 . ($this->widget['test']       ? ' data-test="true"'         : '')
                 . ($this->widget['webview']    ? ' data-webview="true"'      : '')
                 . ($this->widget['shieldPosition'] !== ''
                     ? ' data-shield-position="'.self::esc($this->widget['shieldPosition']).'"' : '')
                 . $style.'></div>';

        return '<div id="'.self::esc($host).'" class="smart-captcha'.$class.'"'.$style.'></div>';
    }

    protected function verify(array $payload, array $state): bool {
        $token = $this->answer($payload);

        if ($token === '') $token = (string)($payload['smart-token'] ?? '');
        if ($token === '') return false;

        $results = WebClient::create(rtrim($this->endpoint, '/').'/validate', [
            'method'        => 'POST',
            'verify'        => true,
            'response_type' => 'json',
            'headers'       => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ])->fill([
            'secret' => $this->secret,
            'token'  => $token,
            'ip'     => Access::getClientIp(),
        ])->send();

        $body = $results[0]['body'] ?? null;

        if (is_string($body)) $body = @json_decode($body, true);

        return is_array($body) && (string)($body['status'] ?? '') === 'ok';
    }

    protected function driverJs(): string {
        return <<<'JS'
window.STCaptcha && (function (global) {

    if (!global.STSmartCaptcha) {
        var queue = [], widgets = {}, ready = false;

        function emit(container, name, detail) {
            try {
                container.dispatchEvent(new CustomEvent('smartcaptcha:' + name, {
                    detail:  Object.assign({ containerId: container.id }, detail || {}),
                    bubbles: true
                }));
            } catch (e) {}
        }

        function render(id, options) {
            var container = document.getElementById(id);
            if (!container || !global.smartCaptcha) return;

            widgets[id] = global.smartCaptcha.render(container, Object.assign({}, options, {
                callback:  function (token) { emit(container, 'success', { token: token }); },
                onFail:    function ()      { emit(container, 'fail', {}); },
                onExpired: function ()      { emit(container, 'expired', {}); }
            }));

            emit(container, 'ready', { widgetId: widgets[id] });
        }

        global.STSmartCaptcha = {
            get ready() { return ready; },
            widgets: widgets,
            mount:       function (id, options) { ready ? render(id, options) : queue.push([id, options]); },
            execute:     function (id) { if (widgets[id] !== undefined) global.smartCaptcha.execute(widgets[id]); },
            reset:       function (id) { if (widgets[id] !== undefined) global.smartCaptcha.reset(widgets[id]); },
            getResponse: function (id) { return widgets[id] !== undefined ? global.smartCaptcha.getResponse(widgets[id]) : null; },
            destroy:     function (id) {
                if (widgets[id] !== undefined && global.smartCaptcha.destroy) {
                    global.smartCaptcha.destroy(widgets[id]);
                    delete widgets[id];
                }
            },
            _onCdnReady: function () {
                ready = true;
                queue.splice(0).forEach(function (item) { render(item[0], item[1]); });
                global.dispatchEvent(new CustomEvent('smartcaptcha:cdn-ready'));
            }
        };

        global.__stSmartCaptchaOnload = function () { global.STSmartCaptcha._onCdnReady(); };

        if (!document.getElementById('st-smartcaptcha-cdn')) {
            var cdn = document.createElement('script');
            cdn.id    = 'st-smartcaptcha-cdn';
            cdn.src   = 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=__stSmartCaptchaOnload';
            cdn.defer = true;
            document.head.appendChild(cdn);
        }
    }

    STCaptcha.register('smart', class extends STCaptcha.Driver {

        get deferSubmit() { return !!this.cfg.invisible; }

        onMount() {
            var self = this;
            var host = this.root.querySelector('.smart-captcha');

            if (!host) return;

            this.hostId = host.id;

            function bind(name, handler) {
                global.addEventListener('smartcaptcha:' + name, function (e) {
                    if (e.detail && e.detail.containerId === self.hostId) handler(e.detail);
                });
            }

            bind('success', function (detail) { self.solve(detail.token || ''); });
            bind('fail',    function ()       { self.fail('rejected'); });
            bind('expired', function ()       { self.fail('expired'); });

            if (this.cfg.widget) global.STSmartCaptcha.mount(this.hostId, this.cfg.widget);
        }

        execute() {
            var self = this;

            return new Promise(function (resolve) {
                var done = false;

                function finish() { if (!done) { done = true; resolve(); } }

                ['success', 'fail', 'expired'].forEach(function (name) {
                    global.addEventListener('smartcaptcha:' + name, finish, { once: true });
                });

                setTimeout(finish, 15000);
                global.STSmartCaptcha.execute(self.hostId);
            });
        }
    });

})(window);
JS;
    }
}
