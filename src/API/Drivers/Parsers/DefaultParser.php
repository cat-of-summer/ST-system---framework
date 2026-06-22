<?php

namespace ST_system\API\Drivers\Parsers;

use ST_system\API\IntegrationDriver;
use ST_system\Storage\File;
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
            'delay'            => 1000,
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

    final public function fetch(string|array|null $input = null): array {
        $entrypoint = $this->getEntrypoint();
        $schema     = $this->getSchema()   ?: $this->schema;
        $template   = $this->getTemplate() ?: $this->template;

        $this->fire('before_fetch', $input);

        $calls = [$input];
        if (is_array($input) && $input !== [] && array_is_list($input)) {
            $calls = $input;
            foreach ($input as $item)
                if (!is_array($item)) { $calls = [$input]; break; }
        }

        $results = [];
        foreach ($calls as $callInput) {
            if (is_array($callInput) && !empty($callInput) && !array_is_list($callInput)) {
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
                $one = $this->fetchOne($expanded, $schema, $template, $entrypoint);
                $this->fire('after_fetch_one', $one);
                $results[] = $one;
            }
        }

        $this->fire('after_fetch', $results);

        return $results;
    }

    private function fetchOne(string|array|null $input, array $schema, string $template, string $entrypoint): array {
        $url  = $this->resolveUrl($input, $template, $entrypoint);
        $data = is_array($input)
            ? array_merge($input, ['url' => $url])
            : ['url' => $url];

        try {
            $file = File::make($url, static::config())->fetch();
        } catch (\Throwable $e) {
            throw new \RuntimeException("Parser: curl error for '{$url}': {$e->getMessage()}", 0, $e);
        }

        $original  = $file->getOriginal();
        $effective = $original !== null ? ($original->getMeta()['effective_url'] ?? $url) : $url;

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
            }
        }

        return [
            'input' => $data,
            'data'  => $file->extract($schema, $data),
        ];
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
}
