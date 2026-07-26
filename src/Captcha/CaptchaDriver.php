<?php

namespace ST_system\Captcha;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasAttributes;
use ST_system\Traits\Events\HasEvents;
use ST_system\Cache\CacheManager;
use ST_system\Access;
use ST_system\Main;
use ST_system\Rule;

abstract class CaptchaDriver {

    use HasConfig;
    use HasAttributes;
    use HasEvents;

    protected static function getReservedEvents(): array {
        return ['issue', 'verify', 'score'];
    }

    private const ID_REGEX = '/^[a-f0-9]{32}$/';

    private static array $js_emitted  = [];
    private static array $css_emitted = [];

    protected string $id;

    protected static function baseConfig(): array {
        return [
            'ttl'          => CaptchaManager::config('default.ttl'),
            'attempts'     => CaptchaManager::config('default.attempts'),
            'min_score'    => CaptchaManager::config('default.min_score'),
            'field_prefix' => CaptchaManager::config('default.field_prefix'),
            'salt'         => CaptchaManager::config('default.salt'),
            'cache'        => CaptchaManager::config('default.cache'),
            'behavior'     => CaptchaManager::config('default.behavior'),
        ];
    }

    protected static function getDefaultConfig(): array {
        return static::baseConfig();
    }

    final public function __construct($key, array $config = []) {
        static::assertKey($key);

        $this->attributes['raw_key'] = $key;
        $this->id = Main::hash($key);

        static::applyConfig($config);
        Rule::scope(static::class, fn() => $this->__init($config));

        $this->attributes['ttl']          = max(1, (int)($config['ttl'] ?? 300));
        $this->attributes['attempts']     = max(1, (int)($config['attempts'] ?? 3));
        $this->attributes['min_score']    = (float)($config['min_score'] ?? 0);
        $this->attributes['field_prefix'] = (string)($config['field_prefix'] ?? 'st-captcha');
        $this->attributes['salt']         = self::assertSalt((string)($config['salt'] ?? ''));
        $this->attributes['cache']        = (array)($config['cache'] ?? []);
        $this->attributes['behavior']     = $this->resolveBehavior($config['behavior'] ?? true);
        $this->attributes['issued_id']    = '';

        $this->purgeResult();
    }

    final public function spawn($key, array $override = []): self {
        static::assertKey($key);

        $clone = clone $this;

        $clone->attributes['raw_key'] = $key;
        $clone->id = Main::hash($key);
        $clone->purgeAttributes();
        $clone->purgeResult();

        if (isset($override['ttl']))          $clone->attributes['ttl']          = max(1, (int)$override['ttl']);
        if (isset($override['attempts']))     $clone->attributes['attempts']     = max(1, (int)$override['attempts']);
        if (isset($override['min_score']))    $clone->attributes['min_score']    = (float)$override['min_score'];
        if (isset($override['field_prefix'])) $clone->attributes['field_prefix'] = (string)$override['field_prefix'];
        if (isset($override['salt']))         $clone->attributes['salt']         = self::assertSalt((string)$override['salt']);
        if (isset($override['cache']))        $clone->attributes['cache']        = (array)$override['cache'];

        if (array_key_exists('behavior', $override))
            $clone->attributes['behavior'] = $clone->resolveBehavior($override['behavior']);

        Rule::scope(static::class, fn() => $clone->__rebind($override));

        return $clone;
    }

    abstract protected function __init(array $config): void;
    protected function __rebind(array $override): void {}

    abstract public function isAvailable(): bool;

    abstract protected function issue(array $params): array;
    abstract protected function render(array $public, string $id): string;
    abstract protected function verify(array $payload, array $state): bool;
    abstract protected function driverJs(): string;

    protected function driverCss(): string { return ''; }
    protected function forcedSignals(): array { return []; }
    protected function providesAnswerField(): bool { return false; }

    public static function name(): string {
        $available = (array)CaptchaManager::config('drivers.available');
        $found     = array_search(static::class, $available, true);

        return $found !== false ? (string)$found : Main::snakeCase(Main::basename(static::class));
    }

    final public function putCaptcha(array $params = []): string {
        CaptchaManager::markLive();

        $id       = bin2hex(random_bytes(16));
        $ttl      = max(1, (int)($params['ttl'] ?? $this->attributes['ttl']));
        $behavior = (array)$this->attributes['behavior'];
        $signals  = (array)($behavior['signals'] ?? []);

        [$secret, $public] = $this->issue($params);

        $pow = [];
        if (in_array('pow', $signals, true))
            $pow = [
                'challenge'  => bin2hex(random_bytes(16)),
                'difficulty' => max(1, (int)($behavior['pow']['difficulty'] ?? 15)),
            ];

        $state = [
            'driver'    => static::name(),
            'class'     => static::class,
            'key'       => $this->id,
            'issued_at' => time(),
            'expires'   => time() + $ttl,
            'attempts'  => 0,
            'max'       => (int)$this->attributes['attempts'],
            'signals'   => $signals,
            'pow'       => $pow,
            'honeypot'  => 'c_'.bin2hex(random_bytes(4)),
            'secret'    => (array)$secret,
        ];

        $this->writeState($id, $state, $ttl);
        $this->attributes['issued_id'] = $id;
        $this->fire('issue', $id, $state, $public);

        $dom    = 'stc_'.$id;
        $config = array_merge((array)$public, [
            'id'      => $id,
            'driver'  => $state['driver'],
            'prefix'  => $this->attributes['field_prefix'],
            'signals' => $signals,
            'pow'     => $pow ?: null,
        ]);

        $prefix = self::esc($this->attributes['field_prefix']);

        $fields = '<input type="hidden" name="'.$prefix.'-id" value="'.self::esc($id).'">'
                . '<input type="hidden" name="'.$prefix.'-behavior" value="">'
                . ($this->providesAnswerField() ? '' : '<input type="hidden" name="'.$prefix.'-answer" value="">')
                . '<input type="text" class="st-captcha-hp" name="'.self::esc($state['honeypot']).'"'
                . ' value="" tabindex="-1" autocomplete="off" aria-hidden="true">';

        return '<div class="st-captcha" id="'.self::esc($dom).'"'
             . ' data-st-captcha="'.self::esc($state['driver']).'" data-captcha-state="pending">'
             . $this->render((array)$public, $id)
             . $fields
             . '</div>'
             . '<script type="text/javascript">(window.STCaptchaQueue=window.STCaptchaQueue||[]).push(['
             . self::json($dom).','.self::json($config)
             . ']);window.STCaptcha&&window.STCaptcha.flush();</script>';
    }

    final public function check($payload): bool {
        $this->purgeResult();

        $prefix = (string)$this->attributes['field_prefix'];

        if (!is_array($payload))
            $payload = [$prefix.'-answer' => (string)$payload];

        $id = (string)($payload[$prefix.'-id'] ?? '');

        if ($id === '' || !preg_match(self::ID_REGEX, $id))
            return $this->reject('expired');

        $store = $this->store($id);
        $state = Access::unseal($store->get(), (string)$this->attributes['salt']);

        if (!$state)
            return $this->reject('expired');

        if (!empty($state['used']))
            return $this->reject('replayed');

        if (($state['key'] ?? '') !== $this->id)
            return $this->reject('mismatch');

        $attempts = (int)($state['attempts'] ?? 0) + 1;

        if ($attempts > (int)($state['max'] ?? 3)) {
            $store->purge();
            return $this->reject('attempts');
        }

        if (!empty($state['honeypot']) && trim((string)($payload[$state['honeypot']] ?? '')) !== '') {
            $store->purge();
            return $this->reject('honeypot');
        }

        $answered = (bool)$this->verify($payload, $state);
        $this->fire('verify', $payload, $state, $answered);

        $result = Behavior::score(
            array_key_exists($prefix.'-behavior', $payload) ? (string)$payload[$prefix.'-behavior'] : null,
            $state,
            (array)$this->attributes['behavior']
        );

        $this->attributes['score']   = $result['score'];
        $this->attributes['report']  = $result['report'];
        $this->attributes['reasons'] = $result['reasons'];

        $trusted = empty($state['signals']) || $result['score'] >= (float)$this->attributes['min_score'];
        $passed  = $answered && $trusted;

        $this->fire('score', $result, $state, $passed);

        $remain = (int)($state['expires'] ?? 0) - time();

        if ($passed) {
            if ($remain > 0) $this->writeState($id, ['used' => 1, 'expires' => $state['expires']], $remain);
            else             $store->purge();

            $this->attributes['passed'] = true;
            $this->attributes['error']  = '';

            return true;
        }

        $state['attempts'] = $attempts;

        if ($remain > 0) $this->writeState($id, $state, $remain);
        else             $store->purge();

        return $this->reject($answered ? 'behavior' : 'answer');
    }

    final public function refresh(string $id = ''): array {
        if ($id !== '' && preg_match(self::ID_REGEX, $id))
            $this->store($id)->purge();

        $html = $this->putCaptcha();

        return [
            'id'   => (string)$this->attributes['issued_id'],
            'html' => $html,
        ];
    }

    public static function resetEmitted(): void {
        self::$js_emitted  = [];
        self::$css_emitted = [];
    }

    final public function includeJs(): string {
        $js   = '';
        $name = static::name();

        if (!isset(self::$js_emitted[''])) {
            self::$js_emitted[''] = true;
            $js .= '<script type="text/javascript">'."\n".self::bootstrapJs()."\n".'</script>';
        }

        if (!isset(self::$js_emitted[$name])) {
            self::$js_emitted[$name] = true;
            $body = $this->driverJs();

            if (trim($body) !== '')
                $js .= '<script type="text/javascript">'."\n".$body."\n".'</script>';
        }

        return $js;
    }

    final public function includeCss(): string {
        $css  = '';
        $name = static::name();

        if (!isset(self::$css_emitted[''])) {
            self::$css_emitted[''] = true;
            $css .= '.st-captcha{display:inline-block}'
                  . '.st-captcha-hp{position:absolute!important;left:-9999px!important;top:0;width:1px;height:1px;opacity:0;pointer-events:none}'
                  . '.st-captcha-image{display:block;max-width:100%}'
                  . '.st-captcha-input{display:block;width:240px}'
                  . '.st-captcha-track{position:relative;width:260px;height:36px;border:1px solid;overflow:hidden;touch-action:none;user-select:none}'
                  . '.st-captcha-handle{position:absolute;left:0;top:0;width:36px;height:36px;border:1px solid;cursor:grab;touch-action:none}';
        }

        if (!isset(self::$css_emitted[$name])) {
            self::$css_emitted[$name] = true;
            $css .= $this->driverCss();
        }

        return trim($css) === '' ? '' : '<style type="text/css">'.$css.'</style>';
    }

    final protected function field(string $name): string {
        return $this->attributes['field_prefix'].'-'.$name;
    }

    final protected function answer(array $payload): string {
        return (string)($payload[$this->field('answer')] ?? '');
    }

    final protected static function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    final protected static function json($value): string {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    private function resolveBehavior($value): array {
        $default = (array)static::config('behavior');
        $forced  = array_values(array_intersect(Behavior::GROUPS, $this->forcedSignals()));

        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            $signals = [];
        } elseif ($value === true || $value === null || $value === '' || $value === 1) {
            $signals = (array)($default['signals'] ?? []);
        } elseif (is_array($value) && Main::arrayIsList($value)) {
            $signals = $value;
        } elseif (is_array($value)) {
            $default = array_replace($default, $value);
            $signals = (array)($default['signals'] ?? []);
        } else {
            $signals = (array)($default['signals'] ?? []);
        }

        $default['signals'] = array_values(array_intersect(
            Behavior::GROUPS,
            array_unique(array_merge($signals, $forced))
        ));

        return $default;
    }

    private function store(string $id): CacheManager {
        $cache  = (array)$this->attributes['cache'];
        $config = ['driver' => (string)(!empty($cache['driver']) ? $cache['driver'] : 'filesystem')];

        if (!empty($cache['dir']))
            $config['dir'] = (string)$cache['dir'];

        return CacheManager::make(['st_captcha', $id], $config);
    }

    private function writeState(string $id, array $state, int $ttl): void {
        $this->store($id)->set(Access::seal($state, (string)$this->attributes['salt']), max(1, $ttl));
    }

    private static function assertSalt(string $salt): string {
        if ($salt !== '' || (string)Access::config('salt') !== '') return $salt;

        throw new \LogicException(
            static::class.': captcha salt is required; set CaptchaManager::config("default.salt"), '
            .'the per-driver "salt" option, or a global Access::config("salt")'
        );
    }

    private static function assertKey($key): void {
        if ($key === '' || $key === null || $key === [] || $key === false)
            throw new \InvalidArgumentException(static::class.': captcha key is required');
    }

    private function reject(string $code): bool {
        $this->attributes['passed'] = false;
        $this->attributes['error']  = $code;

        return false;
    }

    private function purgeResult(): void {
        $this->attributes['passed']  = false;
        $this->attributes['error']   = '';
        $this->attributes['score']   = 0.0;
        $this->attributes['report']  = [];
        $this->attributes['reasons'] = [];
    }

    private static function bootstrapJs(): string {
        $head = <<<'JS'
(function (global) {

    if (global.STCaptcha) return;

JS;

        $tail = <<<'JS'

    function emit(root, name, detail) {
        try {
            root.dispatchEvent(new CustomEvent('captcha:' + name, {
                detail:  Object.assign({ id: root.id }, detail || {}),
                bubbles: true
            }));
        } catch (e) {}
    }

    class Driver {

        constructor(root, cfg) {
            this.root       = root;
            this.cfg        = cfg || {};
            this.prefix     = this.cfg.prefix || 'st-captcha';
            this.form       = root.closest ? root.closest('form') : null;
            this.behavior   = new Behavior(root, this.cfg);
            this.solvedFlag = false;
        }

        get deferSubmit() { return false; }

        field(name) {
            return this.root.querySelector('[name="' + this.prefix + '-' + name + '"]');
        }

        mount() {
            this.behavior.start();
            this.setState('pending');
            this.onMount();
            this.bindForm();
            emit(this.root, 'ready', {});
        }

        onMount() {}

        execute() { return Promise.resolve(); }

        setState(value) { this.root.setAttribute('data-captcha-state', value); }

        answer(value) {
            var field = this.field('answer');
            if (field) field.value = (value === null || value === undefined) ? '' : String(value);
        }

        solve(value) {
            this.answer(value);
            this.solvedFlag = true;
            this.behavior.solved();
            this.setState('valid');
            emit(this.root, 'success', { value: value });
        }

        fail(reason) {
            this.answer('');
            this.solvedFlag = false;
            this.setState('invalid');
            emit(this.root, 'fail', { reason: reason || '' });
        }

        reset() {
            this.answer('');
            this.solvedFlag = false;
            this.setState('pending');
            emit(this.root, 'reset', {});
        }

        collect() {
            var field = this.field('behavior');
            if (field) field.value = this.behavior.dump();
        }

        bindForm() {
            var form = this.form;
            if (!form) return;

            if (!form.__stCaptcha) form.__stCaptcha = [];
            form.__stCaptcha.push(this);

            if (form.__stCaptchaBound) return;
            form.__stCaptchaBound = true;

            function finish() {
                form.__stCaptcha.forEach(function (item) { item.collect(); });
                form.__stCaptchaPassed = true;
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }

            form.addEventListener('submit', function (ev) {
                if (form.__stCaptchaPassed) { form.__stCaptchaPassed = false; return; }

                var pending = form.__stCaptcha.filter(function (item) {
                    return (item.deferSubmit && !item.solvedFlag) || !item.behavior.isReady();
                });

                if (!pending.length) {
                    form.__stCaptcha.forEach(function (item) { item.collect(); });
                    return;
                }

                ev.preventDefault();
                ev.stopImmediatePropagation();
                ev.stopPropagation();

                Promise.all(pending.map(function (item) {
                    return Promise
                        .resolve(item.deferSubmit && !item.solvedFlag ? item.execute() : null)
                        .then(function () { return item.behavior.ready(); });
                })).then(finish, finish);
            }, true);
        }
    }

    var api = {

        Driver:    Driver,
        Behavior:  Behavior,
        drivers:   {},
        instances: {},

        register: function (name, cls) {
            api.drivers[name] = cls;
            api.flush();
        },

        get: function (id) { return api.instances[id] || null; },

        mount: function (id, cfg) {
            (global.STCaptchaQueue = global.STCaptchaQueue || []).push([id, cfg]);
            api.flush();
        },

        flush: function () {
            var queue = global.STCaptchaQueue = global.STCaptchaQueue || [];

            for (var i = 0; i < queue.length; i++) {
                var item = queue[i];
                if (!item || item.mounted) continue;

                var cfg  = item[1] || {};
                var cls  = api.drivers[cfg.driver];
                var root = document.getElementById(item[0]);

                if (!cls || !root) continue;

                item.mounted = true;
                api.instances[item[0]] = new cls(root, cfg);
                api.instances[item[0]].mount();
            }
        }
    };

    global.STCaptcha = api;

    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', function () { api.flush(); });
    else
        api.flush();

})(window);
JS;

        return $head.Behavior::bootstrapJs().$tail;
    }
}
