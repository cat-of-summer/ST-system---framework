<?php

namespace ST_system\Console;

abstract class Command {
    public static string $signature = '';

    private array $arguments = [];
    private array $options   = [];

    final public function __construct(array $positional = [], array $rawOptions = []) {
        [$argDefs, $optDefs] = static::parseSignature(static::$signature);
        $this->arguments = static::resolveArguments($positional, $argDefs);
        $this->options   = static::resolveOptions($rawOptions, $optDefs);
    }

    abstract public function handle(): void;

    protected function line(string $text): void {
        echo $text . PHP_EOL;
    }

    
    protected function option(string $key = '', $default = null) {
        return $key === '' ? $this->options : ($this->options[$key] ?? $default);
    }

    
    protected function argument(string $key = '', $default = null) {
        return $key === '' ? $this->arguments : ($this->arguments[$key] ?? $default);
    }

    
    private static function parseSignature(string $signature): array {
        preg_match_all('/\{([^}]+)\}/', $signature, $tokens);

        $argDefs = [];
        $optDefs = [];

        foreach ($tokens[1] as $token) {
            $token = trim($token);

            if (strpos($token, '--') === 0) {
                $inner = substr($token, 2);
                $alias = null;

                if (strpos($inner, '|') !== false) {
                    [$alias, $inner] = explode('|', $inner, 2);
                }

                if (strpos($inner, '=') !== false) {
                    [$name, $default] = explode('=', $inner, 2);
                    $optDefs[$name] = [
                        'name'    => $name,
                        'alias'   => $alias,
                        'flag'    => false,
                        'default' => $default === '' ? null : $default,
                    ];
                } else {
                    $optDefs[$inner] = [
                        'name'    => $inner,
                        'alias'   => $alias,
                        'flag'    => true,
                        'default' => false,
                    ];
                }
            } else {
                if (substr($token, -1) === '?') {
                    $name = substr($token, 0, -1);
                    $argDefs[] = ['name' => $name, 'required' => false, 'default' => null];
                } elseif (strpos($token, '=') !== false) {
                    [$name, $default] = explode('=', $token, 2);
                    $argDefs[] = ['name' => $name, 'required' => false, 'default' => $default];
                } else {
                    $argDefs[] = ['name' => $token, 'required' => true, 'default' => null];
                }
            }
        }

        return [$argDefs, $optDefs];
    }

    private static function resolveArguments(array $positional, array $argDefs): array {
        $result = [];

        foreach ($argDefs as $i => $def) {
            if (array_key_exists($i, $positional)) {
                $result[$def['name']] = $positional[$i];
            } elseif ($def['required']) {
                fwrite(STDERR, "Missing required argument: {$def['name']}" . PHP_EOL);
                exit(1);
            } else {
                $result[$def['name']] = $def['default'];
            }
        }

        return $result;
    }

    private static function resolveOptions(array $rawOptions, array $optDefs): array {
        $aliasMap = [];
        foreach ($optDefs as $def) {
            if ($def['alias'] !== null) {
                $aliasMap[$def['alias']] = $def['name'];
            }
        }

        $normalized = [];
        foreach ($rawOptions as $key => $value) {
            $normalized[$aliasMap[$key] ?? $key] = $value;
        }

        $result = [];
        foreach ($optDefs as $name => $def) {
            $result[$name] = $normalized[$name] ?? $def['default'];
        }
        foreach ($normalized as $key => $value) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
