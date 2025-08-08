<?php

namespace ST_system;

final class Autoloader {

    public static function register(string $base_dir) {
        if (trim($base_dir) == '')
            throw new \Exception("Некорректный путь к директории для автозагрузчика!");
            
        new self($base_dir);
    }

    private string $base_dir;

    private function __construct(string $base_dir) {

        if (strpos($base_dir, '/') != 0)
            $base_dir = $_SERVER['DOCUMENT_ROOT'].'/'.$base_dir;

        $this->base_dir = $base_dir;

        spl_autoload_register([$this, 'load_class']);
    }

    public function load_class(string $class_name) {
        $file = str_replace('//', '/', $this->base_dir.'/'.str_replace('\\', '/', $class_name).'.php');

        if (file_exists($file))
            require_once $file;
    }
}