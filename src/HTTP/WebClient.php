<?php

namespace ST_system\HTTP;

use ST_system\Main;
use ST_system\Rule;
use ST_system\Cache\Manager as Cache;
use ST_system\Traits\HasEvents;
use ST_system\Traits\HasConfig;

/**
 * Единый HTTP-механизм поверх curl_multi.
 *
 * Один объект = один URL: простой ("https://api/status") или шаблонизированный ("{url}",
 * "http://t.ru/{p}/get"). Шаблон раскрывается через ->query([...]) в декартово произведение
 * комбинаций — пачка из 1..n запросов, отправляемых окнами по config('batch') с паузой
 * config('delay') мс между окнами.
 *
 *   WebClient::create('{url}', ['batch' => 10])->query(['url' => ['a.com', 'b.com']])->send();
 *   while ($r = $client->next()) { ... }   // результаты по одному, по мере готовности
 *
 * Тело запроса: ->schema([...]) задаёт валидацию (Rule::object, throwable), ->fill([...])
 * заполняет — одно тело на все запросы пачки. Значения-файлы (\CURLFile или '@/путь')
 * переключают отправку на multipart/form-data.
 *
 * Результат — массив: ['status','headers','body','data','type','url','effective_url',
 * 'errno','error','aborted','cached','info']. Заголовки — assoc, ключи в lowercase.
 *
 * verify (default false) задаёт и SSL-проверки curl, и схему URL: без схемы подставляется
 * https:// (verify=true) или http:// (verify=false); http:// при verify=true — исключение;
 * https:// при verify=false валидно, но SSL-проверки не включаются.
 *
 * События (HasEvents): 'prepare'(&$spec) — до ключа кеша и настройки хендла;
 * 'prepare_response'($spec,&$result); 'decode_response'($spec,&$result) — нет слушателей
 * или никто не заполнил data -> дефолтный разбор по type; 'error'($spec,&$result) — нет
 * слушателей + config('exception') -> исключение; 'response'($spec,&$result) — всегда
 * последним, единственное событие для кеш-хитов. Все события per-request, к пачкам не
 * привязаны. ->parse(['xml' => fn($body, $result) => ...]) — сахар над decode_response
 * c фильтром по типу ответа.
 *
 * Кеш (Cache\Manager, только GET/HEAD, выключен по умолчанию): ключ = чистый URL + все
 * параметры (вытащенные из query string + fill). Свежий кеш отдаётся без запроса;
 * протухший ревалидируется через If-None-Match/If-Modified-Since с обрывом тела на 304
 * и продлением TTL до max(конфиг, max-age/Expires сервера).
 */
final class WebClient {

    use HasEvents;
    use HasConfig;

    protected static function getReservedEvents(): array {
        return ['prepare', 'prepare_response', 'decode_response', 'error', 'response'];
    }

    protected static function getDefaultConfig(): array {
        return [
            'timeout'          => 30.0,
            'connect_timeout'  => 10.0,
            'follow_redirects' => true,
            'max_redirects'    => 10,
            'verify'           => false,
            'headers'          => [],
            'response_type'    => '',      // '' = автоопределение по Content-Type
            'batch'            => 10,      // размер окна параллельных запросов
            'delay'            => 0,       // пауза между пачками, мс
            'method'           => 'get',
            'exception'        => true,    // бросать ли исключение на необработанную ошибку
            'cache' => [
                'use'    => false,
                'ttl'    => 3600,
                'dir'    => '',
                'driver' => 'filesystem',
            ],
            'mime_aliases' => [
                'json' => ['application/json'],
                'xml'  => ['text/xml', 'application/xml'],
                'html' => ['text/html'],
                'text' => ['text/plain'],
                'raw'  => [],
            ],
        ];
    }

    private string $template;             // исходная строка URL (может содержать {param})
    private array  $config;               // конфиг экземпляра (после applyConfig)
    private array  $placeholders = [];    // имена {param} шаблона (уникальные)
    private ?Rule  $queryRule    = null;  // валидация параметров query() (для шаблона)
    private array  $queryParams  = [];    // name => list<string>
    private ?Rule  $bodyRule     = null;  // валидация fill() (задаётся schema())
    private ?array $body         = null;  // заполненное тело (null = не заполнено)
    private bool   $multipart    = false; // в теле есть файлы -> multipart/form-data
    private ?\Generator $pipeline = null; // общий генератор результатов для next()/send()
    private bool   $drained      = false; // пайплайн исчерпан
    private ?Cache $cacheBase    = null;

    public function __construct(string $url, array $config = []) {
        static::applyConfig($config, [
            'timeout'          => 'float|@timeout',
            'connect_timeout'  => 'float|@connect_timeout',
            'follow_redirects' => 'bool|@follow_redirects',
            'max_redirects'    => 'int|min:0|@max_redirects',
            'verify'           => 'bool|@verify',
            'headers'          => 'array|@headers',
            'response_type'    => ['string|lowercase|@response_type', Rule::in(['', 'json', 'xml', 'html', 'text', 'raw'])],
            'batch'            => 'int|min:1|@batch',
            'delay'            => 'int|min:0|@delay',
            'method'           => ['string|lowercase|@method', Rule::in(['get', 'post', 'put', 'patch', 'delete', 'head', 'options'])],
            'exception'        => 'bool|@exception',
            'cache.use'        => 'bool|@cache.use',
            'cache.ttl'        => 'int|@cache.ttl',
            'cache.dir'        => 'string|@cache.dir',
            'cache.driver'     => 'string|@cache.driver',
            'mime_aliases'     => 'array|@mime_aliases',
        ]);
        $this->config   = $config;
        $this->template = trim($url);

        if (preg_match_all('/\{(\w+)\}/', $this->template, $m)) {
            $this->placeholders = array_values(array_unique($m[1]));

            $schema = [];
            foreach ($this->placeholders as $name)
                $schema[$name] = ['sometimes', Rule::anyOf(
                    'required|string',
                    ['array', 'min:1', Rule::forEach('required|string')]
                )->handleError(fn($v) => "Параметр '{$name}' должен быть непустой строкой или массивом непустых строк")];

            $this->queryRule = Rule::object($schema)->throwable();
        } else {
            $this->template = $this->applyScheme($this->template); // ранняя проверка verify-политики
        }
    }

    public static function create(string $url, array $config = []): self {
        return new self($url, $config);
    }

    // --- наполнение запроса ---

    /** Параметры шаблона: строка или массив строк; массивы декартово размножают запросы. */
    public function query(array $params): self {
        if (!$this->placeholders)
            throw new \LogicException('WebClient: URL не шаблонизирован — query() не применим');

        if ($unknown = array_diff(array_keys($params), $this->placeholders))
            throw new \InvalidArgumentException("WebClient: неизвестные параметры шаблона: '".implode("', '", $unknown)."'");

        $this->queryRule->apply($params);

        foreach ($params as $k => $v)
            $this->queryParams[$k] = array_values((array)$v);

        $this->resetPipeline();
        return $this;
    }

    /** Схема тела запроса; смена схемы обнуляет заполненные параметры. */
    public function schema(array $schema): self {
        $this->bodyRule  = Rule::object($schema)->throwable();
        $this->body      = null;
        $this->multipart = false;
        $this->resetPipeline();
        return $this;
    }

    /** Одно тело на все запросы пачки; \CURLFile или строки '@/путь' включают multipart. */
    public function fill(array $params): self {
        if ($this->bodyRule !== null)
            $this->bodyRule->apply($params);

        $this->multipart = self::detectFiles($params);
        $this->body      = $params;
        $this->resetPipeline();
        return $this;
    }

    /** Обработчики decode_response по типам ответа: ['xml' => fn($body, $result) => ...]. */
    public function parse(array $map): self {
        $allowed = array_keys((array)$this->config['mime_aliases']);

        foreach ($map as $type => $fn) {
            if (!in_array($type, $allowed, true))
                throw new \InvalidArgumentException("WebClient: неизвестный тип ответа '{$type}'");
            if (!is_callable($fn))
                throw new \InvalidArgumentException("WebClient: обработчик для '{$type}' должен быть callable");

            $this->on('decode_response', function($spec, &$result) use ($type, $fn) {
                if (($result['type'] ?? null) === $type)
                    $result['data'] = $fn($result['body'], $result);
            });
        }
        return $this;
    }

    // --- отправка ---

    /** Один готовый результат за вызов; null после исчерпания (до изменения запроса). */
    public function next(): ?array {
        if ($this->drained) return null;
        if ($this->pipeline === null) $this->pipeline = $this->run();

        try {
            if (!$this->pipeline->valid()) {
                $this->pipeline = null;
                $this->drained  = true;
                return null;
            }
            $value = $this->pipeline->current();
            $this->pipeline->next();
            return $value;
        } catch (\Throwable $th) {
            $this->resetPipeline(); // мёртвый генератор; повтор стартует с чистого листа
            throw $th;
        }
    }

    /** Дренирует общий с next() генератор; после исчерпания повторный send() перезапускает пачку. */
    public function send(): array {
        $this->drained = false;

        $results = [];
        while (($r = $this->next()) !== null)
            $results[] = $r;

        return $results;
    }

    private function resetPipeline(): void {
        $this->pipeline = null;
        $this->drained  = false;
    }

    /** Проверка полноты + запуск конвейера (проверки выполняются сразу, не лениво). */
    private function run(): \Generator {
        if ($this->placeholders) {
            $missing = array_diff($this->placeholders, array_keys($this->queryParams));
            if ($missing)
                throw new \LogicException("WebClient: не заполнены параметры шаблона: '".implode("', '", $missing)."'");
        }

        if ($this->multipart) {
            $method = strtoupper((string)$this->config['method']);
            if ($method === 'GET' || $method === 'HEAD')
                throw new \LogicException("WebClient: отправка файлов методом {$method} невозможна");
        }

        return $this->dispatch($this->specs());
    }

    // --- URL-слой ---

    /** Схема URL по verify-политике (см. док класса). */
    private function applyScheme(string $url): string {
        $verify = (bool)$this->config['verify'];

        if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $url, $m)) {
            if (strtolower($m[1]) === 'http' && $verify)
                throw new \InvalidArgumentException("WebClient: verify=true несовместим с http:// в URL '{$url}'");
            return $url;
        }

        return ($verify ? 'https://' : 'http://').ltrim($url, '/');
    }

    /** Отделяет query string от чистого URL. */
    private static function splitUrl(string $url): array {
        $pos = strpos($url, '?');
        if ($pos === false) return [$url, []];

        parse_str(substr($url, $pos + 1), $get);
        return [substr($url, 0, $pos), $get];
    }

    private function substitute(array $combo): string {
        $url = $this->template;
        foreach ($combo as $k => $v)
            $url = str_replace('{'.$k.'}', (string)$v, $url);
        return $url;
    }

    /** Декартово произведение query-параметров через «одометр» — лениво, без материализации. */
    private function combos(): \Generator {
        $keys   = array_keys($this->queryParams);
        $values = array_values($this->queryParams);

        $n = count($keys);
        if ($n === 0) { yield []; return; }

        $idx = array_fill(0, $n, 0);
        while (true) {
            $combo = [];
            for ($i = 0; $i < $n; $i++) $combo[$keys[$i]] = $values[$i][$idx[$i]];
            yield $combo;

            $p = $n - 1;
            while ($p >= 0) { if (++$idx[$p] < count($values[$p])) break; $idx[$p] = 0; $p--; }
            if ($p < 0) break;
        }
    }

    private function specs(): \Generator {
        foreach ($this->combos() as $combo)
            yield $this->buildSpec($combo);
    }

    /** Спека одного запроса; query string вытаскивается из URL прямо перед отправкой. */
    private function buildSpec(array $combo): array {
        $url = $this->placeholders ? $this->applyScheme($this->substitute($combo)) : $this->template;

        [$clean, $get] = self::splitUrl($url);

        $method = strtoupper((string)$this->config['method']);
        $isRead = $method === 'GET' || $method === 'HEAD';
        $params = array_replace($get, (array)$this->body); // материал для ключа кеша

        return [
            'url'           => $clean,
            'get'           => $isRead ? $params : $get,
            'method'        => $method,
            'headers'       => (array)$this->config['headers'],
            'body'          => $isRead ? null : $this->body,
            'multipart'     => $this->multipart,
            'response_type' => (string)$this->config['response_type'],
            'params'        => $params,
        ];
    }

    // --- конвейер отправки ---

    /**
     * Чанкует спеки по batch и гонит через curl_multi, отдавая каждый результат по мере
     * готовности. Свежий curl_init() на запрос, curl_close() сразу после сборки результата;
     * finally гарантирует освобождение хендлов при исключении и при разрушении генератора.
     */
    private function dispatch(\Generator $specs): \Generator {
        $batch = (int)$this->config['batch'];
        $delay = (int)$this->config['delay'];

        $mh     = curl_multi_init();
        $active = []; // hid => ['ch', 'spec', 'state', 'cache']

        try {
            $needDelay = false;
            while ($specs->valid()) {
                if ($needDelay && $delay > 0)
                    usleep($delay * 1000); // пауза между пачками, не перед первой

                // наполнение пачки (кеш-хиты не занимают слоты)
                for ($n = 0; $n < $batch && $specs->valid(); $specs->next()) {
                    $spec = $specs->current();
                    $this->fire('prepare', $spec); // до ключа кеша — мутации spec влияют на него

                    $cache = $this->cacheFor($spec);
                    if ($cache !== null) {
                        if ($this->isFresh($cache) && ($hit = $this->cacheRead($cache)) !== null) {
                            $hit['cached'] = true;
                            $this->fire('response', $spec, $hit);
                            yield $hit;
                            continue;
                        }
                        if ($cache->exists())
                            $this->applyRevalidation($spec, $cache);
                    }

                    $ch    = curl_init();
                    $state = new \stdClass();
                    $this->configureHandle($ch, $spec, $state);
                    curl_multi_add_handle($mh, $ch);
                    $active[self::hid($ch)] = ['ch' => $ch, 'spec' => $spec, 'state' => $state, 'cache' => $cache];
                    $n++;
                }
                $needDelay = count($active) > 0;

                // дренаж пачки: yield по мере завершения хендлов
                while ($active) {
                    do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);

                    while ($info = curl_multi_info_read($mh)) {
                        $ch  = $info['handle'];
                        $hid = self::hid($ch);
                        $entry = $active[$hid];
                        unset($active[$hid]);

                        $content = curl_multi_getcontent($ch);
                        curl_multi_remove_handle($mh, $ch);
                        $result = self::buildResult($ch, $entry['state'], is_string($content) ? $content : null, $entry['spec']);
                        curl_close($ch);

                        $this->finalize($entry['spec'], $result, $entry['cache']);
                        yield $result;
                    }

                    if ($active && curl_multi_select($mh, 1.0) === -1)
                        usleep(1000);
                }
            }
        } finally {
            foreach ($active as $entry) {
                curl_multi_remove_handle($mh, $entry['ch']);
                curl_close($entry['ch']);
            }
            curl_multi_close($mh);
        }
    }

    // --- настройка curl / сборка результата ---

    /**
     * $state (stdClass) делится ПО ССЫЛКЕ между header-колбэком и сборкой результата —
     * для curl_multi состояние живёт до завершения хендла, массив потерял бы мутации.
     */
    private function configureHandle($ch, array $spec, \stdClass $state): void {
        $state->headers = [];
        $state->status  = 0;
        $state->aborted = false;
        $state->url     = (string)$spec['url'];

        $follow    = (bool)$this->config['follow_redirects'];
        $verify    = (bool)$this->config['verify'];
        $onHeaders = $spec['on_headers'] ?? null;

        $url = $spec['url'].($spec['get'] ? '?'.http_build_query($spec['get']) : '');

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => (int)$this->config['max_redirects'],
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];

        $timeout  = (float)$this->config['timeout'];
        $ctimeout = (float)$this->config['connect_timeout'];
        if ($timeout > 0)  $opts[CURLOPT_TIMEOUT_MS]        = (int)round($timeout * 1000);
        if ($ctimeout > 0) $opts[CURLOPT_CONNECTTIMEOUT_MS] = (int)round($ctimeout * 1000);

        $method = $spec['method'];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($spec['body'] !== null) $opts[CURLOPT_POSTFIELDS] = self::encodeBody($spec);
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($spec['body'] !== null) $opts[CURLOPT_POSTFIELDS] = self::encodeBody($spec);
        }

        if ($list = self::normalizeRequestHeaders($spec['headers']))
            $opts[CURLOPT_HTTPHEADER] = $list;

        $opts[CURLOPT_HEADERFUNCTION] = static function($c, $line) use ($state, $onHeaders, $follow) {
            $len  = strlen($line);
            $trim = trim($line);

            if ($trim === '') {
                $status     = $state->status;
                $isRedirect = $follow && $status >= 300 && $status < 400 && isset($state->headers['location']);

                if (!$isRedirect && $onHeaders !== null && $onHeaders($state->headers, ['http_code' => $status]) === true) {
                    $state->aborted = true;
                    return 0; // != $len => curl обрывает передачу ДО тела
                }
                return $len;
            }

            if (stripos($trim, 'http/') === 0) {
                $state->headers = []; // редиректы не смешивают заголовки
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trim, $m)) $state->status = (int)$m[1];
                return $len;
            }

            $p = strpos($trim, ':');
            if ($p !== false)
                $state->headers[strtolower(trim(substr($trim, 0, $p)))] = trim(substr($trim, $p + 1));

            return $len;
        };

        curl_setopt_array($ch, $opts);
    }

    private static function buildResult($ch, \stdClass $state, ?string $content, array $spec): array {
        $errno   = curl_errno($ch);
        $info    = curl_getinfo($ch);
        $aborted = $state->aborted;

        return [
            'status'        => (int)($info['http_code'] ?? $state->status),
            'headers'       => $state->headers,
            'body'          => ($aborted || $spec['method'] === 'HEAD') ? null : $content,
            'url'           => (string)$spec['url'],
            'effective_url' => $info['url'] ?? $state->url,
            'errno'         => $aborted ? 0 : $errno, // обрыв тела на 304 — не ошибка
            'error'         => ($errno !== 0 && !$aborted) ? curl_error($ch) : '',
            'aborted'       => $aborted,
            'cached'        => false,
            'info'          => $info,
        ];
    }

    /** map|list заголовков -> список "Key: Value" для CURLOPT_HTTPHEADER. */
    private static function normalizeRequestHeaders(array $headers): array {
        $out = [];
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                if (is_string($v) && strpos($v, ':') !== false) $out[] = $v;
                continue;
            }
            $key   = str_replace(' ', '-', ucwords(strtolower(str_replace(['_', '-'], ' ', $k))));
            $out[] = $key.': '.$v;
        }
        return $out;
    }

    /** Тело для CURLOPT_POSTFIELDS: multipart-массив, json или urlencoded-строка. */
    private static function encodeBody(array $spec) {
        $body = (array)$spec['body'];

        if (!empty($spec['multipart']))
            return self::flattenMultipart($body); // массив -> curl сам строит multipart/form-data

        foreach (self::normalizeRequestHeaders($spec['headers']) as $line)
            if (stripos($line, 'content-type:') === 0 && stripos($line, 'application/json') !== false)
                return json_encode($body);

        return http_build_query($body);
    }

    private static function detectFiles(array &$params): bool {
        $found = false;
        foreach ($params as &$v) {
            if ($v instanceof \CURLFile) {
                $found = true;
            } elseif (is_string($v) && strlen($v) > 1 && $v[0] === '@' && is_file(substr($v, 1))) {
                $v = new \CURLFile(substr($v, 1));
                $found = true;
            } elseif (is_array($v)) {
                $found = self::detectFiles($v) || $found;
            }
        }
        unset($v);
        return $found;
    }

    /** Вложенные массивы -> bracket-ключи: curl не принимает вложенность в POSTFIELDS-массиве. */
    private static function flattenMultipart(array $params, string $prefix = ''): array {
        $out = [];
        foreach ($params as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix.'['.$k.']';
            if (is_array($v)) $out += self::flattenMultipart($v, $key);
            else              $out[$key] = $v;
        }
        return $out;
    }

    private static function hid($ch): int {
        return is_object($ch) ? spl_object_id($ch) : (int)$ch;
    }

    // --- события / декодирование / ошибки / запись кеша ---

    private function finalize(array $spec, array &$result, ?Cache $cache): void {
        // тело не качали (304/валидаторы) — отдаём сохранённый ответ, продлив ему жизнь
        if (($result['aborted'] || $result['status'] === 304) && $cache !== null
                && ($hit = $this->cacheRead($cache)) !== null) {
            $this->touchCache($cache, $result);
            $hit['cached'] = true;
            $this->fire('response', $spec, $hit);
            $result = $hit;
            return;
        }

        $this->fire('prepare_response', $spec, $result);

        $result['type'] = $this->resolveType($spec, $result);
        $handled = $this->fire('decode_response', $spec, $result);
        if ($handled === false || !array_key_exists('data', $result))
            $this->decodeDefault($result);

        if ($result['errno'] !== 0 || $result['status'] >= 400) {
            if ($this->fire('error', $spec, $result) === false && $this->config['exception'])
                throw new \RuntimeException(
                    "WebClient: запрос '{$result['url']}' завершился ошибкой: "
                    .($result['errno'] ? "curl #{$result['errno']} {$result['error']}" : "HTTP {$result['status']}")
                );
            // слушатели были — считаем ошибку обработанной, результат идёт дальше
        }

        $this->fire('response', $spec, $result);

        if ($cache !== null && !$result['aborted'] && $result['errno'] === 0
                && $result['status'] >= 200 && $result['status'] < 300)
            $cache->set(json_encode($result), -1, '', [
                'etag'          => $result['headers']['etag'] ?? '',
                'last-modified' => $result['headers']['last-modified'] ?? '',
                'fresh_until'   => time() + $this->resolveTtl($result['headers']),
            ]);
    }

    /** Тип ответа: явный response_type или Content-Type через mime_aliases; fallback raw. */
    private function resolveType(array $spec, array $result): string {
        if ($spec['response_type'] !== '') return $spec['response_type'];

        $ct = strtolower(trim(explode(';', (string)($result['headers']['content-type'] ?? ''))[0]));
        if ($ct !== '')
            foreach ((array)$this->config['mime_aliases'] as $alias => $mimes)
                foreach ((array)$mimes as $mime)
                    if ($mime !== '' && strpos($ct, $mime) === 0)
                        return (string)$alias;

        return 'raw';
    }

    /** Дефолтный разбор тела: ошибки декодирования деградируют в data=null, без исключений. */
    private function decodeDefault(array &$result): void {
        if (!is_string($result['body']) || $result['body'] === '') {
            $result['data'] = null;
            return;
        }

        switch ($result['type']) {
            case 'json':
                $data = json_decode($result['body'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $result['data']  = null;
                    $result['error'] = 'Ошибка декодирования JSON: '.json_last_error_msg();
                } else {
                    $result['data'] = $data;
                }
                break;

            case 'xml':
                try {
                    $result['data'] = self::xmlToArray($result['body']);
                } catch (\Throwable $th) {
                    $result['data']  = null;
                    $result['error'] = $th->getMessage();
                }
                break;

            default: // html | text | raw
                $result['data'] = $result['body'];
                break;
        }
    }

    // --- кеш ---

    private function cacheFor(array $spec): ?Cache {
        if (!Main::dotGet($this->config, 'cache.use')) return null;
        if ($spec['method'] !== 'GET' && $spec['method'] !== 'HEAD') return null;

        $this->cacheBase = $this->cacheBase ?? Cache::make('webclient', [
            'driver' => (string)Main::dotGet($this->config, 'cache.driver'),
            'dir'    => (string)Main::dotGet($this->config, 'cache.dir'),
            'ttl'    => (int)Main::dotGet($this->config, 'cache.ttl'),
        ]);

        return $this->cacheBase->make([$spec['method'], $spec['url'], $spec['params']]);
    }

    /**
     * Свежесть по нашей мете 'fresh_until', а не по expires_in драйвера: blob хранится
     * с ttl -1 (всегда читаем через get()), чтобы протухшую запись можно было
     * ревалидировать и отдать её тело при 304.
     */
    private function isFresh(Cache $cache): bool {
        if (!$cache->exists()) return false;
        $meta = $cache->getMeta();
        if (!array_key_exists('fresh_until', $meta)) return false;
        return $meta['fresh_until'] === -1 || $meta['fresh_until'] > time();
    }

    private function cacheRead(Cache $cache): ?array {
        $raw = $cache->get();
        if (!is_string($raw)) return null;

        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : null;
    }

    /** Условные заголовки + вердикт-обрыв тела на основе сохранённых валидаторов. */
    private function applyRevalidation(array &$spec, Cache $cache): void {
        $meta = $cache->getMeta();
        $etag = (string)($meta['etag'] ?? '');
        $last = (string)($meta['last-modified'] ?? '');
        if ($etag === '' && $last === '') return;

        if ($etag !== '') $spec['headers']['If-None-Match']     = $etag;
        if ($last !== '') $spec['headers']['If-Modified-Since'] = $last;

        $spec['on_headers'] = static function(array $headers, array $info) use ($etag, $last) {
            $status = $info['http_code'];
            if ($status === 304)                                               return true;
            if ($status !== 200)                                               return false;
            if ($etag !== '' && ($headers['etag'] ?? null) === $etag)          return true;
            if ($last !== '' && ($headers['last-modified'] ?? null) === $last) return true;
            return false;
        };
    }

    /** Тело не качали (304/валидаторы) — продлеваем свежесть сохранённого ответа. */
    private function touchCache(Cache $cache, array $result): void {
        $cache->setMeta(['fresh_until' => time() + $this->resolveTtl($result['headers'])], -1, true);
    }

    /** TTL: max(конфиг, серверный max-age/Expires). */
    private function resolveTtl(array $headers): int {
        $ttl = (int)Main::dotGet($this->config, 'cache.ttl');

        if (!empty($headers['cache-control']) && preg_match('/\bmax-age\s*=\s*(\d+)/i', $headers['cache-control'], $m))
            $ttl = max($ttl, (int)$m[1]);
        elseif (!empty($headers['expires']) && ($e = strtotime($headers['expires'])) !== false)
            $ttl = max($ttl, $e - time());

        return $ttl;
    }

    // --- XML (копия из API\Drivers\Traits\HasXmlResponse: трейт вешает несовместимый decode_response) ---

    private static function xmlToArray(string $xml): array {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;

        if (!$doc->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET) || $doc->documentElement === null) {
            $errors = array_map(fn(\LibXMLError $e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new \Exception("Ошибка при декодировании XML-ответа: '".(implode('; ', $errors) ?: 'некорректный XML')."'");
        }

        libxml_clear_errors();

        $root = $doc->documentElement;
        return [$root->nodeName => self::domNodeToArray($root)];
    }

    private static function domNodeToArray(\DOMNode $node) {
        $result = [];

        if ($node->hasAttributes())
            foreach ($node->attributes as $attr)
                $result['@attributes'][$attr->nodeName] = $attr->nodeValue;

        $children = [];
        $text     = '';

        foreach ($node->childNodes as $child) {
            switch ($child->nodeType) {
                case XML_ELEMENT_NODE:
                    $children[] = $child;
                    break;
                case XML_TEXT_NODE:
                case XML_CDATA_SECTION_NODE:
                    $text .= $child->nodeValue;
                    break;
            }
        }

        foreach ($children as $child) {
            $name  = $child->nodeName;
            $value = self::domNodeToArray($child);

            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
                continue;
            }

            if (!is_array($result[$name]) || !Main::arrayIsList($result[$name]))
                $result[$name] = [$result[$name]];

            $result[$name][] = $value;
        }

        $text = trim($text);

        if ($text === '')
            return $result === [] ? '' : $result;

        if ($result === [])
            return $text;

        $result['@text'] = $text;
        return $result;
    }
}
