<?php

namespace ST_system;

use ST_system\Storage\File;

final class Loader {

    private function connect(string $path, string $action): void {
        switch ($action) {
            case 'require': require $path; break;
            case 'include': include $path; break;
            case 'require_once': require_once $path; break;
            case 'include_once': include_once $path; break;
            default:
                throw new \Exception("Method {$action} not found");
        }
    }

    public static function create(...$args): static { return new static(...$args); }

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'registerDir':
                return static::create(array_shift($args))->{$name}(array_shift($args) ?? []);
            case 'require':
            case 'include':
            case 'require_once':
            case 'include_once':                
                array_map(fn($path) => static::connect($path, $name),
                    array_keys($this->file->find($input, [
                        ...(array_shift($args) ?? []),
                        'extension' => 'php'
                    ]))
                );
                return;
            case 'registerClass':
                return static::create(array_shift($args))->{$name}(array_shift($args));
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

                array_map(fn($path) => static::connect($path, $name),
                    array_keys($this->file->find($input, [
                        ...(array_shift($args) ?? []),
                        'extension' => 'php'
                    ]))
                );

                return;
            default:
                throw new \Exception("Method {$name} not found");
        }
    }

    private File $file;

    public function __construct(string $path) {
        $this->file = File::make($path);

        if ($this->file->isUri())
            throw new \Exception("Path must be a local file, URI given: {$path}");
    }

    private function registerClass(string $class_name, string $file_path): void {
        static $autoload_registered  = false;
        static $classes_map = [];

        if (!$autoload_registered) {
            spl_autoload_register(function(string $class_name) use ($classes_map) {
                if (isset($classes_map[$class_name]))
                    require $classes_map[$class_name];
            });

            $autoload_registered  = true;
        }

        $file = File::make($this->file->getDirectory().'/'.$file_path.'.php');
        
        if ($file->exists())
            $classes_map[$class_name] = $file->getPathname();
    }

    private function registerDir(array $config = []): void {
        static $directories_map = [];

        $config = [
            'throw' => true,
            'prepend ' => false,
            ...$config
        ];

        $directory = $this->file->getDirectory();

        if (!isset($directories_map[$directory])) {
            spl_autoload_register(function(string $class_name) use ($directory) {
                $file = File::make($directory.'/'.str_replace('\\', '/', $class_name).'.php');

                if ($file->exists())
                    require_once $file->getPathname();
            }, $config['throw'], $config['prepend']);

            $directories_map[$directory] = true;
        }
    }
}