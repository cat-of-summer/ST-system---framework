<?php

namespace ST_system;

final class Autoloader {

    private static $class_map = [];

    public static function register_dir(string $base_dir) {
        if (trim($base_dir) == '')
            throw new \Exception("Некорректный путь к директории для автозагрузчика!");
            
        new self($base_dir);
    }

    public static function register_class(string $class_name, string $file_path) {
        static $load_class_map_registered = false;

        if ((strpos($file_path, '/') !== 0))
            $file_path = $_SERVER['DOCUMENT_ROOT'].'/'.$file_path;

        
        if (!$load_class_map_registered) {
            spl_autoload_register([self::class, 'load_class_map']);
            $load_class_map_registered = true;
        }
        
        self::$class_map[$class_name] = str_replace('//', '/', $file_path);
    }

    public static function load_class_map(string $class_name) {
        if (isset(self::$class_map[$class_name]) && file_exists(self::$class_map[$class_name]))
            require_once self::$class_map[$class_name];
    }

    private string $base_dir;

    private function __construct(string $base_dir) {

        if ((strpos($base_dir, '/') !== 0))
            $base_dir = $_SERVER['DOCUMENT_ROOT'].'/'.$base_dir;

        $this->base_dir = $base_dir;

        spl_autoload_register([$this, 'load_dir']);
    }

    public function load_dir(string $class_name) {
        $file = str_replace('//', '/', $this->base_dir.'/'.str_replace('\\', '/', $class_name).'.php');

        if (file_exists($file))
            require_once $file;
    }
}