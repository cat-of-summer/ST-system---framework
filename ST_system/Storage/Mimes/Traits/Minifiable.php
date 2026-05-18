<?php
namespace ST_system\Storage\Mimes\Traits;

use ST_system\Storage\File;
use ST_system\Main;
use ST_system\Cache\Manager as Cache;
use ST_system\Traits\HasConfig;

trait Minifiable {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return ['cache_dir' => ''];
    }

    private Cache $cache;
    private bool $is_minified = false;

    protected function __init(): void {
        $this->cache = Cache::make($this->file->getPathname(), [
            'driver' => 'filesystem',
            'dir' => static::config('cache_dir') ?: File::config('cache.dir'),
            'file' => $this->file->getFilename(),
            'ttl' => -1
        ]);
    }

    final public function minify(array $config = []): File {
        $instance = $this->file->is_uri
            ? $this->file->fetch()
            : $this->file;

        if (!$instance->exists())
            throw new \InvalidArgumentException("File not found: {$instance->getPathname()}");

        if ($instance->is_minified)
            return $instance;

        $cache = $this->cache->make($instance->getOriginal(true)->getPathname(), [
            'file' => $instance->getBasename().'.min.'.$instance->getExtension()
        ]);

        if (($config['force'] ?? false) || !is_file($cache->file) || $cache->getMeta()['modified_at'] < $cache->getMeta($instance->getFilename())['modified_at'])
            $cache->set($this->__minify($raw = $instance->getRaw(), $config) ?: $raw);

        return $instance->make($cache->file);
    }
}
