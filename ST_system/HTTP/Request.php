<?php

namespace ST_system\HTTP;

use ST_system\Main;

class Request {

    private $method;
    private $uri;
    private $data = [];
    private $headers = [];
    private $get = [];
    private $post = [];
    private $files = [];
    private $query = [];

    protected function __init():void {}
    protected function rules():array {return [];}

    final public function __construct($route = null, string $request_url = '', array $query_params = []) {

        $this->uri = $request_url ?: $_SERVER['REQUEST_URI'] ?? '/';
        $this->method = isset($_POST['_method']) ? $_POST['_method'] : $_SERVER['REQUEST_METHOD'];

        if ($route && !in_array($this->method, $route->methods ?? []))
            throw new \Exception("Method {$this->method} is not allowed!", 403);

        $raw_input = @json_decode(@file_get_contents('php://input'), true);
        $_POST = array_merge($_POST, is_array($raw_input) ? $raw_input : []);

        if (!in_array($this->method, $route->methods))
            throw new \Exception("Method {$this->method} is not allowed!", 403);

        $this->get = $_GET;
        $this->post = $_POST;
        $this->data = array_merge($_GET, $_POST);

        $this->query = $query_params;
        $this->files = $_FILES;

        foreach ($_SERVER as $name => $value)
            if (str_starts_with($name, 'HTTP_'))
                $this->headers[str_replace(' ', '-', ucwords(str_replace('_', ' ', substr($name, 5))))] = $value;
                    
        Main::prepare_params($this->rules(), $this->data);

        $this->__init();
    }

    final public function __call(string $name, array $arguments) {
        if (property_exists($this, $name)) {
            $key = $arguments[0] ?? '';

            return $key !== '' && is_array($this->$name)
                ? ($this->$name[$key] ?? null)
                : $this->$name;
        }
        
        throw new \Exception("Method {$name} not found");
    }

}