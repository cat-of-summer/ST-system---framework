<?php

namespace ST_system\Cache\Drivers\Memcached;

class MemcacheExtAdapter implements MemcachedAdapterInterface {

    private \Memcache $client;

    public function __construct(\Memcache $client) {
        $this->client = $client;
    }

    public static function isAvailable(): bool {
        return class_exists(\Memcache::class);
    }

    public static function connect(array $cfg): self {
        $m = new \Memcache();

        $servers = [];
        if (is_array($cfg['servers'] ?? null) && $cfg['servers']) {
            foreach ($cfg['servers'] as $s) {
                $host = (string)($s['host'] ?? $s[0] ?? '');
                if ($host === '') continue;
                $servers[] = [$host, (int)($s['port'] ?? $s[1] ?? 11211) ?: 11211];
            }
        } elseif (!empty($cfg['host'])) {
            $servers[] = [(string)$cfg['host'], (int)($cfg['port'] ?? 11211) ?: 11211];
        }

        if (!$servers) throw new \RuntimeException('MemcacheExtAdapter: no servers configured');
        foreach ($servers as [$host, $port]) $m->addServer($host, $port);

        return new static($m);
    }

    public function get(string $key) {
        return $this->client->get($key);
    }

    public function set(string $key, string $value, int $expiry = 0): void {
        $this->client->set($key, $value, 0, $expiry);
    }

    public function touch(string $key, int $expiry): void {
        $v = $this->client->get($key);
        if ($v !== false) $this->client->set($key, $v, 0, $expiry);
    }

    public function delete($keys): void {
        foreach ((array)$keys as $k) $this->client->delete((string)$k);
    }

    public function exists(string $key): bool {
        return $this->client->get($key) !== false;
    }

    public function flush(): void {
        $this->client->flush();
    }
}
