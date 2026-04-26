<?php

namespace ST_system\Console;

use ST_system\Storage\File;
use ST_system\Traits\HasConfig;

final class Kernel {
    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'default' => [
                'dir'       => '~/Console/Commands',
                'namespace' => 'Console\Commands',
            ],
        ];
    }

    private static array $commands = [];

    private function __construct() {}

    public static function registerDir(string $dir, string $namespace): void {
        $files = File::find($dir, ['extension' => 'php', 'max_files' => 0]);

        foreach ($files as $file) {
            $relative = substr(
                str_replace('\\', '/', $file->getPathname()),
                strlen(str_replace('\\', '/', rtrim($dir, '/\\'))) + 1
            );
            $class = $namespace . '\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (class_exists($class) && is_subclass_of($class, Command::class) && $class::$signature !== '') {
                self::register($class::$signature, $class);
            }
        }
    }

    public static function register(string $name, string $class): void {
        self::$commands[$name] = $class;
    }

    public static function handle(array $argv): void {
        static $initialized = false;
        if (!$initialized) {
            $config = static::config('default');
            self::registerDir($config['dir'], $config['namespace']);
            $initialized = true;
        }

        $name = $argv[1] ?? null;

        if (!$name || !isset(self::$commands[$name])) {
            echo 'Unknown command: ' . ($name ?? '(none)') . PHP_EOL;
            echo 'Available: ' . implode(', ', array_keys(self::$commands)) . PHP_EOL;
            exit(1);
        }

        $args       = array_slice($argv, 2);
        $rawOptions = [];
        $positional = [];

        foreach ($args as $arg) {
            if (strpos($arg, '--') === 0) {
                $part = substr($arg, 2);
                if (strpos($part, '=') !== false) {
                    [$k, $v] = explode('=', $part, 2);
                    $rawOptions[$k] = $v;
                } else {
                    $rawOptions[$part] = true;
                }
            } elseif (preg_match('/^-([a-zA-Z])(.*)$/', $arg, $m)) {
                $val = ltrim($m[2], '=');
                $rawOptions[$m[1]] = $val !== '' ? $val : true;
            } else {
                $positional[] = $arg;
            }
        }

        (new self::$commands[$name]($positional, $rawOptions))->handle();
    }
}
