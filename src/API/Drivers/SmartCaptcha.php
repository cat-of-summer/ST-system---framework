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
            Rule::scope(static::class, fn() => Rule::object([
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
            ])->throwable()->apply($config));

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

    /** @return mixed */
    public function __call(string $name, array $args) {
        switch ($name) {
            case 'includeCDN':
                return self::$cdnIncluded ? $this->emitRegistration() : self::emitBootstrap();
        }
        throw new \BadMethodCallException("SmartCaptcha: unknown method '{$name}'");
    }

    /** @return mixed */
    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'includeCDN':
                return self::emitBootstrap();
        }
        throw new \BadMethodCallException("SmartCaptcha: unknown static method '{$name}'");
    }

    private function emitRegistration(): string {
        if ($this->jsRegistered) return '';
        $this->jsRegistered = true;

        $payload = json_encode([
            'alias'   => $this->alias,
            'sitekey' => $this->clientKey,
            'hl'      => $this->config['hl'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<script type=\"text/javascript\">window.STSmartCaptcha&&window.STSmartCaptcha.registerInstance({$payload});</script>";
    }

    private static function emitBootstrap(): string {
        if (self::$cdnIncluded) return '';
        self::$cdnIncluded = true;

        $bootstrap = <<<'JS'
                    <script type="text/javascript">
                    (function(){
                        if (window.STSmartCaptcha) return;
                        var queue = [], widgets = {}, ready = false, instances = {}, globalWidgets = {};

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

                        function listenOnce(captchaId, types, cb) {
                            function clean() { types.forEach(function(t) { window.removeEventListener(t, on); }); }
                            function on(e) {
                                if (e.detail && e.detail.containerId === captchaId) {
                                    clean();
                                    cb(e.type, e.detail);
                                }
                            }
                            types.forEach(function(t) { window.addEventListener(t, on); });
                        }

                        function findInstanceCfg(alias) {
                            if (alias && instances[alias]) return instances[alias];
                            for (var k in instances) if (instances.hasOwnProperty(k)) return instances[k];
                            return null;
                        }

                        window.STSmartCaptcha = {
                            get ready() { return ready; },
                            instances:        instances,
                            registerInstance: function(cfg) { if (cfg && cfg.alias) instances[cfg.alias] = cfg; },
                            mount:            function(id, options) { ready ? render(id, options) : queue.push([id, options]); },
                            execute:          function(id) { if (widgets[id] !== undefined) window.smartCaptcha.execute(widgets[id]); else window.addEventListener('captcha:cdn-ready', function h() { window.removeEventListener('captcha:cdn-ready', h); if (widgets[id] !== undefined) window.smartCaptcha.execute(widgets[id]); }); },
                            reset:            function(id) { if (widgets[id] !== undefined) window.smartCaptcha.reset(widgets[id]); },
                            getResponse:      function(id) { return widgets[id] !== undefined ? window.smartCaptcha.getResponse(widgets[id]) : null; },
                            destroy:          function(id) { if (widgets[id] !== undefined && window.smartCaptcha.destroy) { window.smartCaptcha.destroy(widgets[id]); delete widgets[id]; } },

                            executeAndGetToken: function(captchaId, cb) {
                                listenOnce(captchaId, ['captcha:success', 'captcha:fail', 'captcha:expired'], function(type, detail) {
                                    window.STSmartCaptcha.reset(captchaId);
                                    cb(type === 'captcha:success' ? (detail.token || '') : '', type, detail);
                                });
                                window.STSmartCaptcha.execute(captchaId);
                            },

                            bindForm: function(captchaId, form) {
                                if (!form) {
                                    var c = document.getElementById(captchaId);
                                    form = c && c.closest ? c.closest('form') : null;
                                }
                                if (!form || form.__stCaptchaBound) return;
                                form.__stCaptchaBound = true;

                                var input = form.querySelector('input[name="smart-token"]');
                                if (!input) {
                                    input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'smart-token';
                                    form.appendChild(input);
                                }

                                form.addEventListener('submit', function(ev) {
                                    if (form.__stCaptchaPassed) { form.__stCaptchaPassed = false; return; }
                                    ev.preventDefault();
                                    ev.stopImmediatePropagation();
                                    ev.stopPropagation();

                                    window.STSmartCaptcha.executeAndGetToken(captchaId, function(token) {
                                        if (!token) return;
                                        input.value = token;
                                        form.__stCaptchaPassed = true;
                                        if (form.requestSubmit) form.requestSubmit();
                                        else form.submit();
                                    });
                                }, true);
                            },

                            getToken: function(cb, alias) {
                                var cfg = findInstanceCfg(alias);
                                if (!cfg) { cb(''); return; }

                                var id = globalWidgets[cfg.alias];
                                if (!id) {
                                    id = 'st_global_captcha_' + cfg.alias;
                                    if (!document.getElementById(id)) {
                                        var div = document.createElement('div');
                                        div.id = id;
                                        div.style.display = 'none';
                                        div.setAttribute('data-captcha-alias', cfg.alias);
                                        document.body.appendChild(div);
                                        window.STSmartCaptcha.mount(id, { sitekey: cfg.sitekey, hl: cfg.hl || 'ru', invisible: true, hideShield: true });
                                    }
                                    globalWidgets[cfg.alias] = id;
                                }

                                window.STSmartCaptcha.executeAndGetToken(id, cb);
                            },

                            mountAndBind: function(form, opts) {
                                if (!form) return null;
                                var existing = form.querySelector('.smart-captcha');
                                if (existing && existing.id) { window.STSmartCaptcha.bindForm(existing.id, form); return existing.id; }

                                var cfg = findInstanceCfg(opts && opts.alias);
                                if (!cfg) return null;

                                var cid = 'st_cap_' + Math.random().toString(36).slice(2, 10);
                                var div = document.createElement('div');
                                div.id = cid;
                                div.className = 'smart-captcha';
                                div.setAttribute('data-captcha-alias', cfg.alias);

                                var submit = form.querySelector('[type="submit"]');
                                if (submit) submit.parentNode.insertBefore(div, submit);
                                else form.appendChild(div);

                                window.STSmartCaptcha.mount(cid, Object.assign({ sitekey: cfg.sitekey, hl: cfg.hl || 'ru', invisible: true, hideShield: true }, opts || {}));
                                window.STSmartCaptcha.bindForm(cid, form);
                                return cid;
                            },

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
                    $registrations .= $inst->emitRegistration();

                return $bootstrap . $registrations;
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
             . "<script type=\"text/javascript\">window.STSmartCaptcha&&(window.STSmartCaptcha.mount('{$idAttr}',{$optsJson}),window.STSmartCaptcha.bindForm('{$idAttr}'));</script>";
    }

}
