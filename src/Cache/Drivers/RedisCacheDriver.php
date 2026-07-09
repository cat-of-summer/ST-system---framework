<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Cache\Drivers\Redis\RedisAdapterInterface;

class RedisCacheDriver extends CacheDriver {

    private const ADAPTERS = [
        \ST_system\Cache\Drivers\Redis\PhpRedisAdapter::class,
        \ST_system\Cache\Drivers\Redis\PredisAdapter::class,
    ];

    private ?RedisAdapterInterface $connection = null;

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
            'file'       => 'data',
            'ttl'        => 3600,
            'prefix'     => 'cache:',
            'host'       => null,
            'port'       => 6379,
            'auth'       => null,
            'db'         => 0,
            'connection' => null,
        ];
    }

    protected function __init(array $config): void {
        $this->attributes['prefix']     = (string)$config['prefix'];
        $this->connection_config        = $config;
        $this->connection_injected      = isset($config['connection'])
                                          && $config['connection'] instanceof RedisAdapterInterface;
        $this->connection               = static::getConnection($config);
        $this->attributes['bucket']     = $this->attributes['prefix'].$this->id;

        self::$live[] = \WeakReference::create($this);
    }

    /** CacheDriver::spawn() клонирует драйвер — клон тоже держит ссылку на адаптер. */
    public function __clone() {
        self::$live[] = \WeakReference::create($this);
    }

    protected function __rebind(array $override): void {
        if (isset($override['prefix']) && $override['prefix'] !== null)
            $this->attributes['prefix'] = (string)$override['prefix'];
        if (isset($override['connection']) && $override['connection'] instanceof RedisAdapterInterface) {
            $this->connection          = $override['connection'];
            $this->connection_injected = true;
        }
        $this->attributes['bucket'] = $this->attributes['prefix'].$this->id;
    }

    public function isAvailable(): bool {
        return $this->connection() !== null;
    }

    /** Лениво переоткрывает соединение, если его уронил disconnect(). */
    private function connection(): ?RedisAdapterInterface {
        if ($this->connection === null && !$this->connection_injected && $this->connection_config !== [])
            $this->connection = static::getConnection($this->connection_config);

        return $this->connection;
    }

    /**
     * Роняет все соединения процесса. Сокеты закрываются деструкторами адаптеров,
     * следующее обращение откроет новые.
     *
     * Обязателен вызов в ДОЧЕРНЕМ процессе сразу после pcntl_fork(): ребёнок наследует сокет
     * родителя, оба начинают писать в один дескриптор, их байтовые потоки перемешиваются.
     * Выглядит это как случайные, невоспроизводимые ошибки Redis.
     *
     * Соединения, внедрённые извне через ['connection' => $adapter], не трогаются.
     */
    public static function disconnect(): void {
        self::$pool = [];

        foreach (self::$live as $index => $ref) {
            $driver = $ref->get();

            if ($driver === null)             { unset(self::$live[$index]); continue; }
            if ($driver->connection_injected) continue;   // соединение не наше

            $driver->connection = null;
        }

        self::$live = array_values(self::$live);
    }

    private static function getConnection(array $cfg): ?RedisAdapterInterface {
        if (isset($cfg['connection']) && $cfg['connection'] instanceof RedisAdapterInterface)
            return $cfg['connection'];

        if (!is_string($cfg['host']) || $cfg['host'] === '') return null;

        $key = md5(serialize([
            (string)($cfg['host'] ?? ''),
            (int)   ($cfg['port'] ?? 0),
            (string)($cfg['auth'] ?? ''),
            (int)   ($cfg['db']   ?? 0),
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

    protected function writeBlob(string $file, string $payload): void {
        $this->connection()->hSet($this->attributes['bucket'], $file, $payload);
    }

    protected function readBlob(string $file): ?string {
        $r = $this->connection()->hGet($this->attributes['bucket'], $file);
        return ($r === false) ? null : $r;
    }

    protected function writeMeta(string $file, array $meta): void {
        $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Failed to encode JSON for Redis meta');
        $this->connection()->hSet($this->attributes['bucket'], $file.'.meta', $json);
    }

    protected function readMeta(string $file): ?array {
        $r = $this->connection()->hGet($this->attributes['bucket'], $file.'.meta');
        if ($r === false) return null;
        $d = @json_decode($r, true);
        return is_array($d) ? $d : null;
    }

    protected function blobExists(string $file): bool {
        return $this->connection()->hExists($this->attributes['bucket'], $file);
    }

    protected function purgeStorage(): void {
        $this->connection()->del($this->attributes['bucket']);
    }

    protected function purgeBaseStorage(): void {
        $cursor = 0;
        do {
            $keys = $this->connection()->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (is_array($keys) && $keys) $this->connection()->del($keys);
        } while ($cursor !== 0);
    }

    protected function purgeExpiredStorage(): void {
        $meta_field = $this->attributes['file'].'.meta';
        $raw     = $this->connection()->hGet($this->attributes['bucket'], $meta_field);
        $decoded = is_string($raw) ? @json_decode($raw, true) : null;
        $expires = is_array($decoded) ? ($decoded['expires_in'] ?? 0) : 0;

        if ($expires !== -1 && $expires < time())
            $this->connection()->del($this->attributes['bucket']);
    }

    protected function purgeExpiredBaseStorage(): void {
        $meta_field = $this->attributes['file'].'.meta';
        $cursor = 0;
        do {
            $keys = $this->connection()->scan($cursor, $this->attributes['prefix'].'*', 500);
            if (!is_array($keys) || !$keys) continue;

            $now = time();
            $to_delete = [];
            foreach ($keys as $bucket) {
                $raw     = $this->connection()->hGet($bucket, $meta_field);
                $decoded = is_string($raw) ? @json_decode($raw, true) : null;
                $expires = is_array($decoded) ? ($decoded['expires_in'] ?? 0) : 0;
                if ($expires !== -1 && $expires < $now) $to_delete[] = $bucket;
            }

            if ($to_delete) $this->connection()->del($to_delete);
        } while ($cursor !== 0);
    }
}
