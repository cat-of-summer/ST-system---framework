<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;

final class Parser extends IntegrationDriver {

    private array  $schema   = [];

    protected static function getDefaultConfig(): array {
        return [
            'endpoint'          => '',
            'cache'             => ['dir' => '~/cache/', 'ttl' => 3600, 'driver' => 'filesystem'],
            'headers' => [
                'user-agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'accept-language'           => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'connection'                => 'keep-alive',
                'upgrade-insecure-requests' => '1',
                'sec-fetch-dest'            => 'document',
                'sec-fetch-mode'            => 'navigate',
                'sec-fetch-site'            => 'none',
                'sec-fetch-user'            => '?1',
                'cache-control'             => 'max-age=0',
                'sec-ch-ua'                 => '"Not/A)Brand";v="8", "Chromium";v="126", "Google Chrome";v="126"',
                'sec-ch-ua-mobile'          => '?0',
                'sec-ch-ua-platform'        => '"Windows"',
            ],
            'follow_redirects'  => true,
        ];
    }

    protected function __init(): void {
        $this->on('__construct', function(array $schema) {
            $this->schema = $schema;
        });

        $this->on('before_curl_init', function(&$r, $m, $p, &$config) {
            $config['headers']      = static::config('headers');
            $config['method']       = 'GET';
            $config['content_type'] = 'application/x-www-form-urlencoded';
        });

        $this->on('curl_init', function($curl) {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => static::config('follow_redirects'),
                CURLOPT_USERAGENT      => static::config('headers.user-agent'),
                CURLOPT_ENCODING       => '',
                CURLOPT_COOKIEFILE     => '',
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            ]);
        });
    }

    public function purge(): void {
        $this->purgeBase();
    }

    public function fetch(string $url): array {
        Rule::create('url|required')->throwable()->apply($url);

        $cache = null;
        if ($this->cache()) {
            $cache = $this->cache()->make($url);

            if ($cache->isValid())
                return $cache->get();
        }

        $raw = $this->execute_curl($this->curl_init($url));

        if ($raw['error'])
            throw new \RuntimeException("Parser: curl error for '{$url}': {$raw['error']}");

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($raw['response']);
        libxml_clear_errors();

        $result = $this->applySchema($this->schema, $dom, new \DOMXPath($dom), false);

        if ($cache !== null) $cache->set($result);

        return $result;
    }

    private function applySchema(array $schema, \DOMNode $context, \DOMXPath $xpath, bool $relative): array {
        $result = [];

        foreach ($schema as $key => $definition) {
            if (is_string($key) && strncmp($key, '@', 1) === 0) continue;

            if (is_string($definition))
                $definition = ['@xpath' => $definition];

            $selector = $definition['@xpath'];

            if ($relative && strncmp($selector, '//', 2) === 0)
                $selector = '.' . $selector;

            $nodeList = $xpath->query($selector, $context);
            $nodes    = $nodeList ? iterator_to_array($nodeList) : [];

            $extract = $definition['@extract'] ?? null;

            if ($extract === null) {
                $result[$key] = array_map(
                    fn(\DOMNode $n) => trim(str_replace(["\u{00A0}", "\n"], '', $n->nodeValue)),
                    $nodes
                );
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
