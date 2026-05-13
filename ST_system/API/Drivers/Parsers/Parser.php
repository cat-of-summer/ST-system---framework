<?php

namespace ST_system\API\Drivers\Parsers;

use ST_system\API\IntegrationDriver;

final class Parser extends IntegrationDriver {

    private string $endpoint = '';
    private array  $schema   = [];

    protected static function getDefaultConfig(): array {
        return [
            'endpoint'          => '',
            'cache'             => ['dir' => '~/cache/', 'ttl' => 3600, 'driver' => 'filesystem'],
            'headers'           => ['User-Agent' => 'Mozilla/5.0 (compatible; Parser/1.0)'],
            'follow_redirects'  => true,
        ];
    }

    protected function __init(): void {
        $this->on('__construct', function(string $endpoint, array $schema, array $config = []) {
            $this->endpoint = $endpoint;
            $this->schema   = $schema;
            if (!empty($config)) static::config($config);
        });

        $this->on('curl_init', function($curl) {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => (bool) static::config('follow_redirects'),
                CURLOPT_USERAGENT      => static::config('headers.User-Agent') ?? '',
                CURLOPT_ENCODING       => '',
            ]);
        });
    }

    protected function getEndpoint(): string {
        return $this->endpoint;
    }

    public function fetch(string $url = ''): array {
        $url = $url ?: $this->getEndpoint();

        $cacheEntry = null;
        if ($this->cache()) {
            $cacheEntry = $this->cache()->make([$url], ['ttl' => static::config('cache.ttl')]);
            if ($cacheEntry->isValid()) {
                $cached = $cacheEntry->get();
                if ($cached !== null) return (array) $cached;
            }
        }

        $config = [
            'method'       => 'GET',
            'headers'      => (array)(static::config('headers') ?? []),
            'content_type' => 'application/x-www-form-urlencoded',
            'endpoint'     => $url,
        ];

        $curl = $this->curl_init($url, '', [], $config);
        $raw  = $this->execute_curl($curl);

        if ($raw['error'])
            throw new \RuntimeException("Parser: curl error for '{$url}': {$raw['error']}");

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($raw['response']);
        libxml_clear_errors();

        $result = $this->applySchema($this->schema, $dom, new \DOMXPath($dom), false);

        if ($cacheEntry !== null)
            $cacheEntry->set($result, static::config('cache.ttl'));

        return $result;
    }

    private function applySchema(array $schema, \DOMNode $context, \DOMXPath $xpath, bool $relative): array {
        $result = [];

        foreach ($schema as $key => $definition) {
            if (is_string($key) && str_starts_with($key, '@')) continue;

            if (is_string($definition))
                $definition = ['@xpath' => $definition];

            $selector = $definition['@xpath'];

            if ($relative && str_starts_with($selector, '//'))
                $selector = '.' . $selector;

            $nodeList = $xpath->query($selector, $context);
            $nodes    = $nodeList ? iterator_to_array($nodeList) : [];

            $extract = $definition['@extract'] ?? null;

            if ($extract === null) {
                $result[$key] = array_map(fn(\DOMNode $n) => $n->nodeValue, $nodes);
            } elseif (is_callable($extract)) {
                $result[$key] = $extract($nodes);
            } elseif (is_array($extract)) {
                $items = [];
                foreach ($nodes as $node)
                    $items[] = $this->applySchema($extract, $node, $xpath, true);
                $result[$key] = $items;
            }
        }

        return $result;
    }
}
