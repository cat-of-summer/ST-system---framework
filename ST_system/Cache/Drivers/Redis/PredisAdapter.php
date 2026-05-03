<?php

namespace ST_system\Cache\Drivers\Redis;

use Predis\Client;

class PredisAdapter implements RedisAdapterInterface {

    private Client $client;

    public function __construct(Client $client) {
        $this->client = $client;
    }

    /** @return static */
    public static function connect(array $cfg): self {
        $params = ['host' => $cfg['host'], 'port' => (int)$cfg['port']];
        if (!empty($cfg['auth'])) $params['password'] = $cfg['auth'];
        if ((int)$cfg['db'] !== 0) $params['database'] = (int)$cfg['db'];
        return new static(new Client($params));
    }

    public function hSet(string $key, string $field, string $value): void {
        $this->client->hset($key, $field, $value);
    }

    /** @return string|false */
    public function hGet(string $key, string $field) {
        $r = $this->client->hget($key, $field);
        return ($r === null) ? false : (string)$r;
    }

    public function hExists(string $key, string $field): bool {
        return (bool)$this->client->hexists($key, $field);
    }

    /** @param string|array $keys */
    public function del($keys): void {
        $this->client->del(...(array)$keys);
    }

    /**
     * @param int|string|null $cursor
     * @return array|false
     */
    public function scan(&$cursor, string $pattern, int $count) {
        if ($cursor === null || $cursor === false) $cursor = 0;
        [$cursor, $keys] = $this->client->scan($cursor, ['match' => $pattern, 'count' => $count]);
        $cursor = (int)$cursor;
        return $keys ?: false;
    }
}
