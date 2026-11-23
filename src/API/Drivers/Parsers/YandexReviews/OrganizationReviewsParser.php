<?php

namespace ST_system\API\Drivers\Parsers\YandexReviews;

use ST_system\API\Drivers\Parsers\DefaultParser;
use ST_system\HTTP\WebClient;

final class OrganizationReviewsParser extends DefaultParser {

    private const RANKINGS = ['by_relevance_org', 'by_time', 'by_rating_asc', 'by_rating_desc'];
    private const PAGE_SIZE = 50;

    protected static function getDefaultConfig(): array {
        return array_merge(parent::getDefaultConfig(), [
            'delay' => 3000,
            'api'   => true,
        ]);
    }

    protected function __init(): void {
        parent::__init();

        $explicitPage = false;
        $cookies      = [];

        $this->on('before_fetch', function($input) use (&$explicitPage, &$cookies) {
            $cookies = [];

            if (!is_array($input) || $input === [] || \ST_system\Main::arrayIsList($input))
                throw new \InvalidArgumentException("YandexReviews: fetch() принимает ассоциативный массив ['slug' => ..., 'organization_id' => ...]");

            foreach (['slug', 'organization_id'] as $key)
                if (!isset($input[$key]) || !is_scalar($input[$key]) || $input[$key] === '')
                    throw new \InvalidArgumentException("YandexReviews: '{$key}' обязателен и должен быть скалярным значением");

            if (!preg_match('/^\d+$/', (string)$input['organization_id']))
                throw new \InvalidArgumentException("YandexReviews: 'organization_id' должен состоять из цифр");

            if (!preg_match('/^[^\/?#\s]+$/u', (string)$input['slug']))
                throw new \InvalidArgumentException("YandexReviews: некорректный 'slug'");

            if (isset($input['ranking']) && !in_array($input['ranking'], self::RANKINGS, true))
                throw new \InvalidArgumentException("YandexReviews: 'ranking' должен быть одним из: " . implode(', ', self::RANKINGS));

            if (isset($input['page']) && (!is_numeric($input['page']) || (int)$input['page'] < 1))
                throw new \InvalidArgumentException("YandexReviews: 'page' должен быть целым числом >= 1");

            $explicitPage = isset($input['page']);
        });

        $this->on('before_fetch_one', function(&$input) {
            $input['page']    = (int)($input['page'] ?? 1);
            $input['ranking'] = $input['ranking'] ?? self::RANKINGS[0];
        });

        $this->on('after_response', function(&$data, &$response) use (&$cookies) {
            $cookies[$data['url']] = self::cookieHeader((array)($response['headers']['set-cookie'] ?? []));
        });

        $this->on('after_fetch_one', function(&$one) use (&$explicitPage, &$cookies) {
            $one = $this->assemble($one['input'], $one['data'], $cookies[$one['input']['url']] ?? '', $explicitPage);
        });

        $this->on('after_fetch', function(&$results) {
            $results = $results[0] ?? [];
        });
    }

    protected function getTemplate(): string {
        return 'https://yandex.ru/maps/org/{slug}/{organization_id}/reviews/?page={page}';
    }

    protected function getSchema(): array {
        $text = fn($n) => $n ? trim($n->textContent) : null;

        return [
            'state' => [
                '@xpath'   => '//script[contains(concat(" ",normalize-space(@class)," ")," state-view ")]',
                '@array'   => false,
                '@extract' => fn($n) => $n ? self::compactState(json_decode(self::decodeEntities($n->textContent), true)) : null,
            ],
            'dom' => [
                '@xpath'   => '//div[@itemprop="review"]',
                '@extract' => [
                    'author' => [
                        '@xpath'   => '//*[@itemprop="author"]//*[@itemprop="name"]',
                        '@array'   => false,
                        '@extract' => $text,
                    ],
                    'author_url' => [
                        '@xpath'   => '//a[contains(@class,"business-review-view__link")]/@href',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? $n->nodeValue : null,
                    ],
                    'avatar' => [
                        '@xpath'   => '//*[@itemprop="author"]/meta[@itemprop="image"]/@content',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? $n->nodeValue : null,
                    ],
                    'level' => [
                        '@xpath'   => '//div[contains(@class,"business-review-view__author-caption")]',
                        '@array'   => false,
                        '@extract' => $text,
                    ],
                    'rating' => [
                        '@xpath'   => '//meta[@itemprop="ratingValue"]/@content',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? (float)$n->nodeValue : null,
                    ],
                    'date' => [
                        '@xpath'   => '//meta[@itemprop="datePublished"]/@content',
                        '@array'   => false,
                        '@extract' => fn($n) => $n ? $n->nodeValue : null,
                    ],
                    'text' => [
                        '@xpath'   => '//*[contains(@class,"spoiler-view__text-container")]',
                        '@array'   => false,
                        '@extract' => $text,
                    ],
                ],
            ],
        ];
    }

    /** Загрузчик DOM кодирует не-ASCII числовыми сущностями, а внутри <script> они не раскрываются. */
    private static function decodeEntities(string $s): string {
        return preg_replace_callback('/&#(\d+);/', fn($m) => mb_chr((int)$m[1], 'UTF-8'), $s);
    }

    /** Оставляет из state-view только нужное: сам JSON страницы весит сотни килобайт. */
    private static function compactState($state): ?array {
        $item = $state['stack'][0]['results']['items'][0] ?? null;
        if (!is_array($item)) return null;

        $results = $item['reviewResults'] ?? [];

        return [
            'csrf'         => $state['config']['csrfToken'] ?? null,
            'session'      => $state['config']['counters']['analytics']['sessionId'] ?? null,
            'req'          => $item['requestId'] ?? null,
            'organization' => [
                'id'      => $item['id'] ?? null,
                'slug'    => $item['seoname'] ?? null,
                'title'   => $item['title'] ?? null,
                'address' => $item['fullAddress'] ?? null,
                'rating'       => $item['ratingData']['ratingValue'] ?? null,
                'rating_count' => $item['ratingData']['ratingCount'] ?? null,
                'reviews_count' => $item['ratingData']['reviewCount'] ?? null,
            ],
            'params'  => $results['params']  ?? [],
            'reviews' => $results['reviews'] ?? [],
            'tags'    => $results['tags']    ?? [],
            'aspects' => $results['aspects'] ?? [],
        ];
    }

    private function assemble(array $input, array $data, string $cookie, bool $explicitPage): array {
        $slug    = (string)$input['slug'];
        $orgId   = (string)$input['organization_id'];
        $page    = (int)$input['page'];
        $ranking = (string)$input['ranking'];
        $state   = $data['state'] ?? null;

        [$firstReviews, $firstSource] = self::pageReviews($data);

        $total  = max(1, (int)($state['params']['totalPages'] ?? 1));
        $wanted = $explicitPage ? [$page] : range(1, $total);

        $byPage = [];
        $source = $firstSource;

        // 1. API с токеном: страницы, которых нет в загруженном HTML, и любая нестандартная сортировка
        $needApi = $ranking !== self::RANKINGS[0] ? $wanted : array_diff($wanted, [$page]);
        if ($needApi && static::config('api') && $state && $state['csrf'] && $state['session'] && $state['req']
            && preg_match('/(^|;\s*)i=/', $cookie)) {
            foreach ($needApi as $p) {
                if ($byPage) usleep((int)static::config('delay') * 1000);
                $apiReviews = $this->callApi($state, $cookie, $input['url'], $orgId, $p, $ranking);
                if ($apiReviews === null) { $byPage = []; break; }
                $byPage[$p] = $apiReviews;
            }
            if ($byPage) $source = 'api';
        }

        // 2. Запасной путь: SSR-страницы ?page=N (state-view, а при его отсутствии - DOM)
        $sorted = false;
        if (count($byPage) !== count($needApi) || !$needApi) {
            $byPage = [];
            foreach ($wanted as $p) {
                if ($p === $page) {
                    $byPage[$p] = $firstReviews;
                    continue;
                }
                usleep((int)static::config('delay') * 1000);
                $loaded = $this->loadSsrPage($slug, $orgId, $p);
                $byPage[$p] = $loaded[0];
                if ($loaded[1] === 'dom') $source = 'dom';
            }
            $sorted = $ranking !== self::RANKINGS[0];
        } elseif ($ranking === self::RANKINGS[0] && in_array($page, $wanted, true)) {
            $byPage[$page] = $firstReviews;
        }

        ksort($byPage);
        $reviews = array_merge(...array_values($byPage ?: [[]]));
        if ($sorted) $reviews = self::sortReviews($reviews, $ranking);

        return [
            'organization' => ($state['organization'] ?? []) + ['id' => $orgId, 'slug' => $slug],
            'aspects'      => $state['aspects'] ?? [],
            'tags'         => $state['tags'] ?? [],
            'ranking'      => $ranking,
            'pages'        => ['total' => $total, 'loaded' => array_keys($byPage)],
            'source'       => $source,
            'reviews'      => $reviews,
        ];
    }

    /** Нормализованные отзывы страницы из state-view, иначе из DOM. @return array{0: array, 1: string} */
    private static function pageReviews(array $data): array {
        $raw = $data['state']['reviews'] ?? [];
        if ($raw)
            return [array_map([self::class, 'normalizeJson'], $raw), 'state'];

        return [array_map([self::class, 'normalizeDom'], $data['dom'] ?? []), 'dom'];
    }

    private static function normalizeJson(array $r): array {
        $reply = $r['businessComment'] ?? null;

        return [
            'id'     => $r['reviewId'] ?? null,
            'author' => [
                'name'   => $r['author']['name'] ?? null,
                'id'     => $r['author']['publicId'] ?? null,
                'avatar' => isset($r['author']['avatarUrl']) ? str_replace('{size}', 'islands-200', $r['author']['avatarUrl']) : null,
                'level'  => $r['author']['professionLevel'] ?? null,
            ],
            'rating'   => isset($r['rating']) ? (float)$r['rating'] : null,
            'date'     => $r['updatedTime'] ?? null,
            'text'     => $r['text'] ?? null,
            'reply'    => $reply ? ['text' => $reply['text'] ?? null, 'date' => $reply['updatedTime'] ?? null] : null,
            'likes'    => (int)($r['reactions']['likes'] ?? 0),
            'dislikes' => (int)($r['reactions']['dislikes'] ?? 0),
            'photos'   => array_map(fn($p) => [
                'id'  => $p['id'] ?? null,
                'url' => isset($p['urlTemplate']) ? str_replace('{size}', 'XXL', $p['urlTemplate']) : null,
            ], (array)($r['photos'] ?? [])),
            'pinned'   => (bool)($r['pinned'] ?? false),
        ];
    }

    private static function normalizeDom(array $r): array {
        $authorId = $r['author_url'] && preg_match('~/user/([^/?#]+)~', $r['author_url'], $m) ? $m[1] : null;

        return [
            'id'     => null,
            'author' => [
                'name'   => $r['author'],
                'id'     => $authorId,
                'avatar' => $r['avatar'] ? preg_replace('~/islands-\d+$~', '/islands-200', $r['avatar']) : null,
                'level'  => $r['level'],
            ],
            'rating'   => $r['rating'],
            'date'     => $r['date'],
            'text'     => $r['text'],
            'reply'    => null,
            'likes'    => 0,
            'dislikes' => 0,
            'photos'   => [],
            'pinned'   => false,
        ];
    }

    private static function sortReviews(array $reviews, string $ranking): array {
        usort($reviews, function($a, $b) use ($ranking) {
            switch ($ranking) {
                case 'by_time':        return strcmp((string)$b['date'], (string)$a['date']);
                case 'by_rating_asc':  return ($a['rating'] ?? 0) <=> ($b['rating'] ?? 0);
                case 'by_rating_desc': return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
                default:               return 0;
            }
        });
        return $reviews;
    }

    private static function cookieHeader(array $setCookie): string {
        $pairs = [];
        foreach ($setCookie as $line) {
            $pair = trim(explode(';', (string)$line, 2)[0]);
            if (preg_match('/^[^=]+=.+$/', $pair))
                $pairs[] = $pair;
        }
        return implode('; ', $pairs);
    }

    /** Подпись `s` запроса Яндекс Карт: DJB2-xor от строки запроса с параметрами в алфавитном порядке. */
    private static function sign(string $query): string {
        $h = 5381;
        for ($i = 0, $n = strlen($query); $i < $n; $i++)
            $h = (($h * 33) ^ ord($query[$i])) & 0xFFFFFFFF;
        return (string)$h;
    }

    private function httpGet(string $url, array $headers): ?array {
        try {
            $res = WebClient::create($url, [
                'verify'           => true,
                'headers'          => $headers,
                'follow_redirects' => true,
                'exception'        => false,
            ])->send()[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }

        if (!$res || ($res['errno'] ?? 0) !== 0 || ($res['status'] ?? 0) >= 400 || !is_string($res['body'] ?? null))
            return null;

        return $res;
    }

    private function callApi(array $state, string $cookie, string $referer, string $orgId, int $page, string $ranking): ?array {
        $query = [
            'ajax'       => '1',
            'businessId' => $orgId,
            'csrfToken'  => $state['csrf'],
            'locale'     => 'ru_RU',
            'page'       => (string)$page,
            'pageSize'   => (string)self::PAGE_SIZE,
            'ranking'    => $ranking,
            'reqId'      => $state['req'],
            'sessionId'  => $state['session'],
        ];
        ksort($query);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $headers = array_diff_key((array)static::config('headers'), array_flip(['upgrade-insecure-requests', 'sec-fetch-user']));
        $headers = array_merge($headers, [
            'accept'           => 'application/json, text/plain, */*',
            'referer'          => $referer,
            'x-requested-with' => 'XMLHttpRequest',
            'sec-fetch-dest'   => 'empty',
            'sec-fetch-mode'   => 'cors',
            'sec-fetch-site'   => 'same-origin',
            'cookie'           => $cookie,
        ]);

        $url = 'https://yandex.ru/maps/api/business/fetchReviews?' . $qs . '&s=' . self::sign($qs);

        $raw = null;
        for ($attempt = 0; $attempt < 2 && !is_array($raw); $attempt++) {
            if ($attempt) usleep((int)static::config('delay') * 1000);
            $res = $this->httpGet($url, $headers);
            $raw = $res ? (json_decode($res['body'], true)['data']['reviews'] ?? null) : null;
        }

        return is_array($raw) ? array_map([self::class, 'normalizeJson'], $raw) : null;
    }

    /** @return array{0: array, 1: string} */
    private function loadSsrPage(string $slug, string $orgId, int $page): array {
        $url = str_replace(['{slug}', '{organization_id}', '{page}'], [rawurlencode($slug), $orgId, $page], $this->getTemplate());
        $res = $this->httpGet($url, (array)static::config('headers'));

        $resource = $res['data'] ?? null;
        if (!$resource)
            throw new \RuntimeException("YandexReviews: не удалось загрузить '{$url}'");

        return self::pageReviews($resource->extract($this->getSchema(), ['url' => $url]));
    }
}
