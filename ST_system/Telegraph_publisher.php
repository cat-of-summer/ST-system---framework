<?

namespace ST_system;

class Telegraph_publisher {

    private static $POINT = 'https://api.telegra.ph';

    public static const ERROR_CODES = [
        'SHORT_NAME_REQUIRED'      => 1,
        'SHORT_NAME_INVALID'       => 2,
        'AUTHOR_NAME_INVALID'      => 3,
        'AUTHOR_URL_INVALID'       => 4,
        'ACCESS_TOKEN_REQUIRED'    => 5,
        'ACCESS_TOKEN_INVALID'     => 6,
        'PATH_REQUIRED'            => 7,
        'PAGE_NOT_FOUND'           => 8,
        'ACCESS_DENIED'            => 9,
        'TITLE_REQUIRED'           => 10,
        'TITLE_TOO_LONG'           => 11,
        'CONTENT_REQUIRED'         => 12,
        'CONTENT_INVALID'          => 13,
        'CONTENT_TOO_LONG'         => 14,
        'RETURN_CONTENT_INVALID'   => 15,
    ];

    private static $access_token_path = '/local/php_interface/';
    private static $request_params = [
        'create_account' => ['short_name', 'author_name', 'author_url'],
        'create_page' => ['title', 'content', 'author_name', 'author_url', 'return_content'],
        'edit_page' => ['title', 'content', 'path', 'return_content']
    ];

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

    private $access_token;
    private $base_url;

    private $author_name;
    private $author_url;

    private function send_request(string $method, array $query = [], string $send_method = 'GET') {
        $response = $send_method == 'GET'
            ? @file_get_contents(self::$POINT.'/'.$method.'?'.http_build_query($query))
            : @file_get_contents(self::$POINT.'/'.$method, false, stream_context_create($query));

        if ($response === false)
            throw new \RuntimeException('Ошибка запроса к API Telegra.ph');

        $response_data = @json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) 
            throw new \RuntimeException('Ошибка декодирования ответа');

        if (!$response_data['ok']) {
            $error_code = $data['error'] ?? null;
            throw new \RuntimeException("Telegraph {$method} error: {$error_code}", isset(self::ERROR_CODES[$error_code]) ? self::ERROR_CODES[$error_code] : 0);
        }
        
        return $response_data;
    }

    public function __construct(array $PARAMS) {
        
        self::$access_token_path = $_SERVER['DOCUMENT_ROOT'].self::$access_token_path;

        $this->base_url = isset($PARAMS['base_url'])
            ? $PARAMS['base_url']
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];

        $PARAMS['author_url'] = isset($PARAMS['author_url'])
            ? $PARAMS['author_url']
            : $this->base_url;

        if (!is_dir(self::$access_token_path) && !mkdir(self::$access_token_path, 0755, true))
            throw new \RuntimeException('Не удалось создать директорию для токенов: '.self::$access_token_path);
        
        $PARAMS = array_intersect_key($PARAMS, array_flip(self::$request_params['create_account']));
        array_walk($PARAMS, function (&$param, $key) {
            $param = htmlspecialchars($param, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (!$param)
                throw new \InvalidArgumentException('Передан некорректный параметр инициализации: '.$key);
        });

        $this->access_token_path = self::$access_token_path.md5("{$PARAMS['short_name']}{$PARAMS['author_name']}{$PARAMS['author_url']}").'.telegraphtoken';

        if (file_exists($this->access_token_path ))
            $this->access_token = trim(file_get_contents($this->access_token_path));
        else {
            $data = $this->create_account($PARAMS);
            $this->access_token = $data['result']['access_token'];

            file_put_contents($this->access_token_path, $this->access_token);
        }

        $this->author_name = $PARAMS['author_name'];
        $this->author_url = $PARAMS['author_url'];
    }

    public function create_account(array $PARAMS) {
        $PARAMS = array_intersect_key($PARAMS, array_flip(self::$request_params['create_account']));
        array_walk($PARAMS, function (&$param, $key) {
            $param = htmlspecialchars($param, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (!$param)
                throw new \InvalidArgumentException('Передан некорректный параметр запроса create_account: '.$key);
        });

        return $this->send_request('createAccount', $PARAMS);
    }

    private function normalize_content($content) {
        if (!$content instanceof \DOMDocument) {
            if (!is_string($content))
                throw new \InvalidArgumentException('Передаваемый в create_page контент должен быть html-контентом или \DOMDocument объектом');
            
            libxml_use_internal_errors(true);

            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            libxml_clear_errors();

            $content = $document;
        }

        $body = $content->getElementsByTagName('body')->item(0);
  
        return $this->parse_nodes_recursive($body ? $body->childNodes : $content->childNodes);
    }

    private function normalize_url(string $url): string {
        $trimmed = trim($url);
        if (strpos($trimmed, '//') === false && strpos($trimmed, 'http') !== 0)
            return rtrim($this->base_url, '/') . '/' . ltrim($trimmed, '/');
        
        return $trimmed;
    }

    private function parse_nodes_recursive(\DOMNodeList $nodes): array {
        $result = [];

        foreach ($nodes as $node) {
            // 1) Текст
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = preg_replace('/\s+/u', ' ', $node->textContent);
                
                if (trim($text) !== '') {
                    $result[] = $text;
                }

                continue;
            }

            // 2) Только элементы
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $origTag = mb_strtolower($node->nodeName);

            // 3) Работа с nodes_map
            if (!array_key_exists($origTag, self::$nodes_map)) {
                // тег не описан в карте — спускаемся в детей
                $result = array_merge(
                    $result,
                    $this->parse_nodes_recursive($node->childNodes)
                );
                continue;
            }

            $mapped = self::$nodes_map[$origTag];

            if ($mapped === null) {
                // явно «съедаем» этот тег
                $result = array_merge(
                    $result,
                    $this->parse_nodes_recursive($node->childNodes)
                );
                continue;
            }

            // 4) Формируем новую ноду с тегом $mapped
            $tag = $mapped;
            $item = ['tag' => $tag];

            // 5) Дети (кроме img)
            if ($tag !== 'img') {
                $children = $this->parse_nodes_recursive($node->childNodes);
                if ($children)
                    $item['children'] = $children;
                
            }

            // 6) Атрибуты
            $attrs = [];
            if ($node->hasAttribute('href'))
                $attrs['href'] = $this->normalize_url($node->getAttribute('href'));
            
            if ($node->hasAttribute('src'))
                $attrs['src'] = $this->normalize_url($node->getAttribute('src'));
            
            if ($tag === 'img') {
                if ($node->hasAttribute('alt'))
                    $attrs['alt'] = $node->getAttribute('alt');

                if ($node->hasAttribute('srcset')) {
                    $fixed = [];
                    foreach (explode(',', $node->getAttribute('srcset')) as $part) {
                        [$url, $descriptor] = array_pad(preg_split('/\s+/', trim($part)), 2, '');
                        $fixedUrl = $this->normalize_url($url);
                        $fixed[] = trim("$fixedUrl $descriptor");
                    }
                    $attrs['srcset'] = implode(', ', $fixed);
                }
            }
            if ($attrs)
                $item['attrs'] = $attrs;
            
            $result[] = $item;
        }

        return $result;
    }

    public function create_page(array $PARAMS) {

        $PARAMS['author_name'] = isset($PARAMS['author_name']) ? $PARAMS['author_name'] : $this->author_name;
        $PARAMS['author_url'] = isset($PARAMS['author_url']) ? $PARAMS['author_url'] : $this->author_url;
        $PARAMS['return_content'] = isset($PARAMS['return_content']) ? (bool)$PARAMS['return_content'] : false;

        $PARAMS = array_intersect_key($PARAMS, array_flip(self::$request_params['create_page']));
        array_walk($PARAMS, function (&$param, $key) {
            switch (true) {
                case is_bool($param):
                    break;
                case $key == 'content':
                    break;
                default:
                    $param = htmlspecialchars($param, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    if (!$param)
                        throw new \InvalidArgumentException('Передан некорректный параметр запроса create_page: '.$key);
                    break;
            }
        });

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'access_token'  => $this->access_token,
                    'title'         => $PARAMS['title'],
                    'content'       => json_encode($this->normalize_content($PARAMS['content']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'author_name'   => $PARAMS['author_name'],
                    'author_url'    => $PARAMS['author_url'],
                    'return_content' => $PARAMS['return_content'],
                ]),
            ]
        ];

        return $this->send_request('createPage', $options, 'POST');
    }

    public function edit_page(array $PARAMS) {
        $PARAMS['return_content'] = isset($PARAMS['return_content']) ? (bool)$PARAMS['return_content'] : false;

        $PARAMS = array_intersect_key($PARAMS, array_flip(self::$request_params['edit_page']));
        array_walk($PARAMS, function (&$param, $key) {
            switch (true) {
                case is_bool($param):
                    break;
                case $key == 'content':
                    break;
                default:
                    $param = htmlspecialchars($param, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    if (!$param)
                        throw new \InvalidArgumentException('Передан некорректный параметр запроса edit_page: '.$key);
                    break;
            }
        });

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(array_merge([
                    'access_token'  => $this->access_token,
                    'path'          => $PARAMS['path'],
                    'return_content'=> $PARAMS['return_content'],
                ],
                !isset($PARAMS['title']) ? [] : [
                    'title'         => $PARAMS['title'],
                ],
                !isset($PARAMS['content']) ? [] : [
                    'content'       => json_encode($this->normalize_content($PARAMS['content']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])),
            ]
        ];

        return $this->send_request('editPage', $options, 'POST');
    }

}

/*
    $telegraph = new ST_telegraph_publisher([
        'short_name' => 'test_uavprof',
        'author_name' => 'Test Uavprof',
        'author_url' => 'https://uavprof.dieztech.ru/',
    ]);

    $data = $telegraph->create_page([
        'title' => $name,
        'content' => $html
    ]);
*/