<?php

namespace ST_system;

final class Autoloader {

    private static $class_map = [];

    private static function PSR_4(string $base_dir) {
        if (trim($base_dir) == '')
            throw new \Exception("Некорректный путь к директории для автозагрузчика!");
    
        if ((strpos($base_dir, '/') !== 0))
            $base_dir = $_SERVER['DOCUMENT_ROOT'].'/'.$base_dir;

        spl_autoload_register(function(string $class_name) use ($base_dir) {
            $file = str_replace('//', '/', $base_dir.'/'.str_replace('\\', '/', $class_name).'.php');

            if (file_exists($file))
                require_once $file;
        });
    }

    private static function register_class(string $class_name, string $file_path) {
        static $autoload_registered  = false;

        if ((strpos($file_path, '/') !== 0))
            $file_path = $_SERVER['DOCUMENT_ROOT'].'/'.$file_path;

        if (!$autoload_registered) {
            spl_autoload_register(function(string $class) {
                if (isset(self::$class_map[$class]) && file_exists(self::$class_map[$class]))
                    require self::$class_map[$class];
            });
            $autoload_registered  = true;
        }
        
        self::$class_map[$class_name] = str_replace('//', '/', $file_path);
    }

    // private static function register_function(string $fn_name, $file_path_or_callable) {

    //     if ((strpos($file_path_or_callable, '/') !== 0)) {
    //         $file_path_or_callable = $_SERVER['DOCUMENT_ROOT'].'/'.$file_path_or_callable;
    //     }

    //     self::$function_map[$fn_name] = preg_replace('#/+#','/',$file_path_or_callable);
    // }

    private string $base_dir;

    public function __construct(string $base_dir) {

        if ((strpos($base_dir, '/') !== 0))
            $base_dir = $_SERVER['DOCUMENT_ROOT'].'/'.$base_dir;

        $this->base_dir = $base_dir;
    }

    public static function __callStatic(string $name, array $args) {

        switch ($name) {
            case 'PSR_4':
            case 'register_class':
                return call_user_func([self::class, $name], ...$args);
            case 'register_class_map': 
                foreach ($args[0] as $arg)
                    call_user_func([self::class, $name], ...$arg);
                
                return;
        }

        $file = reset($args) ?? '';

        switch ($name) {
            case 'require_map':
            case 'require_once_map':
            case 'include_map':
            case 'include_once_map':
                foreach ($file as $f)
                    self::__callStatic(substr($name, 0, -4), [$f]);
                
                return;
        }

        if (pathinfo($file, PATHINFO_EXTENSION) === '')
            $file .= '.php';

        if ((strpos($file, '~') === 0))
            $file = $_SERVER['DOCUMENT_ROOT'].'/'.ltrim($file, '~/');
        
        switch ($name) {
            case 'require':
                return require $file;
            case 'require_once':
                return require_once $file;
            case 'include':
                return include $file;
            case 'include_once':
                return include_once $file;
        }

        throw new \Error("Call to undefined method " . __CLASS__ . "::{$name}()");
    }

    public function __call(string $name, array $args) {

        switch ($name) {
            case 'PSR_4':
                return self::PSR_4(rtrim($this->base_dir, '/').'/'.ltrim($args[0], '/'));
            case 'register_class':
                return self::register_class($args[0], rtrim($this->base_dir, '/').'/'.ltrim($args[1], '/'));
        }

        $file = reset($args) ?? '';

        switch ($name) {
            case 'require_map':
            case 'require_once_map':
            case 'include_map':
            case 'include_once_map':
                foreach ($file as $f)
                    self::__call(substr($name, 0, -4), [$f]);
                
                return;
            case 'require':
            case 'require_once':
            case 'include':
            case 'include_once':
                return self::__callStatic($name, [rtrim($this->base_dir, '/').'/'.ltrim($file, '/')]);
        }

        throw new \Error("Call to undefined method " . __CLASS__ . "::{$name}()");
    }

}