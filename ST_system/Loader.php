<?php

namespace ST_system;

use ST_system\Storage\File;
use ST_system\Debug;

final class Loader {

    private static function connect(string $realpath, string $action): void {
        switch ($action) {
            case 'require': require $realpath; break;
            case 'require_once': require_once $realpath; break;
            case 'include':
            case 'include_once':
                try {
                    if (Debug::linter($realpath)['code'] > 0) return;

                    switch ($action) {
                        case 'include': include $realpath; break;
                        case 'include_once': include_once $realpath; break;
                    }
                } catch (\Throwable $th) {}
                break;
            default:
                throw new \Exception("Method {$action} not found");
        }
    }

    
    public static function create(...$args): self { return new static(...$args); }

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'registerDir':
                return static::create(array_shift($args))->{$name}(array_shift($args) ?? []);
            case 'require':
            case 'include':
            case 'require_once':
            case 'include_once':
                $input = array_shift($args);

                foreach (File::find($input, [
                    ...(array_shift($args) ?? []),
                    'extension' => 'php'
                ]) as $file)
                    static::connect($file->getPathname(), $name);

                return;
            case 'registerClass':
                $path = array_shift($args);
                return static::create($path)->{$name}(...$args);
            default:
                throw new \Exception("Method {$name} not found");
        }
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'registerDir':
            case 'registerClass':
                return $this->{$name}(...$args);
            case 'require':
            case 'include':
            case 'require_once':
            case 'include_once':
                $input = array_shift($args);

                if ($input == '' && $this->file->isFile())
                    $input = $this->file->getFilename();

                foreach ($this->file->find($input, [
                    ...(array_shift($args) ?? []),
                    'extension' => 'php'
                ]) as $file)
                    static::connect($file->getPathname(), $name);

                return;
            default:
                throw new \Exception("Method {$name} not found");
        }
    }

    private File $file;

    public function __construct(string $path) {
        $this->file = File::make($path);

        if ($this->file->is_uri)
            throw new \Exception("Path must be a local file, URI given: {$path}");
    }

    private function registerClass(string $class_name, string $file_path, string $prefix = ''): void {
        static $autoload_registered  = false;
        static $classes_map = [];

        if (!$autoload_registered) {
            spl_autoload_register(function(string $class_name) use (&$classes_map) {
                if (isset($classes_map[$class_name]))
                    require $classes_map[$class_name];
            });

            $autoload_registered  = true;
        }

        $prefix     = trim($prefix, '\\');
        $full_class = $prefix !== '' ? $prefix . '\\' . $class_name : $class_name;

        $file = $this->file->make($file_path.'.php');

        if ($file->exists())
            $classes_map[$full_class] = $file->getPathname();
    }

    private function registerDir(array $config = []): void {
        static $directories_map = [];

        $config = array_merge(
            [
                'throw'   => true,
                'prepend' => false,
                'prefix'  => '',
            ],
            $config
        );

        $prefix    = trim($config['prefix'], '\\');
        $directory = $this->file;
        $mapKey    = $directory->getPathname() . ':' . $prefix;

        if (!isset($directories_map[$mapKey])) {
            spl_autoload_register(function(string $class_name) use ($directory, $prefix) {
                if ($prefix !== '') {
                    $prefixNs = $prefix . '\\';
                    if (strncmp($class_name, $prefixNs, strlen($prefixNs)) !== 0) return;
                    $relative = substr($class_name, strlen($prefixNs));
                } else {
                    $relative = $class_name;
                }

                $file = $directory->make(str_replace('\\', '/', $relative) . '.php');

                if ($file->exists())
                    require_once $file->getPathname();
            }, $config['throw'], $config['prepend']);

            $directories_map[$mapKey] = true;
        }
    }
}
