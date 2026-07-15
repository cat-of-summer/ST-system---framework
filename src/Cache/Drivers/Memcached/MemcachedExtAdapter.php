<?php

namespace ST_system\Cache\Drivers\Memcached;

class MemcachedExtAdapter implements MemcachedAdapterInterface {

    private \Memcached $client;

    public function __construct(\Memcached $client) {
        $this->client = $client;
    }

    public static function isAvailable(): bool {
        return class_exists(\Memcached::class);
    }

    public static function connect(array $cfg): self {
        $persistent = isset($cfg['persistent_id']) ? (string)$cfg['persistent_id'] : null;
        $m = $persistent !== null && $persistent !== '' ? new \Memcached($persistent) : new \Memcached();

        if (!$m->getServerList()) {
            $servers = [];
            if (is_array($cfg['servers'] ?? null) && $cfg['servers']) {
                foreach ($cfg['servers'] as $s) {
                    $host = (string)($s['host'] ?? $s[0] ?? '');
                    if ($host === '') continue;
                    $port   = (int)($s['port']   ?? $s[1] ?? 11211) ?: 11211;
                    $weight = (int)($s['weight'] ?? $s[2] ?? 0);
                    $servers[] = [$host, $port, $weight];
                }
            } elseif (!empty($cfg['host'])) {
                $servers[] = [(string)$cfg['host'], (int)($cfg['port'] ?? 11211) ?: 11211, 0];
            }

            if (!$servers) throw new \RuntimeException('MemcachedExtAdapter: no servers configured');
            $m->addServers($servers);
        }

        if (!empty($cfg['auth'])) {
            $m->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            [$user, $pass] = array_pad(explode(':', (string)$cfg['auth'], 2), 2, '');
            $m->setSaslAuthData($user, $pass);
        }

        return new static($m);
    }

    public function get(string $key) {
        $r = $this->client->get($key);
        return ($r === false && $this->client->getResultCode() !== \Memcached::RES_SUCCESS) ? false : $r;
    }

    public function set(string $key, string $value, int $expiry = 0): void {
        $this->client->set($key, $value, $expiry);
    }

    public function touch(string $key, int $expiry): void {
        $this->client->touch($key, $expiry);
    }

    public function delete($keys): void {
        if (is_array($keys)) $this->client->deleteMulti($keys);
        else $this->client->delete((string)$keys);
    }

    public function exists(string $key): bool {
        $this->client->get($key);
        return $this->client->getResultCode() === \Memcached::RES_SUCCESS;
    }

    public function flush(): void {
        $this->client->flush();
    }
}
