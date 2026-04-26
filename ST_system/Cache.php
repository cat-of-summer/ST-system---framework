<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Main;

final class Cache {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return [
            'dir' => '~/cache/',
            'file' => 'data',
            'ttl' => 3600
        ];
    }

    private string $base_dir;
    private $raw_key;
    private string $dir;
    private string $file;
    private int $ttl;
    private array $data_cache = [];

    public static function __callStatic(string $name, array $args) {
        switch ($name) {
            case 'make':
            case 'isExpired':
            case 'isValid':
                return static::{$name}(...$args);
        }

        if (!in_array($name, [
            'get',
            'set',
            'remember',
            'getMeta',
            'setMeta'
        ], true))
            throw new \BadMethodCallException("Method {$name} not found");
        
        $key = array_shift($args);

        $file = null;
        $ttl  = null;

        switch ($name) {
            case 'get':
            case 'getMeta':
                // get(string $file = '', ...)
                $file = ($args + [null])[0];
                break;
            case 'remember':
                // remember(callable $callback, string $file = '', int $ttl = 0)
                [, $file, $ttl] = $args + [null, null, null];
                break;
            default:
                // set($data, string $file = '', int $ttl = 0)
                // setMeta(array $data, string $file = '', int $ttl = 0)
                [, $file, $ttl] = $args + [null, null, null];
                break;
        }

        $config = [];
        if ($file !== null) $config['file'] = (string)$file;
        if ($ttl  !== null) $config['ttl']  = (int)$ttl;

        return static::make($key, $config)->{$name}(...$args);
    }

    public function __call(string $name, array $args) {
        switch ($name) {
            case 'make':
                $key = array_shift($args) ?: $this->raw_key;
                $config = array_shift($args);

                return static::make($key, [
                    'dir' => $config['dir'] ?? $this->base_dir,
                    'file' => $config['file'] ?? $this->file,
                    'ttl' => $config['ttl'] ?? $this->ttl,
                ]);
            case 'set':
            case 'get':
            case 'remember':
            case 'getMeta':
            case 'setMeta':
            case 'isExpired':
            case 'isValid':
                return $this->{$name}(...$args);
        }
        throw new \BadMethodCallException("Method {$name} not found");
    }

    public function __get(string $name) {
        switch ($name) {
            case 'file':
                return $this->dir.'/'.$this->file;
            case 'meta':
                return $this->__get('file').'.meta';
            case 'base_dir':
            case 'dir':
            case 'ttl':
                return $this->{$name};
        }

        throw new \BadMethodCallException("Property {$name} not found");
    }

    private static function make(...$args): static { return new static(...$args); }

    public function __construct($key, array $config = []) {
        static::hasConfigInit();

        Rule::scope(static::class, function() use (&$config) {
            Rule::object([
                'dir'  => 'string|defaultConfig:dir',
                'file' => 'string|defaultConfig:file',
                'ttl'  => 'int|defaultConfig:ttl',
            ])->apply($config);
        });

        $this->base_dir = Main::preparePath($config['dir'], 3);
        
        $this->initDir(true);

        $this->raw_key = $key;

        $key = md5(Main::serialize($this->raw_key));

        $this->dir = $this->base_dir.'/'.$key;
        $this->file = $config['file'];
        $this->ttl = (int)$config['ttl'];
    }

    public function purgeBase(): void {
        if (!is_dir($this->base_dir)) return;
        
        $it = new \RecursiveDirectoryIterator($this->base_dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        $lock_file = $this->base_dir.'/'.'.lock';
        $lock = fopen($lock_file, 'c');

        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$lock_file}");
        flock($lock, LOCK_EX);

        foreach ($files as $file)
            if ($file->isDir())
                @rmdir($file->getPathname());
            else
                @unlink($file->getPathname());

        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($lock_file);
    }

    public function purge(): void {
        $this->data_cache = [];

        if (!is_dir($this->dir)) return;
        
        $it = new \RecursiveDirectoryIterator($this->dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        $lock_file = $this->dir.'/'.'.lock';
        $lock = fopen($lock_file, 'c');

        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$lock_file}");
        flock($lock, LOCK_EX);

        foreach ($files as $file)
            if ($file->isDir())
                @rmdir($file->getPathname());
            else
                @unlink($file->getPathname());

        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($lock_file);

        @rmdir($this->dir);
    }

    private function setMeta(array $data, int $ttl = 0, bool $append = true, string $file = ''): void {
        $this->initDir();

        if ($ttl === -1)
            $data['expires_in'] = -1;
        elseif (!isset($data['expires_in']) && $ttl > 0)
            $data['expires_in'] = $ttl + time();

        $data = array_merge(
            !$append ? [] : $this->getMeta($file),
            $data
        );

        $file = $this->dir.'/'.($file === '' ? $this->file : $file);
        $meta = $file.'.meta';

        $data['modified_at'] = filemtime($file);
        $data['meta_modified_at'] = is_file($meta) ? filemtime($meta) : 0;

        $lock = fopen($meta.'.lock', 'c+');

        if ($lock === false)
            throw new \RuntimeException("Cannot open lock file {$meta}.lock");
        
        try {
            if (!flock($lock, LOCK_EX))
                throw new \RuntimeException("Cannot acquire exclusive lock on {$meta}.lock");
            
            $tmp = $meta.'.tmp.'.bin2hex(random_bytes(3));

            if (($json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false)
                throw new \RuntimeException("Failed to encode JSON for meta");
            
            if (@file_put_contents($tmp, $json) === false)
                throw new \RuntimeException("Failed to write temp meta file {$tmp}");
            
            if (!rename($tmp, $meta)) {
                @unlink($tmp);
                throw new \RuntimeException("Failed to rename {$tmp} to {$meta}");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($meta.'.lock');
        }
    }

    private function getMeta(string $file = ''): array {
        static $meta_cache = [];

        if (!is_dir($this->dir)) return [
            'meta_modified_at' => false,
            'modified_at' => false
        ];

        if ($file === '')
            $file = $this->file;
        
        $meta = $this->dir.'/'.$file.'.meta';
        $filemtime = is_file($meta) ? filemtime($meta) : 0;

        if (isset($meta_cache[$meta]) && $meta_cache[$meta]['meta_modified_at'] == $filemtime)
            return $meta_cache[$meta];

        $lock = fopen($meta.'.lock', 'c+');

        if ($lock === false)
            throw new \RuntimeException("Cannot open lock file {$meta}.lock");
        
        try {
            if (!flock($lock, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$meta}.lock");
            
            if (!is_file($meta))
                return $data = [];
            
            if (($content = @file_get_contents($meta)) === false)
                return $data = [];
            
            return $data = is_array($decoded = @json_decode($content, true)) ? $decoded : [];
        } finally {
            $data['meta_modified_at'] = $filemtime;
            $meta_cache[$meta] = $data;
            
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($meta.'.lock');

            return $data;
        }
    }

    private function set($data, int $ttl = 0, string $file = ''): void {
        $this->initDir();

        if ($file === '')
            $file = $this->file;
        
        if ($ttl === 0)
            $ttl = $this->ttl;

        $type = gettype($data);
        
        if (is_object($data))
            $data = $data instanceof \JsonSerializable
                ? $data->jsonSerialize()
                : (array)$data;
                
        if (is_array($data))
            $data = @json_encode($data);
        
        @file_put_contents($this->dir.'/'.$file, $data, LOCK_EX);

        unset($this->data_cache[$this->dir.'/'.$file]);

        $this->setMeta(['type' => $type], $ttl, true, $file);
    }

    private function remember(callable $callback, int $ttl = 0, string $file = '') {
        $data = $this->get($file);

        if ($data !== null)
            return $data;
        
        $data = $callback();

        $this->set($data, $ttl, $file);

        return $data;
    }

    private function get(string $file = '') {
        if (!is_dir($this->dir))
            return null;

        if (!$this->isValid('expires_in', $file))
            return null;

        $meta = $this->getMeta($file);

        $file = $this->dir.'/'.($file === '' ? $this->file : $file);
        $filemtime = filemtime($file);

        if (isset($this->data_cache[$file]) && $meta['modified_at'] == $filemtime)
            return $this->data_cache[$file];

        $lock = fopen($file.'.lock', 'c+');

        if ($lock === false)
            throw new \RuntimeException("Cannot open lock file {$file}.lock");
        
        try {
            if (!flock($lock, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$file}.lock");
            
            if (($content = @file_get_contents($file)) === false)
                return $data = null;

            switch ($meta['type']) {
                case 'array':
                    return $data = is_array($decoded = @json_decode($content, true)) ? $decoded : [];
                case 'object':
                    return $data = is_object($decoded = @json_decode($content, false)) ? $decoded : new \stdClass();
                case 'string':
                    return $data = (string)$content;
                case 'int':
                case 'integer':
                    return $data = (int)$content;
                case 'float':
                case 'double':
                    return $data = (float)$content;
                case 'bool':
                case 'boolean':
                    return $data = (bool)$content;
                default:
                    return $data = $content;
            }
        } finally {
            $this->data_cache[$file] = $data;

            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($file.'.lock');
        }
    }

    private function isExpired(string $expires_key = 'expires_in', string $file = ''): bool {
        $expires_in = $this->getMeta($file)[$expires_key] ?? 0;

        return $expires_in != -1 && $expires_in < time();
    }

    private function isValid(string $expires_key = 'expires_in', string $file = ''): bool {
        if ($this->isExpired($expires_key, $file))
            return false;

        $file = $this->dir.'/'.($file === '' ? $this->file : $file);
        
        return is_file($file);
    }

    public function initDir(bool $base_dir = false): void {
        static $initialized_dirs = [];

        $dir = $base_dir ? $this->base_dir : $this->dir;

        if (!isset($initialized_dirs[$dir])) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);

                if (!is_dir($dir))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            $initialized_dirs[$dir] = true;
        }
    }
}
