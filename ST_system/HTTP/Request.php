<?php

namespace ST_system\HTTP;

use ST_system\Traits\HasValidatableParams;

class Request {

    use HasValidatableParams;

    private static $instance;

    final public static function fetch(array $query_params = []): self {
        static::$instance = new static($query_params);

        return static::$instance;
    }

    final public static function __callStatic(string $name, array $arguments) {
        if (!static::$instance)
            static::fetch();

        if (method_exists(static::class, $name))
            return static::$instance->$name(...$arguments);

        $key = $arguments[0] ?? '';
        $value = static::$instance->$name();

        return $key !== '' && is_array($value)
            ? ($value[$key] ?? null)
            : $value;
    }

    private $data = [];

    protected function __init(): void {}
    protected function __validate(): array { return []; }

    private function __construct(array $query_params = []) {
        static::register_rules_map([
            'mixed' => [null],
            '*mixed' => [fn($k) => new \Exception("Переданный параметр {$k} не должен быть пуст!"), fn($v) => !empty($v)],
            'string' => ['', fn($v) => is_string($v), fn($v) => htmlspecialchars($v)],
            '*string' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть строкой!"), fn($v) => is_string($v), fn($v) => htmlspecialchars($v)],
            'bool' => [false, 'after' => fn($v) => (bool)$v],
            '*bool' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть булевым значением!"), fn($v) => !is_null($v), 'after' => fn($v) => (bool)$v],
            'int' => [0, fn($v) => is_int($v), fn($v) => (int)$v],
            '*int' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть числом!"), fn($v) => is_int($v), fn($v) => (int)$v],
            'email' => ['', fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
            '*email' => [fn($k) => new \Exception("Переданный параметр {$k} не является электронной почтой!"), fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
            'array' => [null, fn($v) => is_array($v)],
            '*array' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть массивом!"), fn($v) => is_array($v)],
            'files' => [null, fn($v) => !empty($v)],
            '*files' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть файлом!"), fn($v) => !empty($v)],
            'files--images' => [[], fn($v) => is_array($v), 'after' => fn($v) => array_values(array_filter($v, fn($f) => in_array($f['extenstion'], ['png', 'jpg', 'webp'])))],
            'files--documents' => [[], fn($v) => is_array($v), 'after' => fn($v) => array_values(array_filter($v, fn($f) => in_array($f['extenstion'], ['doc', 'docx', 'odt', 'pdf'])))],
        ]);

        $this->data['query'] = $query_params;

        if (!empty($this->__validate()))
            $this->validate($this->__validate());

        $this->__init();
    }

    private function pattern(string $route_template, bool $strict_mode = true): self {
        $this->data['query_keys'] = [];
        
        if ($strict_mode)
            $route_template = preg_quote($route_template, '#');

        $this->data['route_template'] = str_replace('//', '/', "#^".preg_replace_callback($strict_mode ? '#\\\\\{(\w+)\\\\\}#' : '#\\{(\w+)\\}#', function ($matches) {
            $this->data['query_keys'][] = $matches[1];
            return "(?P<{$matches[1]}>[^/]+)";
        }, $route_template).'$#');

        unset($this->data['query']);

        return $this;
    }

    private function validate(array $params) {
        $this->data();

        static::prepare_params($params, $this->data['data']);

        foreach (['get', 'post', 'query', 'files'] as $key)
            foreach ($this->data[$key] as $k => $v)
                if ($v == null) {
                    unset($this->data[$key][$k]);
                    unset($this->data['data'][$k]);
                }
        
        return $this;
    }

    private function filter(array $params) {
        $this->data();

        //Тоже самое что validate, но не удаляет неиспользованные ключи
        return $this;
    }

    final public function __call(string $name, array $arguments) {
        if (method_exists($this, $name) && (substr($name, 0, 1) != '_'))
            return $this->$name(...$arguments);

        if (!isset($this->data[$name]))
            switch ($name) {
                case 'uri': $this->data['uri'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); break;
                case 'host': $this->data['host'] = $_SERVER['HTTP_HOST']; break;
                case 'port': $this->data['port'] = $_SERVER['SERVER_PORT']; break;
                case 'scheme': $this->data['scheme'] = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http"; break;     
                case 'origin': $this->data['origin'] = "{$this->scheme()}://{$this->host()}"; break;
                case 'url': $this->data['url'] = "{$this->origin()}{$this->uri()}"; break;
                case 'method': $this->data['method'] = $this->post('_method') ?: $_SERVER['REQUEST_METHOD']; break;
                case 'headers':
                    foreach ($_SERVER as $param_name => $param_value)
                        if (substr($param_name, 0, 5) == 'HTTP_')
                            $this->data['headers'][str_replace(' ', '-', ucwords(str_replace('_', ' ', substr($param_name, 5))))] = $param_value;
                    break;
                case '_get': $this->data['_get'] = $_GET; break;
                case 'get': $this->data['get'] = $this->_get(); break;
                case '_post': $this->data['_post'] = array_merge($_POST, @json_decode(@file_get_contents('php://input'), true) ?? []); break;
                case 'post': $this->data['post'] = $this->_post(); break;
                case '_query':
                    $this->data['_query'] = (!empty($this->data['query_keys']) && preg_match($this->data['route_template'], $this->uri(), $matches))
                        ? array_intersect_key($matches, array_flip($this->data['query_keys']))
                        : [];
                    break;
                case 'query': $this->data['query'] = $this->_query(); break;
                case '_files':
                    $this->data['_files'] = [];
                    foreach ($_FILES as $field => $file) {
                        $this->data['_files'][$field] = [];

                        foreach (is_array($file['name']) ? $file['name'] : [$file['name']] as $i => $filename) {
                            if (is_array($file['error']) ? $file['error'][$i] : $file['error'] !== UPLOAD_ERR_OK)
                                continue;
                            
                            $this->data['_files'][$field][] = [
                                'name'       => $filename,
                                'tmp_name'   => is_array($file['tmp_name']) ? $file['tmp_name'][$i] : $file['tmp_name'],
                                'type'       => is_array($file['type']) ? $file['type'][$i] : $file['type'],
                                'size'       => is_array($file['size']) ? $file['size'][$i] : $file['size'],
                                'extenstion' => strtolower(pathinfo($filename, PATHINFO_EXTENSION))
                            ];
                        }
                    }
                    break;
                case 'files': $this->data['files'] = $this->_files(); break;
                case '_data':
                    $this->data['_data'] = array_merge(
                        $this->_get(),
                        $this->_post(),
                        $this->_query(),
                        $this->_files()
                    );
                    break;
                case 'data':
                    $this->get(); $this->post(); $this->query(); $this->files();

                    $this->data['data'] = [];
                    foreach (['get', 'post', 'query', 'files'] as $key)
                        foreach ($this->data[$key] as $k => &$v)
                            $this->data['data'][$k] = &$v;
                    unset($v);
        
                    break;
                default:
                    throw new \Exception("Method {$name} not found");
            }

        $key = $arguments[0] ?? '';

        return $key !== '' && is_array($this->data[$name])
            ? ($this->data[$name][$key] ?? null)
            : $this->data[$name];
    }

}
