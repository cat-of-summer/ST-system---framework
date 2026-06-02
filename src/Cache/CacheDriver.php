<?php

namespace ST_system\Cache;

use ST_system\Traits\HasConfig;
use ST_system\Traits\HasAttributes;
use ST_system\Main;
use ST_system\Rule;

abstract class CacheDriver {

    use HasConfig;
    use HasAttributes;

    protected string $id;
    protected array $data_cache   = [];
    protected array $meta_cache   = [];
    protected array $exists_cache = [];

    final public function __construct($key, array $config = []) {
        $this->attributes['raw_key'] = $key;
        $this->id = Main::hash($key);

        static::applyConfig($config);
        Rule::scope(static::class, fn() => $this->__init($config));

        $this->attributes['file'] = (string)($config['file'] ?? 'data');
        $this->attributes['ttl']  = (int)($config['ttl']  ?? 0);
    }

    
    final public function spawn($key, array $override = []): self {
        $clone = clone $this;
        $clone->attributes['raw_key'] = $key;
        $clone->id = Main::hash($key);
        $clone->data_cache   = [];
        $clone->meta_cache   = [];
        $clone->exists_cache = [];

        if (isset($override['file']) && $override['file'] !== '' && $override['file'] !== null)
            $clone->attributes['file'] = (string)$override['file'];
        if (isset($override['ttl']) && $override['ttl'] !== null)
            $clone->attributes['ttl'] = (int)$override['ttl'];

        Rule::scope(static::class, fn() => $clone->__rebind($override));

        return $clone;
    }

    abstract protected function __init(array $config): void;
    protected function __rebind(array $override): void {}

    abstract public function isAvailable(): bool;

    abstract protected function writeBlob(string $file, string $payload): void;
    abstract protected function readBlob(string $file): ?string;
    abstract protected function writeMeta(string $file, array $meta): void;
    abstract protected function readMeta(string $file): ?array;
    abstract protected function blobExists(string $file): bool;
    abstract protected function purgeStorage(): void;
    abstract protected function purgeBaseStorage(): void;
    abstract protected function purgeExpiredStorage(): void;
    abstract protected function purgeExpiredBaseStorage(): void;

    final public function set($data, int $ttl = 0, string $file = ''): void {
        if ($file === '') $file = $this->attributes['file'];
        if ($ttl  === 0)  $ttl  = $this->attributes['ttl'];

        [$type, $payload] = $this->encode($data);

        $this->writeBlob($file, $payload);
        $this->setMeta(['type' => $type], $ttl, true, $file);

        $modified_at = $this->meta_cache[$file]['modified_at'] ?? time();
        $this->data_cache[$file]   = ['modified_at' => $modified_at, 'data' => $data];
        $this->exists_cache[$file] = true;
    }

    final public function get(string $file = '') {
        if (!$this->isValid('expires_in', $file)) return null;
        if ($file === '') $file = $this->attributes['file'];

        $meta = $this->getMeta($file);
        $modified_at = $meta['modified_at'] ?? 0;

        if (isset($this->data_cache[$file]) && $this->data_cache[$file]['modified_at'] === $modified_at)
            return $this->data_cache[$file]['data'];

        $content = $this->readBlob($file);
        if ($content === null) return null;

        $data = $this->decode($content, $meta['type'] ?? '');
        $this->data_cache[$file] = ['modified_at' => $modified_at, 'data' => $data];
        return $data;
    }

    final public function setMeta(array $data, int $ttl = 0, bool $append = true, string $file = ''): void {
        if ($file === '') $file = $this->attributes['file'];

        if ($ttl === -1)
            $data['expires_in'] = -1;
        elseif (!isset($data['expires_in']) && $ttl > 0)
            $data['expires_in'] = $ttl + time();

        if ($append)
            $data = array_merge($this->getMeta($file), $data);

        $now = time();
        $data['modified_at']      = $now;
        $data['meta_modified_at'] = $now;

        $this->writeMeta($file, $data);
        $this->meta_cache[$file] = $data;
    }

    final public function getMeta(string $file = ''): array {
        if ($file === '') $file = $this->attributes['file'];

        if (isset($this->meta_cache[$file])) {
            $expires = $this->meta_cache[$file]['expires_in'] ?? 0;
            if ($expires === -1 || $expires > time())
                return $this->meta_cache[$file];
        }

        $meta = $this->readMeta($file) ?? [];
        $this->meta_cache[$file] = $meta;
        return $meta;
    }

    final public function exists(string $file = ''): bool {
        if ($file === '') $file = $this->attributes['file'];
        if (isset($this->exists_cache[$file])) return $this->exists_cache[$file];
        return $this->exists_cache[$file] = $this->blobExists($file);
    }

    final public function isExpired(string $key = 'expires_in', string $file = ''): bool {
        $expires = $this->getMeta($file)[$key] ?? 0;
        return $expires != -1 && $expires < time();
    }

    final public function isValid(string $key = 'expires_in', string $file = ''): bool {
        if ($this->isExpired($key, $file)) return false;
        return $this->exists($file);
    }

    final public function purge(): void {
        $this->data_cache = $this->meta_cache = $this->exists_cache = [];
        $this->purgeStorage();
    }

    final public function purgeBase(): void {
        $this->data_cache = $this->meta_cache = $this->exists_cache = [];
        $this->purgeBaseStorage();
    }

    final public function purgeExpired(): void {
        $this->data_cache = $this->meta_cache = $this->exists_cache = [];
        $this->purgeExpiredStorage();
    }

    final public function purgeExpiredBase(): void {
        $this->data_cache = $this->meta_cache = $this->exists_cache = [];
        $this->purgeExpiredBaseStorage();
    }

    final protected function encode($data): array {
        $type = gettype($data);

        if (is_object($data))
            $data = $data instanceof \JsonSerializable
                ? $data->jsonSerialize()
                : (array)$data;

        if (is_array($data))
            $data = (string)@json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [$type, (string)$data];
    }

    final protected function decode(string $content, string $type) {
        switch ($type) {
            case 'array':
                return is_array($d = @json_decode($content, true)) ? $d : [];
            case 'object':
                return is_object($d = @json_decode($content, false)) ? $d : new \stdClass();
            case 'integer':
            case 'int':
                return (int)$content;
            case 'double':
            case 'float':
                return (float)$content;
            case 'boolean':
            case 'bool':
                return (bool)$content;
            case 'string':
                return (string)$content;
            default:
                return $content;
        }
    }
}
