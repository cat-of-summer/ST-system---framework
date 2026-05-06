<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;
use ST_system\Access;

final class SmartCaptcha extends IntegrationDriver {

    private const KEY_REGEX     = '/^[a-zA-Z0-9_-]{20,100}$/';

    private static array $instances   = [];
    private static bool  $cdnIncluded = false;

    private string $alias        = '';
    private string $clientKey    = '';
    private string $secret       = '';
    private array  $config       = [];
    private bool   $jsRegistered = false;

    protected static function getDefaultConfig(): array {
        return [
            'endpoint' => 'https://smartcaptcha.yandexcloud.net/',
            'captcha'  => [
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
            ],
        ];
    }

    protected function __init(): void {
        $this->on('__construct', function(array $config = []) {
            $errors = Rule::scope(static::class, fn() => Rule::object([
                'alias'          => 'sometimes|string',
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

            $alias = $config['alias'] ?? 'smartCaptcha';

            if (isset(self::$instances[$alias]))
                throw new \LogicException("SmartCaptcha alias '{$alias}' already taken");

            $defaults = self::getDefaultConfig()['captcha'];
            $captcha  = array_merge($defaults, array_intersect_key($config, $defaults));
            unset($captcha['client_key'], $captcha['secret']);

            $this->alias     = $alias;
            $this->clientKey = $config['client_key'];
            $this->secret    = $config['secret'];
            $this->config    = $captcha;

            self::$instances[$alias] = $this;
        });

        $this->on('call', function($m, &$params) {
            $params['secret'] = $this->secret;
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

    public function __get(string $name) {
        switch ($name) {
            case 'client_key':
            case 'clientKey':
                return $this->clientKey;
            case 'alias':
                return $this->alias;
        }
        throw new \LogicException("SmartCaptcha: unknown property '{$name}'");
    }

    public function validate($params): bool {
        if (!is_array($params))
            $params = ['token' => (string)$params];

        $response = $this->call('validate', $params);

        return is_array($response) && ($response['status'] ?? '') === 'ok';
    }

    public static function includeCDN(): string {
        if (self::$cdnIncluded) return '';
        self::$cdnIncluded = true;

        $bootstrap = <<<'JS'
            <script type="text/javascript">
            (function(){
                if (window.STSmartCaptcha) return;
                var queue = [], widgets = {}, ready = false, instances = {};

                function emit(container, name, detail) {
                    var alias = container.getAttribute('data-captcha-alias') || '';
                    var payload = Object.assign({ alias: alias }, detail);
                    container.dispatchEvent(new CustomEvent('captcha:' + name, { detail: payload, bubbles: true }));
                    window.dispatchEvent(new CustomEvent('captcha:' + name, { detail: Object.assign({ containerId: container.id }, payload) }));
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
                    instances:        instances,
                    registerInstance: function(cfg) { if (cfg && cfg.alias) instances[cfg.alias] = cfg; },
                    mount:            function(id, options) { ready ? render(id, options) : queue.push([id, options]); },
                    execute:          function(id) { if (widgets[id] !== undefined) window.smartCaptcha.execute(widgets[id]); },
                    reset:            function(id) { if (widgets[id] !== undefined) window.smartCaptcha.reset(widgets[id]); },
                    getResponse:      function(id) { return widgets[id] !== undefined ? window.smartCaptcha.getResponse(widgets[id]) : null; },
                    destroy:          function(id) { if (widgets[id] !== undefined && window.smartCaptcha.destroy) { window.smartCaptcha.destroy(widgets[id]); delete widgets[id]; } },
                    _onCdnReady:      function() {
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

        $registrations = '';
        foreach (self::$instances as $inst)
            $registrations .= $inst->include();

        return $bootstrap . $registrations;
    }

    public function include(): string {
        if ($this->jsRegistered) return '';
        $this->jsRegistered = true;

        $payload = json_encode([
            'alias'   => $this->alias,
            'sitekey' => $this->clientKey,
            'hl'      => $this->config['hl'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<script type=\"text/javascript\">window.STSmartCaptcha&&window.STSmartCaptcha.registerInstance({$payload});</script>";
    }

    public function putCaptcha(array $params = []): string {
        if (!self::$cdnIncluded)
            throw new \LogicException('SmartCaptcha::includeCDN() must be called before putCaptcha()');

        $params = array_merge($this->config, $params);

        $id         = 'captcha_' . md5(microtime(true) . random_int(0, PHP_INT_MAX));
        $idAttr     = htmlspecialchars($id, ENT_QUOTES);
        $aliasAttr  = htmlspecialchars($this->alias, ENT_QUOTES);
        $sitekey    = htmlspecialchars($this->clientKey, ENT_QUOTES);
        $classAttr  = $params['class'] !== '' ? ' ' . htmlspecialchars($params['class'], ENT_QUOTES) : '';
        $styleAttr  = $params['style'] !== '' ? ' style="' . htmlspecialchars($params['style'], ENT_QUOTES) . '"' : '';

        if ($params['mode'] === 'html') {
            return sprintf(
                '<div id="%s" class="smart-captcha%s" data-captcha-alias="%s" data-sitekey="%s" data-hl="%s"%s%s%s%s%s%s></div>',
                $idAttr, $classAttr, $aliasAttr, $sitekey, htmlspecialchars($params['hl'], ENT_QUOTES),
                $params['invisible']  ? ' data-invisible="true"'  : '',
                $params['hideShield'] ? ' data-hide-shield="true"' : '',
                $params['test']       ? ' data-test="true"'       : '',
                $params['webview']    ? ' data-webview="true"'    : '',
                $params['shieldPosition'] !== '' ? ' data-shield-position="' . htmlspecialchars($params['shieldPosition'], ENT_QUOTES) . '"' : '',
                $styleAttr
            );
        }

        $opts = ['sitekey' => $this->clientKey]
            + array_filter([
                'hl'             => $params['hl'],
                'invisible'      => $params['invisible'],
                'hideShield'     => $params['hideShield'],
                'test'           => $params['test'],
                'webview'        => $params['webview'],
                'shieldPosition' => $params['shieldPosition'] !== '' ? $params['shieldPosition'] : null,
            ], fn($v) => $v !== null);

        $optsJson = json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<div id=\"{$idAttr}\" class=\"smart-captcha{$classAttr}\" data-captcha-alias=\"{$aliasAttr}\"{$styleAttr}></div>"
             . "<script type=\"text/javascript\">window.STSmartCaptcha&&window.STSmartCaptcha.mount('{$idAttr}',{$optsJson});</script>";
    }

}
