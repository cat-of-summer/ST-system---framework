<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasEvents;
use ST_system\Traits\HasInstance;
use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\Cache\Manager as Cache;
use ST_system\Rule;

final class Access {

    use HasInstance;

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'credentials' => [
                'name' => 'pass',
                'value' => date('dm')
            ],
            'accessMethod' => 'GET',
            'CORS' => [
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
                'headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
            ],
            'salt' => '',
            'firewall' => [
                'driver'  => 'filesystem',
                'dir'     => '',
                'limits'  => [[5, 1, 10], [10, 2, 30], [60, 60], [600, 3600]],
                'ttl'     => 3600,
                'exclude' => [],
            ],
        ];
    }

    use HasEvents {
        on as private _on;
    }

    private static $block = [
        'name'         => null,
        'value'        => null,
        'accessMethod' => null,
    ];

    private Cache $cache;

    private function __construct() {
        $config = [
            'driver' => static::config('firewall.driver'),
            'ttl'    => static::config('firewall.ttl'),
        ];

        if ($config['driver'] === 'filesystem')
            $config['dir'] = static::config('firewall.dir');

        $this->cache = Cache::make(static::config('salt') ?: 'st_access', $config);
    }

    public static function on(string $event, callable $listener): void {
        self::getInstance()->_on($event, $listener);
    }

    private static function extractCredential(string $name, string $method): ?string {
        switch ($method) {
            case 'GET':     return Request::get($name);
            case 'POST':
            case 'PUT':
            case 'DELETE':
            case 'PATCH':   return Request::post($name);
            case 'HEADERS': return Request::headers($name);
            case 'COOKIE':  return Request::cookie($name);
            case 'SESSION': return $_SESSION[$name] ?? null;
        }

        throw new \InvalidArgumentException("Access method not allowed: '$method'");
    }

    public static function requestAccess(array $config = []) {
        static::applyConfig($config, [
            'name'         => 'string|html_decode|@credentials.name',
            'value'        => 'string|html_decode|@credentials.value',
            'accessMethod' => 'nullable|string|@accessMethod',
            'onFail'       => ['callable', Rule::default(fn() => self::throw(401))],
            'onSuccess'    => 'nullable|callable'
        ]);

        $val = self::extractCredential($config['name'], $config['accessMethod']);

        return (empty($val) || $val != $config['value'])
            ? $config['onFail']
            : $config['onSuccess'];
    }

    public static function handleGeo(array $config = []) {
        static::applyConfig($config, [
            'token'       => 'string|required',
            'driver'      => ['string', Rule::default(\ST_system\API\Drivers\IpInfo::class)],
            'ip'          => ['string', Rule::default(self::getClientIp())],
            'white_list'  => ['array', Rule::default([])],
            'black_list'  => ['array', Rule::default([])],
            'onBlackList' => ['callable', Rule::default(fn() => self::throw(403))],
            'onWhiteList' => 'nullable|callable',
            'onPassed'    => 'nullable|callable',
        ]);

        $details = $config['driver']::create($config['token'])->getDetails($config['ip']);

        foreach ($config['black_list'] as $field => $allowed) {
            if (!is_array($allowed)) $allowed = [$allowed];

            if (isset($details[$field]) && in_array($details[$field], $allowed, true))
                return ($config['onBlackList'])($details);
        }

        if (!empty($config['white_list'])) {
            $whiteHit = true;
            foreach ($config['white_list'] as $field => $allowed) {
                if (!is_array($allowed)) $allowed = [$allowed];

                if (!isset($details[$field]) || !in_array($details[$field], $allowed, true)) {
                    $whiteHit = false;
                    break;
                }
            }
            if ($whiteHit)
                return isset($config['onWhiteList']) ? ($config['onWhiteList'])($details) : $details;
        }

        return isset($config['onPassed']) ? ($config['onPassed'])($details) : $details;
    }

    public static function httpAccess(array $config = []) {
        static::applyConfig($config, [
            'login'    => 'string|html_decode|@credentials.name',
            'password' => 'string|html_decode|@credentials.value',
        ]);

        if (
            !isset($_SERVER['PHP_AUTH_USER']) ||
            !isset($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] !== $config['login'] ||
            $_SERVER['PHP_AUTH_PW'] !== $config['password']
        ) {
            Response::status(401)->header('WWW-Authenticate', 'Basic realm="Restricted Area"')->send();
        }
    }

    public static function call(callable $f, array $config = []) {
        static::applyConfig($config, [
            'name'         => 'string|html_decode|@credentials.name',
            'value'        => 'string|html_decode|@credentials.value',
            'accessMethod' => 'nullable|string|@accessMethod',
        ]);

        $val = self::extractCredential($config['name'], $config['accessMethod']);

        if ($val !== null && $val == $config['value'])
            return $f();
    }

    public static function startBlock(array $config = []) {
        static::applyConfig($config, [
            'name'         => 'string|html_decode|@credentials.name',
            'value'        => 'string|html_decode|@credentials.value',
            'accessMethod' => 'nullable|string|@accessMethod',
        ]);

        self::$block = [
            'name'         => $config['name'],
            'value'        => $config['value'],
            'accessMethod' => $config['accessMethod'],
        ];

        ob_start();
    }

    public static function endBlock() {
        if (!self::$block['name'] || !self::$block['value'] || !self::$block['accessMethod']) return;

        $content = ob_get_clean();

        $val = self::extractCredential(self::$block['name'], self::$block['accessMethod']);

        if ($val !== null && $val == self::$block['value'])
            echo $content;
    }

    public static function throw(int $code): void {
        if (self::getInstance()->fire('throw', $code) === false)
            Response::status($code)->header('X-Content-Type-Options', 'nosniff')->send();
    }

    public static function getRequestOrigin(): string {
        static $data = null;

        if ($data === null) {
            $origin = Request::headers('Origin');

            if (!$origin) {
                $referer = Request::headers('Referer');
                $origin = $referer ? (parse_url($referer, PHP_URL_HOST) ?? '') : '';
            }

            $data = (string)$origin;
        }

        return $data;
    }

    public static function getClientIp(): string {
        static $data = null;

        if ($data === null) {
            $forwarded = Request::headers('X-Forwarded-For');

            $data = $forwarded
                ? trim(explode(',', $forwarded)[0])
                : (string)(Request::headers('Client-Ip') ?: $_SERVER['REMOTE_ADDR'] ?? '');
        }

        return $data;
    }

    private function salt(): string {
        static $salt = null;

        if ($salt === null)
            $salt = self::config('salt');

        return (string)$salt;
    }

    private function xorStream(string $data, string $salt): string {
        $out = '';
        $len = strlen($data);

        for ($i = 0, $b = 0; $i < $len; $i += 32, $b++)
            $out .= substr($data, $i, 32) ^ hash('sha256', $salt . pack('N', $b), true);

        return $out;
    }

    private function seal(array $state): string {
        $json = (string)json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $salt = $this->salt();

        if (empty($salt)) return $json;

        $ct = $this->xorStream($json, $salt);

        return substr(hash_hmac('sha256', $ct, $salt, true), 0, 8) . $ct;
    }

    private function unseal(?string $blob): array {
        if ($blob === null) return [];

        $salt = $this->salt();

        if (empty($salt)) {
            $data = json_decode($blob, true);
            return is_array($data) ? $data : [];
        }

        if (strlen($blob) < 8) return [];

        $tag = substr($blob, 0, 8);
        $ct  = substr($blob, 8);

        if (!hash_equals($tag, substr(hash_hmac('sha256', $ct, $salt, true), 0, 8))) return [];

        $data = json_decode($this->xorStream($ct, $salt), true);

        return is_array($data) ? $data : [];
    }

    public static function handleIp(): void {
        $self = self::getInstance();

        $ip = self::getClientIp();
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return;

        static $handled = null;
        if (isset($handled)) return;
        $handled = $ip;

        if (in_array($ip, (array)self::config('firewall.exclude'), true)) return;

        $now   = time();
        $cache = $self->cache->make(['access:fw', substr(hash('sha256', $self->salt().'|'.$ip), 0, 32)]);
        $state = $self->unseal($cache->get());

        if (($state['ban'] ?? 0) > $now) {
            self::throw(429);
            return;
        }

        unset($state['ban']);

        $violated = false;
        $limitTtl = 0;
        $ttl      = 1;

        foreach ((array)self::config('firewall.limits') as $i => $limit) {
            if (!is_array($limit)) continue;

            $max    = (int)($limit[0] ?? 0);
            $window = max(1, (int)($limit[1] ?? 1));
            if ($max <= 0) continue;

            $slot = intdiv($now, $window);

            $w = $state['w'][$i] ?? null;
            if (!is_array($w) || ($w[0] ?? null) !== $slot) $w = [$slot, 0];
            $w[1]++;
            $state['w'][$i] = $w;

            if ($w[1] > $max) {
                $violated = true;
                $limitTtl = max($limitTtl, isset($limit[2]) ? max(1, (int)$limit[2]) : max(1, (int)self::config('firewall.ttl')));
            }
            $ttl = max($ttl, $window);
        }

        if ($violated) {
            $state['ban'] = $now + $limitTtl;
            $ttl          = max($ttl, $limitTtl);
        }

        if ($state)
            $cache->set($self->seal($state), $ttl);

        if ($violated) {
            $self->fire('ban', $ip);
            self::throw(429);
        }
    }

    public static function banIp(string $ip, ?int $ttl = null): void {
        $self = self::getInstance();

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false)
            throw new \InvalidArgumentException("Access::banIp(): invalid IP '{$ip}'");

        $ttl = $ttl ?: (int)self::config('firewall.ttl');

        $cache = $self->cache->make(['access:fw', substr(hash('sha256', $self->salt().'|'.$ip), 0, 32)]);
        $state = $self->unseal($cache->get());
        $state['ban'] = time() + $ttl;

        $entryTtl = $ttl;
        foreach ((array)self::config('firewall.limits') as $l)
            $entryTtl = max($entryTtl, (int)($l[1] ?? 0));

        $cache->set($self->seal($state), $entryTtl);
        $self->fire('ban', $ip);
    }

    public static function unbanIp(string $ip): void {
        $self = self::getInstance();

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false)
            throw new \InvalidArgumentException("Access::unbanIp(): invalid IP '{$ip}'");

        $cache = $self->cache->make(['access:fw', substr(hash('sha256', $self->salt().'|'.$ip), 0, 32)]);
        $state = $self->unseal($cache->get());
        if (!$state) return;

        unset($state['ban']);

        if (empty($state['w'])) {
            $cache->purge();
        } else {
            $entryTtl = 1;
            foreach ((array)self::config('firewall.limits') as $l)
                $entryTtl = max($entryTtl, (int)($l[1] ?? 0));

            $cache->set($self->seal($state), $entryTtl);
        }

        $self->fire('unban', $ip);
    }

    public static function unbanAll(): void {
        $self = self::getInstance();
        $self->cache->purgeBase();
        $self->fire('unbanAll');
    }

    public static function handleCORS(array $config = []) {
        static::applyConfig($config, [
            'allowed_origins'   => ['array', Rule::default(['*'], true), Rule::forEach('url')],
            'forbidden_origins' => 'sometimes|array|foreach:url',
            'methods'           => ['array|@CORS.methods', Rule::forEach(['required|string|strtoupper', Rule::in(self::config('CORS.methods'))])],
            'headers'           => ['array|@CORS.headers', Rule::forEach('required|string|html_decode')],
        ]);

        $request_origin = self::getRequestOrigin();

        if (!empty($request_origin) && in_array($request_origin, $config['forbidden_origins'], true))
            self::throw(403);

        $origin_header = in_array('*', $config['allowed_origins'], true)
            ? '*'
            : ((!empty($request_origin) && in_array($request_origin, $config['allowed_origins'], true))
                ? $request_origin
                : null);

        if ($origin_header === null)
            self::throw(403);

        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Origin: $origin_header");
        header("Access-Control-Allow-Headers: " . implode(", ", $config['headers']));

        if (Request::method() === 'OPTIONS') {
            header("Access-Control-Allow-Methods: " . implode(", ", $config['methods']));
            Response::status(200)->send();
        }
    }
}
