<?php
namespace ST_system\Storage\Mimes\Traits;

use ST_system\Storage\File;
use ST_system\Cache;

trait Minifiable {

    private Cache $cache;
    private bool $is_minified = false;

    protected function __init(): void {
        $this->cache = Cache::make($this->file->getPathname(), [
            'dir' => rtrim(File::config('cache.dir'), '/').'/minified_cache/',
            'ttl' => -1
        ]);
    }

    final public function minify(array $config = []): File {
        $instance = $this->file->isUri()
            ? $this->file->fetch()
            : $this->file;

        if ($instance->is_minified)
            return $instance;

        $cache = $this->cache->make('', [
            'file' => $instance->getBasename().'.min.'.$instance->getExtension()
        ]);

        if (($config['force'] ?? false) || !is_file($cache->file))
            $cache->set($this->__minify($instance->getRaw(), $config));
        
        return $instance->make($cache->file);
    }

    abstract public function __minify(string $content, array $config): string;
}