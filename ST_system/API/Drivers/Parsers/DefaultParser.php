<?php

namespace ST_system\API\Drivers\Parsers;

use ST_system\API\IntegrationDriver;
use ST_system\Main;
use ST_system\Rule;

class DefaultParser extends IntegrationDriver {

    private array  $schema   = [];
    private string $template = '';

    private array $paramOverrides = [];

    private static array $last_fetch_per_domain = [];

    protected static function getReservedEvents(): array {
        return array_merge(parent::getReservedEvents(), [
            'before_fetch', 'before_fetch_one', 'after_fetch_one', 'after_fetch', 'after_redirect',
        ]);
    }

    protected function getEntrypoint(): string { return ''; }
    protected function getSchema(): array      { return []; }
    protected function getTemplate(): string   { return ''; }

    protected static function getDefaultConfig(): array {
        return [
            'endpoint'          => '',
            'cache'             => ['dir' => '', 'ttl' => 3600, 'driver' => 'filesystem'],
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
            'follow_redirects' => true,
            'delay'   => 1000,
        ];
    }

    protected function __init(): void {
        $this->on('__construct', function(array $params = []) {
            Rule::object([
                'schema'   => ['array|nullable', Rule::default([])],
                'template' => ['string|nullable', Rule::default('')],
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
        $this->paramOverrides = [];
        $this->purgeBase();
    }

    final public function fetch(string|array|null $input = null): array {
        $entrypoint = $this->getEntrypoint();
        $schema     = $this->getSchema()   ?: $this->schema;
        $template   = $this->getTemplate() ?: $this->template;

        $this->fire('before_fetch', $input);

        $calls = $this->normalizeCalls($input);

        $results = [];
        foreach ($calls as $callInput) {
            $expanded_calls = (is_array($callInput) && !empty($callInput) && !array_is_list($callInput))
                ? $this->expandParams($callInput)
                : [$callInput];

            foreach ($expanded_calls as $expanded) {
                $this->fire('before_fetch_one', $expanded);
                $one = $this->fetchOne($expanded, $schema, $template, $entrypoint);
                $this->fire('after_fetch_one', $one);
                $results[] = $one;
            }
        }

        $this->fire('after_fetch', $results);

        return $results;
    }

    private function normalizeCalls(string|array|null $input): array {
        if (!is_array($input)) return [$input];

        if ($input !== [] && array_is_list($input)) {
            foreach ($input as $item)
                if (!is_array($item)) return [$input];
            return $input;
        }

        return [$input];
    }

    private function expandParams(array $params): array {
        $sets = [[]];
        foreach ($params as $key => $value) {
            $candidates = is_array($value) ? array_values($value) : [$value];
            $next = [];
            foreach ($sets as $set)
                foreach ($candidates as $c)
                    $next[] = $set + [$key => $c];
            $sets = $next;
        }
        return $sets;
    }

    private function fetchOne(string|array|null $input, array $schema, string $template, string $entrypoint): array {
        $url  = $this->resolveUrl($input, $template, $entrypoint);
        $data = is_array($input)
            ? array_merge($input, ['url' => $url])
            : ['url' => $url];

        $cache = null;
        if ($this->cache()) {
            $cache = $this->cache()->make($url);

            if ($cache->isValid())
                return $cache->get();

            if ($cache->exists()) {
                $header_list = [];
                foreach ((array)static::config('headers') as $k => $v) {
                    if (is_int($k) && is_string($v) && strpos($v, ':') !== false)
                        [$k, $v] = array_map('trim', explode(':', $v, 2));
                    $header_list[] = "{$k}: {$v}";
                }

                $head = curl_init();
                curl_setopt_array($head, [
                    CURLOPT_URL            => $url,
                    CURLOPT_NOBODY         => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER         => true,
                    CURLOPT_FOLLOWLOCATION => static::config('follow_redirects'),
                    CURLOPT_USERAGENT      => static::config('headers.user-agent'),
                    CURLOPT_HTTPHEADER     => $header_list,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                ]);
                $head_response = curl_exec($head);
                $head_errno    = curl_errno($head);
                curl_close($head);

                if (!$head_errno) {
                    $head_ttl = Main::getHttpCacheTtl($head_response);
                    if ($head_ttl > 0) {
                        $cache->setMeta(['expires_in' => time() + $head_ttl], 0, true);
                        return $cache->get();
                    }
                }
            }
        }

        $delay_ms = (int)static::config('delay');
        if ($delay_ms > 0 && ($host = parse_url($url, PHP_URL_HOST))) {
            $last = self::$last_fetch_per_domain[$host] ?? 0.0;
            if ($last > 0.0) {
                $elapsed_ms = (microtime(true) - $last) * 1000.0;
                if ($elapsed_ms < $delay_ms)
                    usleep((int)(($delay_ms - $elapsed_ms) * 1000));
            }
            self::$last_fetch_per_domain[$host] = microtime(true);
        }

        $raw = $this->execute_curl($this->curl_init($url));

        if ($raw['error'])
            throw new \RuntimeException("Parser: curl error for '{$url}': {$raw['error']}");

        $effective = $raw['effective_url'] ?? $url;
        if ($effective !== $url && is_array($input) && $template !== '' && $entrypoint === '') {
            $overrides = [];
            $this->fire('after_redirect', $input, $url, $effective, $overrides);

            foreach ($overrides as $key => $map)
                foreach ((array)$map as $orig => $canon)
                    $this->paramOverrides[$key][(string)$orig] = $canon;

            $newUrl = $this->resolveUrl($input, $template, $entrypoint);
            if ($newUrl !== $url) {
                $url = $newUrl;
                $data['url'] = $url;
                if ($this->cache()) $cache = $this->cache()->make($url);
            }
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($raw['response']);
        libxml_clear_errors();

        $result = [
            'input' => $data,
            'data'  => $this->applySchema($schema, $dom, new \DOMXPath($dom), $data),
        ];

        if ($cache !== null) $cache->set($result);

        return $result;
    }

    private function resolveUrl(string|array|null $input, string $template, string $entrypoint): string {
        if ($entrypoint !== '')
            return $entrypoint;

        if ($template !== '') {
            if (is_array($input)) {
                $url = $template;
                foreach ($input as $key => $val) {
                    $resolved = $this->paramOverrides[$key][(string)$val] ?? $val;
                    $url = str_replace('{' . $key . '}', $resolved, $url);
                }
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
