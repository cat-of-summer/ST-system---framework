<?php

namespace ST_system\API\Drivers\Parsers;

use ST_system\API\IntegrationDriver;
use ST_system\HTTP\WebClient;
use ST_system\Storage\File;
use ST_system\Main;
use ST_system\Rule;

class DefaultParser extends IntegrationDriver {

    private array  $schema   = [];
    private string $template = '';

    private array $paramOverrides = [];

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
            'endpoint' => '',
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
            'delay'            => 1000,   // пауза между окнами batch, мс (WebClient)
            'batch'            => 1,      // размер окна параллельных запросов (1 — вежливо-последовательно)
            'requeue'          => 3,      // повторов транзиентного сбоя на запрос
        ];
    }

    protected function __init(): void {
        $this->on('__construct', function(array $params = []) {
            Rule::object([
                'schema'   => 'array',
                'template' => 'string',
            ])->throwable()->apply($params);

            $this->schema   = $params['schema'];
            $this->template = $params['template'];
        });
    }

    final public function purge(): void {
        $this->paramOverrides = [];
        $this->purgeBase();
    }

    /** @param string|array|null $input */
    final public function fetch($input = null): array {
        $entrypoint = $this->getEntrypoint();
        $schema     = $this->getSchema()   ?: $this->schema;
        $template   = $this->getTemplate() ?: $this->template;

        $this->fire('before_fetch', $input);

        $calls = [$input];
        if (is_array($input) && $input !== [] && Main::arrayIsList($input)) {
            $calls = $input;
            foreach ($input as $item)
                if (!is_array($item)) { $calls = [$input]; break; }
        }

        // разворачиваем всё в плоский упорядоченный список заданий (URL резолвится сразу:
        // after_redirect-оверрайды в кодовой базе никто не слушает, каскад между вызовами
        // фактически не работает — можно резолвить заранее и качать пачкой)
        $jobs = [];
        foreach ($calls as $callInput) {
            if (is_array($callInput) && !empty($callInput) && !Main::arrayIsList($callInput)) {
                $expanded_calls = [[]];
                foreach ($callInput as $key => $value) {
                    $candidates = is_array($value) ? array_values($value) : [$value];
                    $next = [];
                    foreach ($expanded_calls as $set)
                        foreach ($candidates as $c)
                            $next[] = $set + [$key => $c];
                    $expanded_calls = $next;
                }
            } else {
                $expanded_calls = [$callInput];
            }

            foreach ($expanded_calls as $expanded) {
                $this->fire('before_fetch_one', $expanded);
                $url  = $this->resolveUrl($expanded, $template, $entrypoint);
                $data = is_array($expanded) ? array_merge($expanded, ['url' => $url]) : ['url' => $url];
                $jobs[] = ['input' => $expanded, 'url' => $url, 'data' => $data];
            }
        }

        $responses = $this->fetchAll(array_column($jobs, 'url'));

        $results = [];
        foreach ($jobs as $i => $job) {
            $one = $this->assembleOne($job, $responses[$i] ?? null, $schema, $template, $entrypoint);
            $this->fire('after_fetch_one', $one);
            $results[] = $one;
        }

        $this->fire('after_fetch', $results);

        return $results;
    }

    /**
     * Пачечная загрузка URL через общий конвейер WebClient::group() (окна batch с паузой
     * delay, повторы транзиентных сбоев по requeue). Результаты — по индексам входа.
     *
     * @param string[] $urls
     * @return array<int, ?array>  WebClient-результат ('data' — Resource) на каждый URL
     */
    private function fetchAll(array $urls): array {
        $out = array_fill(0, count($urls), null);
        if (!$urls) return $out;

        $cfg = [
            'method'           => 'get',
            'headers'          => (array)static::config('headers'),
            'follow_redirects' => (bool)static::config('follow_redirects'),
            'exception'        => false,
            'requeue'          => (int)static::config('requeue'),
            'cache'            => [
                'use'    => true,
                'ttl'    => (int)File::config('cache.ttl'),
                'dir'    => File::config('cache.dir'),
                'driver' => 'filesystem',
            ],
        ];

        WebClient::group(function () use ($urls, $cfg, &$out) {
            foreach ($urls as $i => $url) {
                // https проверяем (как прежний File-download), http пропускаем
                $client = WebClient::create($url, ['verify' => stripos($url, 'https://') === 0] + $cfg);

                $client->on('error', function ($spec, array &$r) {
                    if (($r['errno'] ?? 0) !== 0 || ($r['status'] ?? 0) >= 500)
                        $r['requeue'] = true;
                });

                $client->on('response', function ($spec, array &$r) use (&$out, $i) {
                    $out[$i] = $r;
                });
            }
        }, [
            'batch' => max(1, (int)static::config('batch')),
            'delay' => (int)static::config('delay'),
        ])->send();

        return $out;
    }

    /** Сборка одного результата из WebClient-ответа: редирект-хук + извлечение по схеме. */
    private function assembleOne(array $job, ?array $response, array $schema, string $template, string $entrypoint): array {
        $input = $job['input'];
        $url   = $job['url'];
        $data  = $job['data'];

        // бросаем только на реальном транспортном сбое (нет тела); HTTP 4xx/5xx с телом
        // извлекаем как раньше (File-загрузка их не роняла) — один плохой URL не рушит пачку
        if ($response === null || ($response['errno'] ?? 0) !== 0)
            throw new \RuntimeException(
                "Parser: не удалось загрузить '{$url}'"
                .($response ? " (".($response['error'] ?: 'HTTP '.($response['status'] ?? 0)).")" : '')
            );

        $effective = (string)($response['effective_url'] ?? $url);

        if ($effective !== $url && is_array($input) && $template !== '' && $entrypoint === '') {
            $overrides = [];
            $this->fire('after_redirect', $input, $url, $effective, $overrides);

            foreach ($overrides as $key => $map)
                foreach ((array)$map as $orig => $canon)
                    $this->paramOverrides[$key][(string)$orig] = $canon;

            $newUrl = $this->resolveUrl($input, $template, $entrypoint);
            if ($newUrl !== $url)
                $data['url'] = $newUrl;
        }

        $resource = $response['data'] ?? null;

        return [
            'input' => $data,
            'data'  => $resource !== null ? $resource->extract($schema, $data) : [],
        ];
    }

    /** @param string|array|null $input */
    private function resolveUrl($input, string $template, string $entrypoint): string {
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
}
