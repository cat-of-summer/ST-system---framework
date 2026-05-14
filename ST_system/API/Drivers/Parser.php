<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;

class Parser extends IntegrationDriver {

    private array  $schema   = [];
    private string $template = '';

    protected function getEntrypoint(): string { return ''; }
    protected function getSchema(): array      { return []; }
    protected function getTemplate(): string   { return ''; }

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
        $this->on('__construct', function(array $params = []) {
            Rule::object([
                'schema'   => 'array|nullable|default:[]',
                'template' => 'string|nullable|default:',
            ])->throwable()->apply($params);

            $this->schema   = $params['schema'];
            $this->template = $params['template'];
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

    final public function purge(): void {
        $this->purgeBase();
    }

    final public function fetch(string|array|null $input = null): array {
        $entrypoint = $this->getEntrypoint();
        $schema     = $this->getSchema()   ?: $this->schema;
        $template   = $this->getTemplate() ?: $this->template;

        $url  = $this->resolveUrl($input, $template, $entrypoint);
        $data = is_array($input)
            ? array_merge($input, ['url' => $url])
            : ['url' => $url];

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

        $result = $this->applySchema($schema, $dom, new \DOMXPath($dom), $data);

        if ($cache !== null) $cache->set($result);

        return $result;
    }

    private function resolveUrl(string|array|null $input, string $template, string $entrypoint): string {
        if ($entrypoint !== '')
            return $entrypoint;

        if ($template !== '') {
            if (is_array($input)) {
                $url = $template;
                foreach ($input as $key => $val)
                    $url = str_replace('{' . $key . '}', $val, $url);
                if (preg_match('/\{[^}]+\}/', $url))
                    throw new \InvalidArgumentException("Parser: в URL остались неразрешённые плейсхолдеры: {$url}");
                Rule::create('url|required')->throwable()->apply($url);
                return $url;
            }

            if (is_string($input)) {
                $pattern = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($template, '#')) . '$#';
                if (!preg_match($pattern, $input))
                    throw new \InvalidArgumentException("Parser: URL не соответствует шаблону '{$template}'");
                return $input;
            }

            throw new \InvalidArgumentException("Parser: template задан, fetch() принимает строку или массив параметров");
        }

        if (is_string($input)) {
            Rule::create('url|required')->throwable()->apply($input);
            return $input;
        }

        throw new \InvalidArgumentException("Parser: не задан entrypoint и template, fetch() принимает строку URL");
    }

    private function applySchema(array $schema, \DOMNode $context, \DOMXPath $xpath, array $data): array {
        $result = [];

        foreach ($schema as $key => $definition) {
            if (is_string($key) && strncmp($key, '@', 1) === 0) continue;

            if (is_string($definition))
                $definition = ['@xpath' => $definition];

            $selector = $definition['@xpath'];

            $global = isset($selector[0]) && $selector[0] === '~';
            if ($global)
                $selector = substr($selector, 1);
            elseif (!($context instanceof \DOMDocument))
                $selector = '.' . ltrim($selector, '.');

            $nodeList = $xpath->query($selector, $global ? null : $context);
            $nodes    = $nodeList ? iterator_to_array($nodeList) : [];

            $extract = $definition['@extract'] ?? null;
            $asArray = $definition['@array']   ?? true;

            if ($extract === null) {
                $values = array_map(
                    fn(\DOMNode $n) => trim(str_replace(["\u{00A0}", "\n"], '', $n->nodeValue)),
                    $nodes
                );
                $result[$key] = $asArray ? $values : ($values[0] ?? null);
            } elseif (is_callable($extract)) {
                $result[$key] = $asArray
                    ? $extract($nodes, $data)
                    : $extract($nodes[0] ?? null, $data);
            } elseif (is_array($extract)) {
                if ($asArray) {
                    $items = [];
                    foreach ($nodes as $node)
                        $items[] = $this->applySchema($extract, $node, $xpath, $data);
                    $result[$key] = $items;
                } else {
                    $result[$key] = isset($nodes[0])
                        ? $this->applySchema($extract, $nodes[0], $xpath, $data)
                        : null;
                }
            }
        }

        return $result;
    }
}
