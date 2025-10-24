<?php

namespace ST_system\HTTP;

use ST_system\Traits\Validatable_params;

class Request {

    use Validatable_params;

    private $data = [];

    protected function __init(): void {}
    protected function __validate(): array { return []; }

    final public static function fetch(): self { return new static(); }
    private function __construct() {
        static::register_rules_map([
            'string' => [null, fn($v) => is_string($v), fn($v) => htmlspecialchars($v)],
            '*string' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть строкой!"), fn($v) => is_string($v), fn($v) => htmlspecialchars($v)],
            'int' => [null, fn($v) => is_int($v)],
            'bool' => [null, 'after' => fn($v) => (bool)$v],
            '*bool' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть булевым значением!"), fn($v) => !is_null($v),'after' => fn($v) => (bool)$v],
            '*int' => [fn($k) => new \Exception("Переданный параметр {$k} должен быть числом!"), fn($v) => is_int($v)],
            'email' => [null, fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
            '*email' => [fn($k) => new \Exception("Переданный параметр {$k} не является электронной почтой!"), fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
            'file' => [null, fn($v) => is_array($v) && is_file($v['tmp_name'])],
            'file_array' => [[], fn($v) => is_array($v), 'after' => fn($v) => array_values(array_filter($v, fn($f) => is_array($f) && is_file($f['tmp_name'] ?? '')))],
        ]);

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

        return $this;
    }

    private function validate(array $params) {
        $this->data();

        static::prepare_params_links($params, $this->data['data']);

        foreach (['get', 'post', 'query', 'files'] as $key)
            foreach ($this->data[$key] as $k => $v)
                if ($v === null) {
                    unset($this->data[$key][$k]);
                    unset($this->data['data'][$k]);
                }
        
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
                case 'url': $this->data['url'] = "{$this->scheme()}://{$this->host()}{$this->uri()}";
                case 'method': $this->data['method'] = $this->post('_method') ?: $_SERVER['REQUEST_METHOD'];
                case 'headers':
                    foreach ($_SERVER as $param_name => $param_value)
                        if (substr($param_name, 0, 5) == 'HTTP_')
                            $this->data['headers'][str_replace(' ', '-', ucwords(str_replace('_', ' ', substr($param_name, 5))))] = $param_value;
                    break;
                case 'get': $this->data['get'] = $_GET; break;
                case 'post': $this->data['post'] = array_merge($_POST, @json_decode(@file_get_contents('php://input'), true) ?? []); break;
                case 'query':
                    $this->data['query'] = (!empty($this->data['query_keys']) && preg_match($this->data['route_template'], $this->uri(), $matches))
                        ? array_intersect_key($matches, array_flip($this->data['query_keys']))
                        : [];
                    break;
                case 'files':
                    $this->data['files'] = [];
                    foreach ($_FILES as $field => $file) {
                        $this->data['files'][$field] = [];
                        foreach ($file['name'] as $i => $filename) {
                            if ($file['error'][$i] !== UPLOAD_ERR_OK)
                                continue;
                                            
                             $this->data['files'][$field][] = [
                                'name'       => $filename,
                                'tmp_name'   => $file['tmp_name'][$i],
                                'type'       => $file['type'][$i],
                                'size'       => $file['size'][$i],
                                'extenstion' => strtolower(pathinfo($filename, PATHINFO_EXTENSION))
                            ];
                        }
                    }
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

    final public static function __callStatic(string $name, array $arguments) {
        if (method_exists(static::class, $name))
            return static::fetch()->$name(...$arguments);

        $key = $arguments[0] ?? '';
        $value = static::fetch()->$name();

        return $key !== '' && is_array($value)
            ? ($value[$key] ?? null)
            : $value;
    }
}
