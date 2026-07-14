<?php

namespace ST_system\Storage;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasAttributes;
use ST_system\Main;
use ST_system\Storage\Mimes;

/**
 * «Именованный блоб + mime + резолвер»: базовый носитель контента и mime-сервиса, не привязанный
 * к диску/сети. Хранит тело в памяти ($raw) и SplFileInfo, построенный из «имени».
 *
 * Вызовы проксируются в __call: сперва в SplFileInfo (getPathname/getExtension/... — резолв mime
 * зависит от них, поэтому info обязан идти первым), затем в mime-сервис (get/set/parse/encode/
 * toHTML/...). Дисковые/сетевые операции (fetch/find) в базе бросают «not materialized» и
 * переопределяются наследником {@see File}.
 *
 * Конструируется напрямую массивом: new Resource(['body' => ..., 'mime' => ...]); File — через
 * свои фабрики (путь/URL).
 */
class Resource {

    use HasConfig;
    use HasAttributes;

    protected static function getDefaultConfig(): array {
        return [
            'mimes' => [
                'extensions' => [
                    'js' => 'application/javascript',
                    'json' => 'application/json',
                    'css' => 'text/css',
                    'woff2' => 'font/woff2',
                    'woff' => 'font/woff',
                    'ttf' => 'font/ttf',
                    'eot' => 'font/eot',
                    'otf' => 'font/otf',
                    'svg' => 'image/svg+xml',
                    'html' => 'text/html',
                    'htm' => 'text/html',
                    'xml' => 'application/xml',
                    'txt' => 'text/plain',
                ],
                'services' => [
                    'text/html' => Mimes\HtmlMime::class,
                    'text/plain' => Mimes\TextPlainMime::class,
                    'text/css' => Mimes\CssMime::class,
                    'text/javascript' => Mimes\JsMime::class,
                    'text/xml' => Mimes\XmlMime::class,
                    'application/javascript' => Mimes\JsMime::class,
                    'application/json' => Mimes\JsonMime::class,
                    'application/xml' => Mimes\XmlMime::class,
                    'font/' => Mimes\FontMime::class,
                    'image/svg+xml' => Mimes\SvgMime::class,
                    'image/' => Mimes\ImageMime::class,
                ]
            ],
        ];
    }

    private string $name = '';
    private string $id = '';
    private ?\SplFileInfo $info = null;
    private ?Mimes\Mime $mime = null;
    private array $mime_data = [];
    protected $original = null;
    protected $raw = null;

    protected function __construct(array $config = []) {
        $this->name       = $config['name'] ?? 'inline';
        $this->id         = (string)($config['id'] ?? '');
        $this->raw        = $config['body'] ?? null;
        $this->original   = $config['original'] ?? null;
        $this->attributes = $config['options'] ?? [];

        if (isset($config['mime']))
            $this->attributes['mime_override'] = $config['mime'];
    }

    final protected static function make(...$args) {
        return new static(...$args);
    }

    public static function __callStatic(string $name, array $args) {
        if ($name === 'make')
            return static::make(...$args);

        throw new \BadMethodCallException("Method ".static::class."::{$name}() not found");
    }

    /** Идентификатор содержимого для производных кешей: pathname у File, ключ запроса у WebClient. */
    final public function getId(): string {
        return $this->id;
    }

    final protected function info(): \SplFileInfo {
        return $this->info ??= new \SplFileInfo($this->name);
    }

    final protected function mime(): Mimes\Mime {
        return $this->mime ??= static::resolveMimeService($this->getMime(), $this);
    }

    final protected static function resolveMimeService(string $mime, self $file): Mimes\Mime {
        $matched = array_filter(
            static::config('mimes.services'),
            fn($r, $m) => strpos($mime, $m) !== false,
            ARRAY_FILTER_USE_BOTH
        );

        if (!$matched) return new class($file) extends Mimes\Mime {};

        // `new (reset($matched))(...)` — синтаксис PHP 8.0; на 7.4 имя класса берётся из переменной.
        $service = reset($matched);

        return new $service($file);
    }

    final public function setMime(string $mime): self {
        if (
            !((new \ReflectionClass($this->mime()))->isAnonymous()) &&
            !$this->is_uri &&
            $this->exists()
        ) return $this;

        $this->attributes['mime_override'] = $mime;
        $this->mime_data = [];
        $this->mime = static::resolveMimeService($mime, $this);

        return $this;
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'exists':
                return $this->raw !== null;
            case 'fetch':
            case 'find':
                throw new \LogicException("Resource '{$name}()' requires a materialized File (disk/HTTP)");
            case 'getBasename':
                if (empty($args))
                    return $this->info()->getBasename('.'.$this->getExtension());
        }

        // SplFileInfo-методы проксируются первыми (резолв mime зависит от getExtension()/getMime());
        // имя сверяется по кэшу без построения info — mime-методы (parse/toHTML/get) сюда не попадают
        static $info_methods = null;
        if ($info_methods === null)
            $info_methods = array_flip(array_map('strtolower', get_class_methods(\SplFileInfo::class)));

        if (isset($info_methods[strtolower($name)]))
            return $this->info()->{$name}(...$args);

        $key = $args === [] ? $name : $name.'#'.Main::hash($args);

        if (!array_key_exists($key, $this->mime_data))
            $this->mime_data[$key] = $this->mime()->{$name}(...$args);
        return $this->mime_data[$key];
    }

    final public function getOriginal(bool $force = false) {
        $instance = $this;

        if ($force) {
            while ($original = $instance->original)
                $instance = $original;

            return $instance;
        }

        return $instance->original;
    }

    protected function attributeMap(): array {
        return [
            'relative_path' => ['getRelativePath', true],
            'pathname'      => ['getPathname', true],
            'filename'      => ['getFilename', true],
            'basename'      => ['getBasename', true],
            'extension'     => ['getExtension', true],
            'path'          => ['getPath', true],
            'service_name'  => ['getServiceName', true],
            'original'      => ['getOriginal', true],
        ];
    }

    public function purge(bool $storage = true): self {
        $this->purgeAttributes();

        $this->mime_data = [];

        if ($this->mime !== null) $this->mime->purge($storage);

        if ($original = $this->getOriginal())
            $original->purge($storage);

        return $original ?? $this;
    }

    final protected function getIsUriAttribute(): bool {
        return (bool)($this->attributes['is_uri'] ?? false);
    }

    final protected function setIsUriAttribute($v): void {}

    public function getMime(): string {
        if (!empty($this->attributes['mime_override']))
            return $this->attributes['mime_override'];

        $extension = $this->getExtension();

        if (isset(static::config('mimes.extensions')[$extension]))
            return static::config('mimes.extensions')[$extension];

        return '';
    }

    final public function getServiceName(): string {
        return (new \ReflectionClass($this->mime()))->isAnonymous()
            ? 'Default'
            : get_class($this->mime());
    }

    final public function getRelativePath(string $root = ''): string {
        if ($this->is_uri)
            return $this->getPathname();

        return str_replace(Main::preparePath('~'.$root, 1), '', $this->getPathname());
    }

    /** Detached-буфер: возвращает in-memory тело. File переопределяет чтением диска/сети. */
    public function getRaw(bool $force = false) {
        if ($this->raw === null)
            throw new \LogicException("Resource has no content (not materialized)");

        return $this->raw;
    }

    /** Декод собственного тела через mime->get(). */
    final public function get() {
        return $this->mime()->get($this->getRaw());
    }

    /** Кодирование данных в строку через mime->set(). */
    final public function set($data, int &$flags = 0) {
        return $this->mime()->set($data, $flags);
    }

    /** Псевдоним get() для декода собственного тела. */
    final public function getContents() {
        return $this->get();
    }
}
