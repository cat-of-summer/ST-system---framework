<?php

namespace ST_system;

use ST_system\Traits\HasConfig;
use ST_system\Main;

final class Cache {

    use HasConfig;

    private static array $CONFIG = [
        'dir' => '~/cache/',
        'file' => 'data',
        'ttl' => 3600,
        'expires_key' => 'expires_in'
    ];

    private static $INITIALIZED_DIRS = [];

    private string $base_dir;
    private $raw_key;
    private string $dir;
    private string $file;
    private int $ttl;

    public static function __callStatic(string $name, array $args) {
        if ($name === 'make')
            return static::make(...$args);

        if (!in_array($name, [
            'get',
            'set',
            'getMeta',
            'setMeta'
        ], true))
            throw new \BadMethodCallException("Method {$name} not found");
        
        $key = array_shift($args);

        if ((strncmp($name, 'get', 3) === 0))
            [$file, $data, $ttl] = $args;
        else
            [$data, $file, $ttl] = $args;

        return static::make($key, [
            'file' => (string)$file,
            'ttl' => (int)$ttl
        ])->{$name}(...$args);
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
            case 'getMeta':
            case 'setMeta':
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
        $config = array_merge(static::config(), array_filter($config));

        $this->base_dir = Main::prepare_path($config['dir']);
        $this->raw_key = $key;

        $key = md5(Main::serialize($this->raw_key));

        if (!isset(static::$INITIALIZED_DIRS[$this->base_dir])) {
            if (!is_dir($this->base_dir)) {
                @mkdir($this->base_dir, 0775, true);

                if (!is_dir($this->base_dir))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            static::$INITIALIZED_DIRS[$this->base_dir] = true;
        }

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

    private function setMeta(array $data, string $file = '', int $ttl = 0): void {
        if ($file === '')
            $file = $this->file;

        if ($ttl === -1)
            unset($data[static::config('expires_key')]);
        elseif (!isset($data[static::config('expires_key')]) && $ttl > 0)
            $data[static::config('expires_key')] = $ttl + time();

        $data = array_merge($this->getMeta($file), $data);
        $meta = $this->dir.'/'.$file.'.meta';

        if (!isset(static::$INITIALIZED_DIRS[$this->dir])) {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);

                if (!is_dir($this->dir))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            static::$INITIALIZED_DIRS[$this->dir] = true;
        }

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
            'modified_at' => false
        ];

        if ($file === '')
            $file = $this->file;
        
        $meta = $this->dir.'/'.$file.'.meta';
        $filemtime = filemtime($meta);

        if (isset($meta_cache[$meta]) && $meta_cache[$meta]['modified_at'] == $filemtime)
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
            $data['modified_at'] = $filemtime;
            $meta_cache[$meta] = $data;
            
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($meta.'.lock');

            return $data;
        }
    }

    private function set($data, string $file = '', int $ttl = 0): void {
        if ($file === '')
            $file = $this->file;
        
        if ($ttl === 0)
            $ttl = $this->ttl;
        
        if (!isset(static::$INITIALIZED_DIRS[$this->dir])) {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);

                if (!is_dir($this->dir))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            static::$INITIALIZED_DIRS[$this->dir] = true;
        }

        $this->setMeta(['type' => gettype($data)], $file, $ttl);

        if (is_object($data))
            $data = $data instanceof \JsonSerializable
                ? $data->jsonSerialize()
                : (array)$data;
                
        if (is_array($data))
            $data = @json_encode($data);
        
        @file_put_contents($this->dir.'/'.$file, $data, LOCK_EX);
    }

    private function get(string $file = '') {
        if (!is_dir($this->dir))
            return null;

        if ($this->isExpired($file))
            return null;

        $meta = $this->getMeta($file);

        $file = $this->dir.'/'.($file === '' ? $this->file : $file);
        $lock = fopen($file.'.lock', 'c+');

        if ($lock === false)
            throw new \RuntimeException("Cannot open lock file {$file}.lock");
        
        try {
            if (!flock($lock, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$file}.lock");
            
            if (!is_file($file))
                return null;

            if (($content = @file_get_contents($file)) === false)
                return null;

            switch ($meta['type']) {
                case 'array':
                    return is_array($decoded = @json_decode($content, true)) ? $decoded : [];
                case 'object':
                    return is_object($decoded = @json_decode($content, false)) ? $decoded : new \stdClass();
                case 'string':
                    return (string)$content;
                case 'int':
                case 'integer':
                    return (int)$content;
                case 'float':
                case 'double':
                    return (float)$content;
                case 'bool':
                case 'boolean':
                    return (bool)$content;
                default:
                    return $content;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($file.'.lock');
        }
    }

    private function isExpired(string $file = '', string $expires_key = ''): bool {
        if ($expires_key === '')
            $expires_key = static::config('expires_key');

        $meta = $this->getMeta($file);

        return isset($meta[$expires_key]) && $meta[$expires_key] < time();
    }
}
