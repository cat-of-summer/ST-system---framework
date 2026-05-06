<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;
use ST_system\Access;

final class SmartCaptcha extends IntegrationDriver {

    private const KEY_REGEX = '/^[a-zA-Z0-9_-]{20,100}$/';

    protected static function getDefaultConfig(): array {
        return [
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
        ];
    }

    private static bool $cdnIncluded = false;

    protected function __init(): void {
        static::hasConfigInit();

        $this->on('__construct', function(array $config = []) {
            $config = array_merge($config, $config['defaults'] ?? []);
            unset($config['defaults']);

            $errors = Rule::scope(static::class, fn() => Rule::object([
                'client_key'     => ['required|string', Rule::regex(self::KEY_REGEX)->handleError(fn() => 'invalid format')],
                'secret'         => ['required|string', Rule::regex(self::KEY_REGEX)->handleError(fn() => 'invalid format')],
                'mode'           => 'sometimes|in:js,html',
                'hl'             => 'sometimes|string',
                'invisible'      => 'sometimes|bool',
                'hideShield'     => 'sometimes|bool',
                'test'           => 'sometimes|bool',
                'webview'        => 'sometimes|bool',
                'shieldPosition' => 'sometimes|string',
                'class'          => 'sometimes|string',
                'style'          => 'sometimes|string',
            ])->apply($config));

            if (!empty($errors))
                throw new \InvalidArgumentException('SmartCaptcha config: ' . implode('; ', $errors));

            static::setConfig($config);
        });

        $this->on('call', function($m, &$params) {
            $params['secret'] = static::config('secret');
        });

        $this->registerMethodsMap([
            'validate' => [
                'method' => 'POST',
                'params' => [
                    'token' => 'required|string|trim',
                    'ip'    => ['string', Rule::default(Access::getClientIp())],
                ],
            ],
        ]);
    }

    public function validate($params): bool {
        if (!is_array($params))
            $params = ['token' => (string)$params];

        $response = $this->call('validate', $params);

        return is_array($response) && ($response['status'] ?? '') === 'ok';
    }

    public function includeCDN(): string {
        if (self::$cdnIncluded) return '';
        self::$cdnIncluded = true;

        return <<<'JS'
            <script type="text/javascript">
            (function(){
                if (window.STSmartCaptcha) return;
                var queue = [], widgets = {}, ready = false;

                function emit(container, name, detail) {
                    container.dispatchEvent(new CustomEvent('captcha:' + name, { detail: detail, bubbles: true }));
                    window.dispatchEvent(new CustomEvent('captcha:' + name, { detail: Object.assign({ containerId: container.id }, detail) }));
                }

                function render(id, options) {
                    var container = document.getElementById(id);
                    if (!container || !window.smartCaptcha) return;
                    container.setAttribute('data-captcha-state', 'pending');

                    widgets[id] = window.smartCaptcha.render(container, Object.assign({}, options, {
                        callback:  function(token) { container.setAttribute('data-captcha-state', 'valid');   emit(container, 'success', { token: token }); },
                        onFail:    function()      { container.setAttribute('data-captcha-state', 'invalid'); emit(container, 'fail', {}); },
                        onExpired: function()      { container.setAttribute('data-captcha-state', 'expired'); emit(container, 'expired', {}); }
                    }));
                    emit(container, 'ready', { widgetId: widgets[id] });
                }

                window.STSmartCaptcha = {
                    get ready() { return ready; },
                    mount:       function(id, options) { ready ? render(id, options) : queue.push([id, options]); },
                    execute:     function(id) { if (widgets[id] !== undefined) window.smartCaptcha.execute(widgets[id]); },
                    reset:       function(id) { if (widgets[id] !== undefined) window.smartCaptcha.reset(widgets[id]); },
                    getResponse: function(id) { return widgets[id] !== undefined ? window.smartCaptcha.getResponse(widgets[id]) : null; },
                    destroy:     function(id) { if (widgets[id] !== undefined && window.smartCaptcha.destroy) { window.smartCaptcha.destroy(widgets[id]); delete widgets[id]; } },
                    _onCdnReady: function() {
                        ready = true;
                        queue.splice(0).forEach(function(t) { render(t[0], t[1]); });
                        window.dispatchEvent(new CustomEvent('captcha:cdn-ready'));
                    }
                };
                window.__stSmartCaptchaOnload = function() { window.STSmartCaptcha._onCdnReady(); };
            })();
            </script>
            <script src="https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=__stSmartCaptchaOnload" defer></script>
        JS;
    }

    public function putCaptcha(array $params = []): string {
        if (!self::$cdnIncluded)
            throw new \LogicException('SmartCaptcha::includeCDN() must be called before putCaptcha()');

        Rule::scope(static::class, fn() => Rule::object([
            'mode'           => 'defaultConfig|in:js,html',
            'hl'             => 'defaultConfig|string',
            'invisible'      => 'defaultConfig|bool',
            'hideShield'     => 'defaultConfig|bool',
            'test'           => 'defaultConfig|bool',
            'webview'        => 'defaultConfig|bool',
            'shieldPosition' => 'defaultConfig|string',
            'class'          => 'defaultConfig|string',
            'style'          => 'defaultConfig|string',
        ])->apply($params));

        $id        = 'captcha_' . md5(microtime(true) . random_int(0, PHP_INT_MAX));
        $idAttr    = htmlspecialchars($id, ENT_QUOTES);
        $sitekey   = htmlspecialchars(static::config('client_key'), ENT_QUOTES);
        $classAttr = $params['class'] !== '' ? ' ' . htmlspecialchars($params['class'], ENT_QUOTES) : '';
        $styleAttr = $params['style'] !== '' ? ' style="' . htmlspecialchars($params['style'], ENT_QUOTES) . '"' : '';

        if ($params['mode'] === 'html') {
            return sprintf(
                '<div id="%s" class="smart-captcha%s" data-sitekey="%s" data-hl="%s"%s%s%s%s%s%s></div>',
                $idAttr, $classAttr, $sitekey, htmlspecialchars($params['hl'], ENT_QUOTES),
                $params['invisible']  ? ' data-invisible="true"'  : '',
                $params['hideShield'] ? ' data-hide-shield="true"' : '',
                $params['test']       ? ' data-test="true"'       : '',
                $params['webview']    ? ' data-webview="true"'    : '',
                $params['shieldPosition'] !== '' ? ' data-shield-position="' . htmlspecialchars($params['shieldPosition'], ENT_QUOTES) . '"' : '',
                $styleAttr
            );
        }

        $opts = ['sitekey' => static::config('client_key')]
            + array_filter([
                'hl'             => $params['hl'],
                'invisible'      => $params['invisible'],
                'hideShield'     => $params['hideShield'],
                'test'           => $params['test'],
                'webview'        => $params['webview'],
                'shieldPosition' => $params['shieldPosition'] !== '' ? $params['shieldPosition'] : null,
            ], fn($v) => $v !== null);

        $optsJson = json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<div id=\"{$idAttr}\" class=\"smart-captcha{$classAttr}\"{$styleAttr}></div>"
             . "<script type=\"text/javascript\">window.STSmartCaptcha&&window.STSmartCaptcha.mount('{$idAttr}',{$optsJson});</script>";
    }

}
