<?php

namespace ST_system\HTTP;

use ST_system\Main;
use ST_system\Rule;
use ST_system\Cache\Manager as Cache;
use ST_system\Traits\HasEvents;
use ST_system\Traits\HasConfig;
use ST_system\Storage\Resource;

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
 * Очередь «в одну сторону»: выданные комбинации помечаются обработанными и повторно не
 * отправляются — после полного прохода send() возвращает [], next() — null. Полный
 * перезапуск пачки — reset(); query()/schema()/fill() тоже сбрасывают обработанное.
 * Исключение посреди пачки не помечает недоставленные комбо: повторный send()/next()
 * дошлёт только их.
 *
 * Тело запроса: ->schema([...]) задаёт валидацию (Rule::object, throwable), ->fill([...])
 * заполняет — одно тело на все запросы пачки. Значения-файлы (\CURLFile или '@/путь')
 * переключают отправку на multipart/form-data.
 *
 * Параметризация тела: маркер WebClient::param('имя') в спеке поля schema() — сам по себе
 * или в массиве с правилами: ['param' => [WebClient::param('p2'), 'string|email']] — делает
 * поле участником декартова произведения. Значения подаются через query(['p2' => [...]]),
 * валидируются правилами-модификаторами один раз при query() и подставляются в тело
 * per-request. schema() обязан быть вызван ДО query() с маркерными именами.
 *
 * Группа: WebClient::group(fn() => ..., $config) собирает созданные внутри замыкания
 * клиенты (вложенные group() сливаются в одну плоскую группу) в общий конвейер: задания
 * идут в порядке создания клиентов, окна batch могут смешивать запросы разных клиентов
 * на границах. batch/delay — из конфига группы; per-request настройки и события — от
 * клиента-владельца. Каскад конфига при создании клиента: явный конфиг клиента <- конфиг
 * внутренней группы <- ... <- внешней <- дефолты. Группа иммутабельна: query()/schema()/
 * fill() на ней бросают LogicException; события регистрируются на клиентах-участниках;
 * next()/send()/reset() группы действуют на всех участников.
 *
 * Результат — массив: ['status','headers','body','data','type','url','effective_url',
 * 'errno','error','aborted','cached','config','info']. 'data' — Storage\Resource над телом
 * (ленивый декод: ->get()/->extract()/->getDom()); 'type' — MIME этого ресурса; 'config' —
 * действующий конфиг клиента-владельца (различение запросов в общем обработчике группы).
 * Пустое тело -> data=null. Заголовки — assoc, ключи в lowercase.
 *
 * verify (default false) задаёт и SSL-проверки curl, и схему URL: без схемы подставляется
 * https:// (verify=true) или http:// (verify=false); http:// при verify=true — исключение;
 * https:// при verify=false валидно, но SSL-проверки не включаются.
 *
 * Тело оборачивается в in-memory Storage\Resource (без диска/сети): Content-Type (или явный
 * response_type) -> 'type' -> new Resource(['body'=>$body,'mime'=>$type]); декод по требованию.
 *
 * События (HasEvents): 'prepare'(&$spec) — до ключа кеша и настройки хендла;
 * 'prepare_response'($spec,&$result); 'decode_response'($spec,&$result) — нет слушателей
 * или никто не заполнил data -> data = Resource над телом по 'type'; слушателю доступен
 * fill/get-контекст в $spec['params']; 'error'($spec,&$result) — нет слушателей +
 * config('exception') -> исключение; слушатель может вернуть запрос в очередь повторов
 * через $result['requeue'] = true (лимит — config('requeue'): 0 — запрещено, <0 — без
 * лимита, >0 — макс. повторов на запрос; повторы дожёвываются тем же конвейером после
 * основного потока, поэтому работают и while ($r = $client->next()), и цикл по send());
 * 'response'($spec,&$result) — всегда последним, единственное событие для кеш-хитов.
 * Все события per-request, к пачкам не привязаны.
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
            'response_type'    => '',      // '' = автоопределение по Content-Type; иначе явный MIME
            'batch'            => 10,      // размер окна параллельных запросов
            'delay'            => 0,       // пауза между пачками, мс
            'method'           => 'GET',
            'exception'        => true,    // бросать ли исключение на необработанную ошибку
            'requeue'          => 0,       // повторы из 'error': 0 — запрещены, <0 — без лимита, >0 — макс. на запрос
            'cache' => [
                'use'    => false,
                'ttl'    => 3600,
                'dir'    => '',
                'driver' => 'filesystem',
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

    private array $bodyParams = [];   // dot-ключ поля схемы => ['name' => query-параметр, 'mods' => спека правил]
    private array $processed  = [];   // hash комбо => true — выданные результаты (one-way очередь)
    private bool  $isGroup    = false;
    private array $members    = [];   // клиенты группы в порядке создания (только для группы)

    private static array $groupStack        = []; // фреймы активных group(): ['group' => self, 'config' => array]
    private static bool  $constructingGroup = false;

    public function __construct(string $url, array $config = []) {
        if (self::$constructingGroup) {
            static::applyConfig($config, self::configSchema());
            $this->config   = $config;
            $this->template = '';
            $this->isGroup  = true;
            return; // группа: без разбора URL и регистрации в стеке
        }

        // каскад конфигов активных групп под явный конфиг клиента: ключи клиента выигрывают,
        // внутренняя группа приоритетнее внешней, дефолты дозаполнит @-механизм applyConfig
        foreach (array_reverse(self::$groupStack) as $frame)
            foreach (Main::dotFlatten($frame['config']) as $k => $v)
                if (Main::dotGet($config, $k) === null) Main::dotSet($config, $k, $v);

        static::applyConfig($config, self::configSchema());
        $this->config   = $config;
        $this->template = trim($url);

        if (preg_match_all('/\{(\w+)\}/', $this->template, $m))
            $this->placeholders = array_values(array_unique($m[1]));
        else
            $this->template = $this->applyScheme($this->template); // ранняя проверка verify-политики

        $this->rebuildQueryRule();

        if (self::$groupStack)
            self::$groupStack[count(self::$groupStack) - 1]['group']->members[] = $this;
    }

    private static function configSchema(): array {
        return [
            'timeout'          => 'float|@timeout',
            'connect_timeout'  => 'float|@connect_timeout',
            'follow_redirects' => 'bool|@follow_redirects',
            'max_redirects'    => 'int|min:0|@max_redirects',
            'verify'           => 'bool|@verify',
            'headers'          => 'array|@headers',
            'response_type'    => 'string|lowercase|@response_type',
            'batch'            => 'int|min:1|@batch',
            'delay'            => 'int|min:0|@delay',
            'method'           => ['string|uppercase|@method', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'exception'        => 'bool|@exception',
            'requeue'          => 'int|@requeue',
            'cache.use'        => 'bool|@cache.use',
            'cache.ttl'        => 'int|@cache.ttl',
            'cache.dir'        => 'string|@cache.dir',
            'cache.driver'     => 'string|@cache.driver',
        ];
    }

    public static function create(string $url, array $config = []): self {
        return new self($url, $config);
    }

    /**
     * Общий конвейер нескольких клиентов: все WebClient, созданные внутри $fn (включая
     * вложенные group() — они сливаются в одну плоскую группу), образуют одну очередь.
     * $config группы: batch/delay движка + каскадные значения для клиентов внутри.
     */
    public static function group(callable $fn, array $config = []): self {
        $group = self::$groupStack ? self::$groupStack[0]['group'] : self::makeGroup($config);

        self::$groupStack[] = ['group' => $group, 'config' => $config];
        try     { $fn(); }
        finally { array_pop(self::$groupStack); }

        return $group; // вложенные вызовы возвращают тот же внешний экземпляр
    }

    private static function makeGroup(array $config): self {
        self::$constructingGroup = true;
        try     { return new self('', $config); }
        finally { self::$constructingGroup = false; }
    }

    /** Маркер параметризуемого поля тела для schema(): значения подставляются из query(). */
    public static function param(string $name): object {
        return (object)['__wc' => 'param', 'name' => $name];
    }

    private static function isParamMarker($v): bool {
        return $v instanceof \stdClass && ($v->__wc ?? null) === 'param';
    }

    // --- наполнение запроса ---

    /** Параметры шаблона/маркеров тела: строка или массив; массивы декартово размножают запросы. */
    public function query(array $params): self {
        $this->guardGroup('query');

        if (!$this->placeholders && !$this->bodyParams)
            throw new \LogicException('WebClient: URL не шаблонизирован и schema() без маркеров — query() не применим');

        if ($unknown = array_diff(array_keys($params), $this->paramNames()))
            throw new \InvalidArgumentException(
                "WebClient: неизвестные параметры: '".implode("', '", $unknown)
                ."' (параметры тела через WebClient::param() требуют вызова schema() ДО query())"
            );

        if ($this->queryRule !== null)
            $this->queryRule->apply($params);

        foreach ($params as $k => $v)
            $this->queryParams[$k] = array_values((array)$v);

        $this->reset();
        return $this;
    }

    /**
     * Схема тела запроса; смена схемы обнуляет заполненные параметры.
     * Поле со значением-маркером WebClient::param('имя') (или массивом [маркер, ...правила])
     * параметризуется через query() и участвует в декартовом произведении.
     */
    public function schema(array $schema): self {
        $this->guardGroup('schema');

        $this->bodyParams = [];
        $clean = [];
        foreach ($schema as $field => $spec) {
            [$marker, $mods] = self::extractMarker($spec);
            if ($marker === null) { $clean[$field] = $spec; continue; }

            if (!preg_match('/^\w+$/', (string)$marker->name))
                throw new \InvalidArgumentException("WebClient: недопустимое имя параметра тела '{$marker->name}'");
            if (in_array($marker->name, array_column($this->bodyParams, 'name'), true))
                throw new \InvalidArgumentException("WebClient: параметр тела '{$marker->name}' привязан более чем к одному полю");

            $this->bodyParams[$field] = ['name' => $marker->name, 'mods' => $mods];
            $clean[$field] = array_merge(['sometimes'], $mods); // fill() маркерных полей не требует
        }

        $this->bodyRule  = Rule::object($clean)->throwable();
        $this->body      = null;
        $this->multipart = false;

        $this->rebuildQueryRule();
        $this->queryParams = array_intersect_key($this->queryParams, array_flip($this->paramNames()));

        $this->reset();
        return $this;
    }

    /** Одно тело на все запросы пачки; \CURLFile или строки '@/путь' включают multipart. */
    public function fill(array $params): self {
        $this->guardGroup('fill');

        if ($this->bodyRule !== null)
            $this->bodyRule->apply($params);

        $this->multipart = self::detectFiles($params);
        $this->body      = $params;
        $this->reset();
        return $this;
    }

    /** [маркер|null, правила-модификаторы] из спеки поля: маркер сам по себе или среди элементов массива. */
    private static function extractMarker($spec): array {
        if (self::isParamMarker($spec)) return [$spec, []];
        if (!is_array($spec)) return [null, []];

        $marker = null;
        $mods   = [];
        foreach ($spec as $item) {
            if (!self::isParamMarker($item)) { $mods[] = $item; continue; }
            if ($marker !== null)
                throw new \InvalidArgumentException('WebClient: поле схемы содержит более одного маркера param()');
            $marker = $item;
        }
        return $marker === null ? [null, []] : [$marker, $mods];
    }

    /** Допустимые имена для query(): placeholder'ы URL + маркерные параметры тела. */
    private function paramNames(): array {
        return array_values(array_unique(array_merge($this->placeholders, array_column($this->bodyParams, 'name'))));
    }

    /**
     * Правило валидации query(). Значения маркерных параметров проверяются правилами-
     * модификаторами один раз здесь, а не per-combo; строковость не навязывается.
     */
    private function rebuildQueryRule(): void {
        $schema = [];

        foreach ($this->placeholders as $name)
            $schema[$name] = ['sometimes', Rule::anyOf(
                'required|string',
                ['array', 'min:1', Rule::forEach('required|string')]
            )->handleError(fn($v) => "Параметр '{$name}' должен быть непустой строкой или массивом непустых строк")];

        foreach ($this->bodyParams as $param) {
            $name = $param['name'];
            if (isset($schema[$name])) continue; // имя совпало с placeholder'ом — правило шаблона приоритетно
            $spec = $param['mods'] ?: 'required';
            $schema[$name] = ['sometimes', Rule::anyOf(
                $spec,
                ['array', 'min:1', Rule::forEach($spec)]
            )->handleError(fn($v) => "Параметр тела '{$name}' не прошёл валидацию (значение или массив значений)")];
        }

        $this->queryRule = $schema ? Rule::object($schema)->throwable() : null;
    }

    private function guardGroup(string $method): void {
        if ($this->isGroup)
            throw new \LogicException("WebClient: {$method}() неприменим к группе");
    }

    // --- отправка ---

    /** Один готовый результат за вызов; null после исчерпания (до reset() или изменения запроса). */
    public function next(): ?array {
        if ($this->drained) return null;

        try {
            if ($this->pipeline === null) $this->pipeline = $this->run();
            else                          $this->pipeline->next(); // резюм помечает предыдущий результат обработанным

            if (!$this->pipeline->valid()) {
                $this->pipeline = null;
                $this->drained  = true;
                return null;
            }
            return $this->pipeline->current();
        } catch (\Throwable $th) {
            $this->resetPipeline(); // мёртвый генератор; следующий запуск дошлёт необработанные комбо
            throw $th;
        }
    }

    /** Дренирует общий с next() генератор; после исчерпания возвращает [] (перезапуск — reset()). */
    public function send(): array {
        $results = [];
        while (($r = $this->next()) !== null)
            $results[] = $r;

        return $results;
    }

    /** Полный перезапуск пачки: сброс обработанных комбинаций и конвейера (у группы — всех участников). */
    public function reset(): self {
        $this->processed = [];
        $this->resetPipeline();

        if ($this->isGroup)
            foreach ($this->members as $m) $m->reset();

        return $this;
    }

    private function resetPipeline(): void {
        $this->pipeline = null;
        $this->drained  = false;
    }

    /** Проверка полноты + запуск конвейера (проверки выполняются сразу, не лениво). */
    private function run(): \Generator {
        if ($this->isGroup) {
            foreach ($this->members as $m) $m->validateReady();
            return $this->dispatch($this->groupJobs());
        }

        $this->validateReady();
        return $this->dispatch($this->jobs());
    }

    private function validateReady(): void {
        if ($missing = array_diff($this->paramNames(), array_keys($this->queryParams)))
            throw new \LogicException("WebClient: не заполнены параметры: '".implode("', '", $missing)."'");

        if ($this->multipart) {
            $method = strtoupper((string)$this->config['method']);
            if ($method === 'GET' || $method === 'HEAD')
                throw new \LogicException("WebClient: отправка файлов методом {$method} невозможна");
        }
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

    /** Подставляет только placeholder'ы URL: маркерные ключи комбо могут быть не-строками. */
    private function substitute(array $combo): string {
        $url = $this->template;
        foreach ($this->placeholders as $k)
            if (array_key_exists($k, $combo))
                $url = str_replace('{'.$k.'}', (string)$combo[$k], $url);
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

    /** Конверты заданий конвейера: только необработанные комбинации; owner нужен группе. */
    private function jobs(): \Generator {
        foreach ($this->combos() as $combo) {
            $key = self::comboKey($combo);
            if (isset($this->processed[$key])) continue;
            yield ['owner' => $this, 'key' => $key, 'spec' => $this->buildSpec($combo), 'attempts' => 0];
        }
    }

    /** Общий поток группы: задания участников в порядке их создания. */
    private function groupJobs(): \Generator {
        foreach ($this->members as $m)
            yield from $m->jobs();
    }

    private static function comboKey(array $combo): string {
        return md5(serialize($combo)); // порядок ключей одометра стабилен
    }

    /** Спека одного запроса; query string вытаскивается из URL прямо перед отправкой. */
    private function buildSpec(array $combo): array {
        $url = $this->placeholders ? $this->applyScheme($this->substitute($combo)) : $this->template;

        [$clean, $get] = self::splitUrl($url);

        $body      = $this->body;
        $multipart = $this->multipart;
        if ($this->bodyParams) {
            $body = $body ?? [];
            foreach ($this->bodyParams as $field => $param)
                if (array_key_exists($param['name'], $combo))
                    Main::dotSet($body, $field, $combo[$param['name']]);
            $multipart = self::detectFiles($body);
        }

        $method = strtoupper((string)$this->config['method']);
        $isRead = $method === 'GET' || $method === 'HEAD';
        $params = array_replace($get, (array)$body); // материал для ключа кеша

        return [
            'url'           => $clean,
            'get'           => $isRead ? $params : $get,
            'method'        => $method,
            'headers'       => (array)$this->config['headers'],
            'body'          => $isRead ? null : $body,
            'multipart'     => $multipart,
            'response_type' => (string)$this->config['response_type'],
            'params'        => $params,
        ];
    }

    // --- конвейер отправки ---

    /**
     * Чанкует задания по batch и гонит через curl_multi, отдавая каждый результат по мере
     * готовности. $this — исполнитель (клиент или группа), даёт только batch/delay; всё
     * per-request идёт через owner задания (у одиночного клиента owner === $this).
     * Основной поток дополняется очередью повторов (requeue из 'error') — повторы уходят
     * тем же конвейером после исчерпания основного потока. Пометка processed стоит сразу
     * после yield: выданный результат помечается при следующем запросе потребителя,
     * брошенный до yield или недоставленный (закрыт finally) — нет.
     * Свежий curl_init() на запрос, curl_close() сразу после сборки результата;
     * finally гарантирует освобождение хендлов при исключении и при разрушении генератора.
     */
    private function dispatch(\Generator $jobs): \Generator {
        $batch = (int)$this->config['batch'];
        $delay = (int)$this->config['delay'];

        $mh     = curl_multi_init();
        $active = []; // hid => job + ['ch', 'state', 'cache']
        $retry  = []; // очередь повторов текущего прогона (сбрасывается вместе с генератором)

        $take = static function() use ($jobs, &$retry): ?array {
            if ($jobs->valid()) { $job = $jobs->current(); $jobs->next(); return $job; }
            return $retry ? array_shift($retry) : null;
        };

        try {
            $needDelay = false;
            while ($jobs->valid() || $retry) {
                if ($needDelay && $delay > 0)
                    usleep($delay * 1000); // пауза между пачками, не перед первой

                // наполнение пачки (кеш-хиты не занимают слоты)
                for ($n = 0; $n < $batch && ($job = $take()) !== null;) {
                    $owner = $job['owner'];
                    $spec  = $job['spec'];
                    $owner->fire('prepare', $spec); // до ключа кеша — мутации spec влияют на него

                    $cache = $owner->cacheFor($spec);
                    if ($cache !== null) {
                        if ($owner->isFresh($cache) && ($hit = $owner->cacheRead($cache)) !== null) {
                            $hit['cached'] = true;
                            $hit['config'] = $owner->config;
                            $owner->fire('response', $spec, $hit);
                            yield $hit;
                            $owner->processed[$job['key']] = true;
                            continue;
                        }
                        if ($cache->exists())
                            $owner->applyRevalidation($spec, $cache);
                    }

                    $ch    = curl_init();
                    $state = new \stdClass();
                    $owner->configureHandle($ch, $spec, $state);
                    curl_multi_add_handle($mh, $ch);
                    $job['spec'] = $spec; // мутации prepare/ревалидации
                    $active[self::hid($ch)] = $job + ['ch' => $ch, 'state' => $state, 'cache' => $cache];
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

                        $owner = $entry['owner'];
                        if ($owner->finalize($entry['spec'], $result, $entry['cache'], $entry['attempts'])) {
                            yield $result;
                            $owner->processed[$entry['key']] = true;
                        } else {
                            $retry[] = ['owner' => $owner, 'key' => $entry['key'],
                                        'spec' => $entry['spec'], 'attempts' => $entry['attempts'] + 1];
                        }
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
                return Resource::make(['mime' => 'application/json'])->set($body);

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

    /** false — результат не выдаётся, задание возвращается в очередь повторов (requeue из 'error'). */
    private function finalize(array $spec, array &$result, ?Cache $cache, int $attempts = 0): bool {
        $result['config'] = $this->config;

        // тело не качали (304/валидаторы) — отдаём сохранённый ответ, продлив ему жизнь
        if (($result['aborted'] || $result['status'] === 304) && $cache !== null
                && ($hit = $this->cacheRead($cache)) !== null) {
            $this->touchCache($cache, $result);
            $hit['cached'] = true;
            $hit['config'] = $this->config;
            $this->fire('response', $spec, $hit);
            $result = $hit;
            return true;
        }

        $this->fire('prepare_response', $spec, $result);

        $result['type'] = $this->resolveMime($spec, $result);
        $handled = $this->fire('decode_response', $spec, $result);
        if ($handled === false || !array_key_exists('data', $result))
            $this->decodeBody($result);

        if ($result['errno'] !== 0 || $result['status'] >= 400) {
            if ($this->fire('error', $spec, $result) === false && $this->config['exception'])
                throw new \RuntimeException(
                    "WebClient: запрос '{$result['url']}' завершился ошибкой: "
                    .($result['errno'] ? "curl #{$result['errno']} {$result['error']}" : "HTTP {$result['status']}")
                );

            if (!empty($result['requeue'])) {
                unset($result['requeue']);
                if ($this->requeueAllowed($attempts))
                    return false; // слушатель вернул запрос в очередь; 'response' не срабатывает
                // лимит исчерпан / requeue=0 — флаг игнорируется, результат идёт потребителю
            }
            // слушатели были — считаем ошибку обработанной, результат идёт дальше
        }

        $this->fire('response', $spec, $result);

        // data (DOMDocument для html) несериализуема — кэшируем облегчённую копию без
        // конфига, тело реконструируется в data при чтении (cacheRead)
        if ($cache !== null && !$result['aborted'] && $result['errno'] === 0
                && $result['status'] >= 200 && $result['status'] < 300) {
            $lean = $result;
            unset($lean['data'], $lean['config']);
            $cache->set(json_encode($lean), -1, '', [
                'etag'          => $result['headers']['etag'] ?? '',
                'last-modified' => $result['headers']['last-modified'] ?? '',
                'fresh_until'   => time() + $this->resolveTtl($result['headers']),
            ]);
        }

        return true;
    }

    private function requeueAllowed(int $attempts): bool {
        $limit = (int)$this->config['requeue'];
        return $limit < 0 || $attempts < $limit;
    }

    /** MIME для разбора: явный response_type (MIME) или Content-Type ответа. */
    private function resolveMime(array $spec, array $result): string {
        if ($spec['response_type'] !== '')
            return (string)$spec['response_type'];

        return strtolower(trim(explode(';', (string)($result['headers']['content-type'] ?? ''))[0]));
    }

    /**
     * 'data' -> in-memory Storage\Resource над телом по $result['type']. Декодирование ленивое:
     * ->get() (json->массив, html/xml->текст), ->extract($schema)/->getDom()/->toArray().
     * Пустое тело (HEAD/оборванное) -> data = null.
     */
    private function decodeBody(array &$result): void {
        $result['data'] = (is_string($result['body']) && $result['body'] !== '')
            ? Resource::make([
                'body' => $result['body'],
                'mime' => (string)$result['type'],
                'id'   => (string)($result['effective_url'] ?? $result['url'] ?? ''),
            ])
            : null;
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
        if (!is_array($arr)) return null;

        $this->decodeBody($arr); // data не кэшируется — восстанавливаем из body по type
        return $arr;
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
}
