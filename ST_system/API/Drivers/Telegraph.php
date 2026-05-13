<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;


final class Telegraph extends IntegrationDriver {

    protected static function getDefaultConfig(): array {
        return [
            'endpoint'  => 'https://api.telegra.ph'
        ];
    }

    private string $access_token = '';
    private string $author_name  = '';
    private string $author_url   = '';
    private string $base_url     = '';

    
    private static array $nodes_map = [
        'h1'         => 'h3',
        'h2'         => 'h3',
        'h3'         => 'h3',
        'h4'         => 'h4',
        'h5'         => 'h4',
        'h6'         => 'h4',
        'p'          => 'p',
        'div'        => null,
        'section'    => null,
        'article'    => null,
        'ul'         => null,
        'ol'         => null,
        'li'         => 'p',
        'a'          => 'a',
        'img'        => 'img',
        'strong'     => 'strong',
        'b'          => 'strong',
        'em'         => 'em',
        'i'          => 'em',
        'u'          => 'u',
        'blockquote' => 'blockquote',
    ];

    protected function __init(): void {
        $this->on('prepare_response', function ($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) {
                $raw_data['error'] = $raw_data['response'];
                return;
            }

            $decoded = @json_decode($raw_data['response'], true);
            if (!is_array($decoded) || !isset($decoded['ok'])) {
                $raw_data['error'] = 'Invalid Telegraph API response';
                return;
            }
            if (empty($decoded['ok'])) {
                $raw_data['error'] = $decoded['error'] ?? 'Unknown Telegraph error';
                return;
            }

            
            $raw_data['response'] = json_encode($decoded['result'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        
        $this->on('call', function (string $method, array &$params) {
            if ($method !== 'createAccount' && $this->access_token !== '')
                $params['access_token'] = $this->access_token;
        });

        $this->on('__construct', function (array $PARAMS = []) {
            Rule::object([
                'short_name'  => 'required|string',
                'author_name' => 'nullable|string',
                'author_url'  => 'nullable|url',
                'base_url'    => 'nullable|url',
            ])->throwable()->apply($PARAMS);

            $this->base_url = $PARAMS['base_url'] ?? $this->detectBaseUrl();

            $PARAMS['author_url'] ??= $this->base_url;
            $PARAMS['author_name'] ??= '';

            if ($this->cache()?->isValid()) {
                $this->access_token = (string)$this->cacheGet();
            } else {
                $data = $this->call('createAccount', [
                    'short_name'  => $PARAMS['short_name'],
                    'author_name' => $PARAMS['author_name'],
                    'author_url'  => $PARAMS['author_url'],
                ]);

                if (!empty($data['access_token'])) {
                    $this->access_token = $data['access_token'];
                    $this->cacheSet($this->access_token);
                }
            }

            $this->author_name = $PARAMS['author_name'];
            $this->author_url  = $PARAMS['author_url'];
        });

        $content_rule = Rule::create(fn(&$v) => is_string($v) || is_array($v) || $v instanceof \DOMDocument)
            ->handleError(fn($v) => 'Content must be an HTML string, array of nodes, or DOMDocument');

        $this->registerMethodsMap([
            'createAccount' => [
                'params' => [
                    'short_name'  => 'required|string',
                    'author_name' => 'nullable|string',
                    'author_url'  => 'nullable|url',
                ],
            ],
            'createPage' => [
                'method' => 'POST',
                'params' => [
                    'title'          => 'required|string',
                    'content'        => $content_rule,
                    'author_name'    => 'nullable|string',
                    'author_url'     => 'nullable|url',
                    'return_content' => 'nullable|bool',
                ],
                'on_prepare' => function (&$params) {
                    $params['author_name'] ??= $this->author_name;
                    $params['author_url']  ??= $this->author_url;
                    if (isset($params['content']))
                        $params['content'] = json_encode(
                            $this->normalize_content($params['content']),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                },
            ],
            'editPage/{path}' => [
                'method' => 'POST',
                'params' => [
                    'path'           => Rule::create(fn(&$v) => is_string($v) && $v !== '')
                                            ->handleError(fn($v) => 'Page path is required')
                                            ->after(fn(&$v) => $v = trim($v, '/'))
                                            ->skip(true),
                    'title'          => 'nullable|string',
                    'content'        => $content_rule,
                    'author_name'    => 'nullable|string',
                    'author_url'     => 'nullable|url',
                    'return_content' => 'nullable|bool',
                ],
                'on_prepare' => function (&$params) {
                    if (isset($params['content']))
                        $params['content'] = json_encode(
                            $this->normalize_content($params['content']),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                },
            ],
            'getPage/{path}' => [
                'params' => [
                    'path'           => Rule::create(fn(&$v) => is_string($v) && $v !== '')
                                            ->handleError(fn($v) => 'Page path is required')
                                            ->after(fn(&$v) => $v = trim($v, '/'))
                                            ->skip(true),
                    'return_content' => 'nullable|bool',
                ],
            ],
        ]);
    }

    
    private function detectBaseUrl(): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$proto}://{$host}";
    }

    private function normalize_url(string $url): string {
        $trimmed = trim($url);
        if (strpos($trimmed, '//') === false && strpos($trimmed, 'http') !== 0)
            return rtrim($this->base_url, '/').'/'.ltrim($trimmed, '/');
        return $trimmed;
    }

    
    private function normalize_content($content): array {
        if (is_array($content))
            return $content;

        if (!$content instanceof \DOMDocument) {
            if (!is_string($content))
                throw new \InvalidArgumentException('Content must be an HTML string, array, or DOMDocument');

            libxml_use_internal_errors(true);
            $doc = new \DOMDocument();
            $doc->loadHTML('<?xml encoding="utf-8" ?>'.$content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            $content = $doc;
        }

        $body = $content->getElementsByTagName('body')->item(0);
        return $this->parse_nodes_recursive($body ? $body->childNodes : $content->childNodes);
    }

    private function parse_nodes_recursive(\DOMNodeList $nodes): array {
        $result = [];

        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = preg_replace('/\s+/u', ' ', $node->textContent);
                if (trim($text) !== '')
                    $result[] = $text;
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE)
                continue;

            $origTag = mb_strtolower($node->nodeName);

            if (!array_key_exists($origTag, self::$nodes_map)) {
                $result = array_merge($result, $this->parse_nodes_recursive($node->childNodes));
                continue;
            }

            $mapped = self::$nodes_map[$origTag];

            if ($mapped === null) {
                $result = array_merge($result, $this->parse_nodes_recursive($node->childNodes));
                continue;
            }

            $tag  = $mapped;
            $item = ['tag' => $tag];

            if ($tag !== 'img') {
                $children = $this->parse_nodes_recursive($node->childNodes);
                if ($children)
                    $item['children'] = $children;
            }

            $attrs = [];
            if ($node->hasAttribute('href'))
                $attrs['href'] = $this->normalize_url($node->getAttribute('href'));
            if ($node->hasAttribute('src'))
                $attrs['src']  = $this->normalize_url($node->getAttribute('src'));

            if ($tag === 'img') {
                if ($node->hasAttribute('alt'))
                    $attrs['alt'] = $node->getAttribute('alt');

                if ($node->hasAttribute('srcset')) {
                    $fixed = [];
                    foreach (explode(',', $node->getAttribute('srcset')) as $part) {
                        [$url, $descriptor] = array_pad(preg_split('/\s+/', trim($part)), 2, '');
                        $fixed[] = trim($this->normalize_url($url).' '.$descriptor);
                    }
                    $attrs['srcset'] = implode(', ', $fixed);
                }
            }

            if ($attrs) $item['attrs'] = $attrs;

            $result[] = $item;
        }

        return $result;
    }
}
