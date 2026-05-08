<?php

namespace ST_system\Cache\Drivers\Redis;

class PhpRedisAdapter implements RedisAdapterInterface {

    private object $client;

    public function __construct(object $client) {
        $isRedis  = $client instanceof \Redis;
        $isRelay  = class_exists(\Relay\Relay::class) && $client instanceof \Relay\Relay;
        if (!$isRedis && !$isRelay)
            throw new \InvalidArgumentException('PhpRedisAdapter expects \Redis or \Relay\Relay, got '.get_class($client));
        $this->client = $client;
    }

    public static function isAvailable(): bool {
        return class_exists(\Redis::class) || class_exists(\Relay\Relay::class);
    }

    
    public static function connect(array $cfg): self {
        if (class_exists(\Redis::class)) {
            $r = new \Redis();
            $r->connect($cfg['host'], (int)$cfg['port']);
            if (!empty($cfg['auth'])) $r->auth($cfg['auth']);
            if ((int)$cfg['db'] !== 0) $r->select((int)$cfg['db']);
            return new static($r);
        }
        $r = new \Relay\Relay();
        $r->connect($cfg['host'], (int)$cfg['port']);
        if (!empty($cfg['auth'])) $r->auth($cfg['auth']);
        if ((int)$cfg['db'] !== 0) $r->select((int)$cfg['db']);
        return new static($r);
    }

    public function hSet(string $key, string $field, string $value): void {
        $this->client->hSet($key, $field, $value);
    }

    
    public function hGet(string $key, string $field) {
        $r = $this->client->hGet($key, $field);
        return ($r === null) ? false : $r;
    }

    public function hExists(string $key, string $field): bool {
        return (bool)$this->client->hExists($key, $field);
    }

    
    public function del($keys): void {
        $this->client->del($keys);
    }

    
    public function scan(&$cursor, string $pattern, int $count) {
        if ($cursor === null || $cursor === false) $cursor = 0;
        $result = $this->client->scan($cursor, $pattern, $count);
        return ($result === false) ? false : $result;
    }
}
