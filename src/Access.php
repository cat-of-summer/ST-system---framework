<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Traits\Events\HasStaticEvents;
use ST_system\Traits\HasInstance;
use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\Cache\CacheManager;
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
                'dir'     => Main::glue([CacheManager::config('default.dir'), Main::basename(static::class)], '/'),
                'limits'  => [[5, 1, 10], [10, 2, 30], [60, 60], [600, 3600]],
                'ttl'     => CacheManager::config('default.ttl'),
                'exclude'    => [],
                'proxies'    => [],
                'verifyBots' => true,
                'screen'     => [],
                'rules'      => [],
            ],
            'bots' => [
                ['Googlebot',   ['.googlebot.com']],
                ['yandex.com',  ['yandex.ru', 'yandex.net', 'yandex.com']],
                ['Mail.RU_Bot', ['mail.ru', 'smailru.net']],
                ['bingbot',     ['search.msn.com']],
            ],
            'handleGeo' => [
                'drivers' => [
                    'default'   => 'ipinfo',
                    'available' => [
                        'ipinfo' => \ST_system\API\Drivers\Geo\IpInfo::class,
                        'sxgeo'  => \ST_system\API\Drivers\Geo\SxGeo::class,
                        'geoip2' => \ST_system\API\Drivers\Geo\GeoIP2::class,
                        'static' => \ST_system\API\Drivers\Geo\StaticGeo::class,
                    ],
                ],
            ],
        ];
    }

    use HasStaticEvents;

    private static $block = [
        'name'         => null,
        'value'        => null,
        'accessMethod' => null,
    ];

    private CacheManager $cache;

    private function __construct() {
        $config = [
            'driver' => static::config('firewall.driver'),
            'ttl'    => static::config('firewall.ttl'),
        ];

        if ($config['driver'] === 'filesystem')
            $config['dir'] = static::config('firewall.dir');

        $this->cache = CacheManager::make(static::config('salt') ?: 'st_access', $config);
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

        $callback = (empty($val) || $val != $config['value'])
            ? $config['onFail']
            : $config['onSuccess'];

        return $callback ? $callback() : null;
    }

    public static function handleGeo(array $config = []) {
        static::applyConfig($config, [
            'token'       => ['string', Rule::default('')],
            'driver'      => ['string', Rule::default(self::config('handleGeo.drivers.default'))],
            'ip'          => ['string', Rule::default(self::getClientIp())],
            'white_list'  => ['array', Rule::default([])],
            'black_list'  => ['array', Rule::default([])],
            'onBlackList' => ['callable', Rule::default(fn() => self::throw(403))],
            'onWhiteList' => 'nullable|callable',
            'onPassed'    => 'nullable|callable',
            'onError'     => 'nullable|callable',
        ]);

        $driver = self::config('handleGeo.drivers.available.'.$config['driver']) ?: $config['driver'];

        if (!is_string($driver) || !is_subclass_of($driver, \ST_system\API\Drivers\Geo\GeoDriver::class))
            throw new \InvalidArgumentException(
                "Access::handleGeo(): geo-драйвер '{$config['driver']}' должен реализовывать ".\ST_system\API\Drivers\Geo\GeoDriver::class
            );

        $details = [];

        try {
            $details = $driver::create($config['token'])->getDetails($config['ip']);
        } catch (\Throwable $th) {
            return isset($config['onError']) 
                ? ($config['onError'])($th)
                : (isset($config['onPassed']) 
                    ? ($config['onPassed'])($details, $th) 
                    : $details
                );
        }

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

    /**
     * HTTP Basic: без подходящих логина и пароля - 401 с окном браузера.
     *   login, password - пара для сравнения (по умолчанию credentials);
     *   verify - своя проверка вместо пары: fn(?string $login, ?string $password): bool, логин и
     *            пароль - null, если браузер их не прислал (пользователь уже вошёл иначе);
     *   realm  - подпись окна.
     * Логин и пароль берутся из PHP_AUTH_USER/PHP_AUTH_PW, а под CGI/FPM - из заголовка
     * Authorization (HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION).
     */
    public static function httpAccess(array $config = []) {
        static::applyConfig($config, [
            'login'    => 'string|html_decode|@credentials.name',
            'password' => 'string|html_decode|@credentials.value',
            'verify'   => 'nullable|callable',
            'realm'    => 'nullable|string',
        ]);

        [$login, $password] = self::basicCredentials();

        $passed = isset($config['verify'])
            ? (bool)($config['verify'])($login, $password)
            : $login !== null
                && hash_equals((string)$config['login'], $login)
                && hash_equals((string)$config['password'], (string)$password);

        if (!$passed) {
            $realm = str_replace('"', '', (string)($config['realm'] ?? '')) ?: 'Restricted Area';

            Response::status(401)->header('WWW-Authenticate', 'Basic realm="'.$realm.'", charset="UTF-8"')->send();
        }
    }

    /** [логин, пароль] из Basic-авторизации запроса; [null, null] - её нет. */
    public static function basicCredentials(): array {
        if (isset($_SERVER['PHP_AUTH_USER']))
            return [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')];

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = trim((string)($_SERVER[$key] ?? ''));
            if (stripos($header, 'Basic ') !== 0) continue;

            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) return explode(':', $decoded, 2);
        }

        return [null, null];
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
        if (self::fire('throw', $code) === false)
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
            $remote  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $proxies = (array)self::config('firewall.proxies');

            if (!empty($proxies)) {
                $ip = $remote;
                foreach ($proxies as $cidr => $serverKey) {
                    if ($remote === (string)$cidr || self::ipInCidr($remote, (string)$cidr)) {
                        $candidate = trim((string)($_SERVER[$serverKey] ?? ''));
                        if ($candidate !== '')
                            $ip = self::lastUntrusted(array_map('trim', explode(',', $candidate)), $proxies);
                        break;
                    }
                }
            } else {
                $forwarded = Request::headers('X-Forwarded-For');
                $ip = $forwarded
                    ? trim(explode(',', $forwarded)[0])
                    : (string)(Request::headers('Client-Ip') ?: $remote);
            }

            $data = self::normalizeIp($ip);
        }

        return $data;
    }

    /**
     * Адрес посетителя в цепочке прокси: справа налево первый не из доверенных. Прокси дописывают
     * адреса справа, а левые элементы X-Forwarded-For присылает сам клиент - первым из них мог бы
     * представиться кто угодно.
     */
    private static function lastUntrusted(array $chain, array $proxies): string {
        $chain = array_values(array_filter($chain, static fn($ip) => $ip !== ''));

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $trusted = false;
            foreach (array_keys($proxies) as $cidr)
                if (self::ipMatches($chain[$i], (string)$cidr)) $trusted = true;

            if (!$trusted) return $chain[$i];
        }

        return $chain[0] ?? '';
    }

    private static function normalizeIp(string $ip): string {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false)
                return implode(':', str_split(bin2hex($packed), 4));
        }

        return $ip;
    }

    private static function ipInCidr(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) return false;

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int)$bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) return false;
        if (strlen($ipBin) !== strlen($subnetBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) return false;

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;
        if ($rem === 0) return true;

        $mask = chr((0xff << (8 - $rem)) & 0xff);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }

    /** Шаблон IP настроек фаервола: адрес, подсеть (10.0.0.0/8) или начало адреса с точкой (192.168.). */
    public static function isIpPattern(string $pattern): bool {
        if (filter_var($pattern, FILTER_VALIDATE_IP)) return true;
        if (substr($pattern, -1) === '.') return (bool)preg_match('/^[0-9a-f:.]+\.$/i', $pattern);
        if (strpos($pattern, '/') === false) return false;

        [$ip, $bits] = explode('/', $pattern, 2);

        return (bool)filter_var($ip, FILTER_VALIDATE_IP) && ctype_digit($bits)
            && (int)$bits <= (strpos($ip, ':') !== false ? 128 : 32);
    }

    /** IP подходит под шаблон: адрес, подсеть или начало адреса с точкой. */
    public static function ipMatches(string $ip, string $pattern): bool {
        if ($pattern === '') return false;
        if (strpos($pattern, '/') !== false) return self::ipInCidr($ip, $pattern);
        if (substr($pattern, -1) === '.') return strncmp($ip, $pattern, strlen($pattern)) === 0;

        return self::normalizeIp($ip) === self::normalizeIp($pattern);
    }

    private static function salt(): string {
        static $salt = null;

        if ($salt === null)
            $salt = self::config('salt');

        return (string)$salt;
    }

    public static function xorStream(string $data, string $salt): string {
        $out = '';
        $len = strlen($data);

        for ($i = 0, $b = 0; $i < $len; $i += 32, $b++)
            $out .= substr($data, $i, 32) ^ hash('sha256', $salt . pack('N', $b), true);

        return $out;
    }

    public static function seal(array $state, string $salt = ''): string {
        $json = (string)json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $salt = $salt !== '' ? $salt : self::salt();

        if (empty($salt)) return $json;

        $ct = self::xorStream($json, $salt);

        return substr(hash_hmac('sha256', $ct, $salt, true), 0, 8) . $ct;
    }

    public static function unseal(?string $blob, string $salt = ''): array {
        if ($blob === null) return [];

        $salt = $salt !== '' ? $salt : self::salt();

        if (empty($salt)) {
            $data = json_decode($blob, true);
            return is_array($data) ? $data : [];
        }

        if (strlen($blob) < 8) return [];

        $tag = substr($blob, 0, 8);
        $ct  = substr($blob, 8);

        if (!hash_equals($tag, substr(hash_hmac('sha256', $ct, $salt, true), 0, 8))) return [];

        $data = json_decode(self::xorStream($ct, $salt), true);

        return is_array($data) ? $data : [];
    }

    public static function handleIp(): void {
        $self = self::getInstance();

        $ip = self::getClientIp();
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return;

        static $handled = null;
        if (isset($handled)) return;
        $handled = $ip;

        foreach ((array)self::config('firewall.exclude') as $ex)
            if (self::ipMatches($ip, (string)$ex)) return;

        if (self::config('firewall.verifyBots') && self::verifyBot()) return;

        $screen = (array)self::config('firewall.screen');
        if ($screen) {
            $reason = null;
            if (!empty($screen['noUserAgent']) && (string)Request::headers('User-Agent') === '') $reason = 'no_user_agent';
            elseif (!empty($screen['noReferer']) && self::getRequestOrigin() === '')              $reason = 'no_referer';
            elseif (!empty($screen['noLang'])) {
                $al = (string)Request::headers('Accept-Language');
                if ($al === '' || $al === '*') $reason = 'no_lang';
            }
            if ($reason === null && !empty($screen['http2Only']) && (($_SERVER['SERVER_PROTOCOL'] ?? '') !== 'HTTP/2.0'))
                $reason = 'no_http2';

            if ($reason !== null) {
                self::fire('screen', $reason, $ip);
                self::throw(403);
                return;
            }
        }

        $verdict = self::matchRules($ip);
        if ($verdict === 'allow') return;
        if ($verdict === 'deny') {
            self::fire('deny', $ip);
            self::throw(403);
            return;
        }

        $now   = time();
        $cache = $self->cache->make(['access:fw', substr(hash('sha256', self::salt().'|'.$ip), 0, 32)]);
        $state = self::unseal($cache->get());

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
            $cache->set(self::seal($state), $ttl);

        if ($violated) {
            $until = $state['ban'];
            self::fire('ban', $ip, $until);
            self::throw(429);
        }
    }

    public static function banIp(string $ip, ?int $ttl = null): void {
        $self = self::getInstance();

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false)
            throw new \InvalidArgumentException("Access::banIp(): invalid IP '{$ip}'");

        $ttl = $ttl ?: (int)self::config('firewall.ttl');

        $cache = $self->cache->make(['access:fw', substr(hash('sha256', self::salt().'|'.$ip), 0, 32)]);
        $state = self::unseal($cache->get());
        $state['ban'] = time() + $ttl;

        $entryTtl = $ttl;
        foreach ((array)self::config('firewall.limits') as $l)
            $entryTtl = max($entryTtl, (int)($l[1] ?? 0));

        $cache->set(self::seal($state), $entryTtl);
        $until = $state['ban'];
        self::fire('ban', $ip, $until);
    }

    /** Когда истекает бан IP (unix-время); 0 - IP не забанен. */
    public static function banUntil(string $ip): int {
        $self = self::getInstance();

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return 0;

        $state = self::unseal($self->cache->make(['access:fw', substr(hash('sha256', self::salt().'|'.$ip), 0, 32)])->get());
        $until = (int)($state['ban'] ?? 0);

        return $until > time() ? $until : 0;
    }

    public static function unbanIp(string $ip): void {
        $self = self::getInstance();

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false)
            throw new \InvalidArgumentException("Access::unbanIp(): invalid IP '{$ip}'");

        $cache = $self->cache->make(['access:fw', substr(hash('sha256', self::salt().'|'.$ip), 0, 32)]);
        $state = self::unseal($cache->get());
        if (!$state) return;

        unset($state['ban']);

        if (empty($state['w'])) {
            $cache->purge();
        } else {
            $entryTtl = 1;
            foreach ((array)self::config('firewall.limits') as $l)
                $entryTtl = max($entryTtl, (int)($l[1] ?? 0));

            $cache->set(self::seal($state), $entryTtl);
        }

        self::fire('unban', $ip);
    }

    public static function unbanAll(): void {
        self::getInstance()->cache->purgeBase();
        self::fire('unbanAll');
    }

    public static function verifyBot(?array $signatures = null): bool {
        $ua = (string)Request::headers('User-Agent');
        if ($ua === '') return false;

        $ip = self::getClientIp();
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return false;

        $ptrOk = null;
        foreach (self::botSignatures($signatures ?? (array)self::config('bots')) as [$needle, $suffixes])
            if (stripos($ua, $needle) !== false) { $ptrOk = $suffixes; break; }

        if ($ptrOk === null) return false;

        $self   = self::getInstance();
        $cache  = $self->cache->make(['access:bot', substr(hash('sha256', self::salt().'|'.$ip.'|'.$ua), 0, 32)]);
        $cached = self::unseal($cache->get());

        if (isset($cached['v'])) {
            $verified = (bool)$cached['v'];
        } elseif (in_array('.', $ptrOk, true)) {
            $verified = true;
        } else {
            $verified = false;
            $ptr = @gethostbyaddr($ip);

            if ($ptr !== false && $ptr !== $ip) {
                $hit = false;
                foreach ($ptrOk as $suffix)
                    if ($suffix !== '' && stripos($ptr, $suffix) !== false) { $hit = true; break; }

                if ($hit) {
                    $ipNorm = self::normalizeIp($ip);
                    foreach ((array)@dns_get_record($ptr, DNS_A + DNS_AAAA) as $r)
                        if ((isset($r['ip'])   && self::normalizeIp($r['ip'])   === $ipNorm)
                         || (isset($r['ipv6']) && self::normalizeIp($r['ipv6']) === $ipNorm)) {
                            $verified = true;
                            break;
                        }
                }
            }
        }

        if (!isset($cached['v']))
            $cache->set(self::seal(['v' => $verified ? 1 : 0]), 3600);

        if ($verified) self::fire('verifiedBot', $ip, $ua);

        return $verified;
    }

    private static function botSignatures(array $config, string $prefix = ''): array {
        $list = [];

        foreach ($config as $key => $value) {
            if (is_int($key) && $prefix === '' && is_array($value) && isset($value[0]) && is_string($value[0])) {
                $list[] = [$value[0], array_values(array_filter((array)($value[1] ?? []), 'is_string'))];
                continue;
            }

            $needle = $prefix === '' ? (string)$key : $prefix.'.'.$key;

            if (is_array($value) && $value && !Main::arrayIsList($value))
                $list = array_merge($list, self::botSignatures($value, $needle));
            elseif ($needle !== '')
                $list[] = [$needle, array_values(array_filter((array)$value, 'is_string'))];
        }

        return $prefix === '' ? array_values(array_filter($list, static fn($s) => $s[0] !== '')) : $list;
    }

    private static function matchRules(string $ip): ?string {
        $rules = (array)self::config('firewall.rules');
        if (!$rules) return null;

        $ctx = [
            'ip'      => $ip,
            'cidr'    => $ip,
            'ua'      => (string)Request::headers('User-Agent'),
            'referer' => self::getRequestOrigin(),
            'lang'    => strtolower(substr(preg_replace('/[^a-zA-Z]/', '', (string)Request::headers('Accept-Language')), 0, 2)),
            'country' => isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', $_SERVER['HTTP_CF_IPCOUNTRY'])) : '',
            'ptr'     => null,
        ];

        foreach (['allow', 'deny'] as $action) {
            foreach ($rules as $rule) {
                $rule = array_values((array)$rule);
                if (count($rule) < 3 || strtolower((string)$rule[0]) !== $action) continue;

                $type    = strtolower((string)$rule[1]);
                $pattern = (string)$rule[2];
                if ($pattern === '') continue;

                if ($ctx['ptr'] === null && $type === 'ptr') {
                    $p = @gethostbyaddr($ip);
                    $ctx['ptr'] = ($p && $p !== $ip) ? strtolower($p) : '';
                }

                $hit = ($type === 'ip' || $type === 'cidr')
                    ? self::ipMatches($ip, $pattern)
                    : (in_array($type, ['country', 'lang'], true)
                        ? (($ctx[$type] ?? '') !== '' && strcasecmp((string)$ctx[$type], $pattern) === 0)
                        : (in_array($type, ['referer', 'ua', 'ptr'], true) && ($ctx[$type] ?? '') !== '' && stripos((string)$ctx[$type], $pattern) !== false));

                if ($hit) return $action;
            }
        }

        return null;
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
