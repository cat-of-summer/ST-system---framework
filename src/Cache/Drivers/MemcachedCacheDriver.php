<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Memcached\MemcachedAdapterInterface;

class MemcachedCacheDriver extends CacheDriver {

    private const ADAPTERS = [
        \ST_system\Cache\Drivers\Memcached\MemcachedExtAdapter::class,
        \ST_system\Cache\Drivers\Memcached\MemcacheExtAdapter::class,
    ];

    private ?MemcachedAdapterInterface $connection = null;

    /** Конфиг соединения, чтобы уметь переподключиться после disconnect(). */
    private array $connection_config = [];

    /** Соединение внедрено извне — оно не наше, ронять его нельзя. */
    private bool $connection_injected = false;

    /** Пул соединений, общий на процесс. */
    private static array $pool = [];

    /** @var \WeakReference[] Живые драйверы — их ссылки на адаптер тоже надо ронять. */
    private static array $live = [];

    protected static function getDefaultConfig(): array {
        return [
            'file'          => 'data',
            'ttl'           => 3600,
            'prefix'        => 'cache:',
            'host'          => null,
            'port'          => 11211,
            'auth'          => null,
            'servers'       => null,
            'persistent_id' => null,
            'connection'    => null,
        ];
    }

    protected function __init(array $config): void {
        $this->attributes['prefix'] = (string)$config['prefix'];
        $this->connection_config    = $config;
        $this->connection_injected  = isset($config['connection'])
                                      && $config['connection'] instanceof MemcachedAdapterInterface;
        $this->connection           = static::getConnection($config);
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;

        self::$live[] = \WeakReference::create($this);
    }

    /** CacheDriver::spawn() клонирует драйвер — клон тоже держит ссылку на адаптер. */
    public function __clone() {
        self::$live[] = \WeakReference::create($this);
    }

    protected function __rebind(array $override): void {
        if (isset($override['prefix']) && $override['prefix'] !== null)
            $this->attributes['prefix'] = (string)$override['prefix'];
        if (isset($override['connection']) && $override['connection'] instanceof MemcachedAdapterInterface) {
            $this->connection          = $override['connection'];
            $this->connection_injected = true;
        }
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    public function isAvailable(): bool {
        return $this->connection() !== null;
    }

    /** Лениво переоткрывает соединение, если его уронил disconnect(). */
    private function connection(): ?MemcachedAdapterInterface {
        if ($this->connection === null && !$this->connection_injected && $this->connection_config !== [])
            $this->connection = static::getConnection($this->connection_config);

        return $this->connection;
    }

    /**
     * Роняет все соединения процесса; следующее обращение откроет новые.
     *
     * Обязателен вызов в ДОЧЕРНЕМ процессе сразу после pcntl_fork(): унаследованный сокет
     * нельзя делить с родителем — их байтовые потоки перемешаются.
     * Для Memcached с persistent_id это особенно важно: такие соединения переживают запрос.
     *
     * Соединения, внедрённые извне через ['connection' => $adapter], не трогаются.
     */
    public static function disconnect(): void {
        self::$pool = [];

        foreach (self::$live as $index => $ref) {
            $driver = $ref->get();

            if ($driver === null)             { unset(self::$live[$index]); continue; }
            if ($driver->connection_injected) continue;

            $driver->connection = null;
        }

        self::$live = array_values(self::$live);
    }

    private static function getConnection(array $cfg): ?MemcachedAdapterInterface {
        if (isset($cfg['connection']) && $cfg['connection'] instanceof MemcachedAdapterInterface)
            return $cfg['connection'];

        $hasServers = is_array($cfg['servers'] ?? null) && $cfg['servers'];
        if (!$hasServers && (!is_string($cfg['host']) || $cfg['host'] === '')) return null;

        $key = md5(serialize([
            $cfg['servers'] ?? null,
            (string)($cfg['host'] ?? ''),
            (int)   ($cfg['port'] ?? 0),
            (string)($cfg['auth'] ?? ''),
            (string)($cfg['persistent_id'] ?? ''),
        ]));
        if (isset(self::$pool[$key])) return self::$pool[$key];

        foreach (self::ADAPTERS as $adapterClass) {
            if (!$adapterClass::isAvailable()) continue;
            try {
                return self::$pool[$key] = $adapterClass::connect($cfg);
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    private function key(string $file): string {
        return $this->attributes['bucket'].':'.$file;
    }

    private function metaKey(string $file): string {
        return $this->attributes['bucket'].':'.$file.'.meta';
    }

    // expires_in из меты → native TTL memcached (большой unix ts). -1 (вечно) → 0.
    private static function expiryFromMeta(array $meta): int {
        $expires = $meta['expires_in'] ?? 0;
        if ($expires === -1) return 0;
        return $expires > 0 ? (int)$expires : 0;
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->connection()->set($this->key($file), $payload, 0);
    }

    protected function readBlob(string $file): ?string {
        $r = $this->connection()->get($this->key($file));
        return ($r === false) ? null : (string)$r;
    }

    protected function writeMeta(string $file, array $meta): void {
        $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Failed to encode JSON for Memcached meta');

        $expiry = static::expiryFromMeta($meta);
        $this->connection()->set($this->metaKey($file), $json, $expiry);
        if ($expiry > 0) $this->connection()->touch($this->key($file), $expiry);
    }

    protected function readMeta(string $file): ?array {
        $r = $this->connection()->get($this->metaKey($file));
        if ($r === false) return null;
        $d = @json_decode($r, true);
        return is_array($d) ? $d : null;
    }

    protected function blobExists(string $file): bool {
        return $this->connection()->exists($this->key($file));
    }

    protected function purgeStorage(): void {
        // Memcached не перечисляет ключи — удаляем известные поля текущего bucket'а.
        // Полная очистка по префиксу невозможна, только flush через purgeBase().
        $file = $this->attributes['file'];
        $keys = [
            $this->key($file), $this->metaKey($file),
            $this->key('data'), $this->metaKey('data'),
        ];
        $this->connection()->delete(array_values(array_unique($keys)));
    }

    protected function purgeBaseStorage(): void {
        // Нет prefix-scoped очистки — сбрасываем весь сервер.
        $this->connection()->flush();
    }

    protected function purgeExpiredStorage(): void {
        // Native TTL сам эвиктит истёкшие записи; здесь — немедленное удаление текущего файла.
        $file    = $this->attributes['file'];
        $raw     = $this->connection()->get($this->metaKey($file));
        $decoded = is_string($raw) ? @json_decode($raw, true) : null;
        $expires = is_array($decoded) ? ($decoded['expires_in'] ?? 0) : 0;

        if ($expires !== -1 && $expires < time())
            $this->connection()->delete([$this->key($file), $this->metaKey($file)]);
    }

    protected function purgeExpiredBaseStorage(): void {
        // No-op: перечисление ключей недоступно, эвикцию истёкших делает native TTL memcached.
    }
}
