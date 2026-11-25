<?php

namespace ST_system\Storage\Mimes\Traits;

use ST_system\Storage\File;
use ST_system\Cache\CacheManager;
use ST_system\Traits\HasConfig;

trait Minifiable {

    use HasConfig;

    protected static function getDefaultConfig(): array {
        return ['cache_dir' => File::config('cache.dir')];
    }

    protected CacheManager $cache;
    private bool $is_minified = false;

    /** Алгоритм минификации формата. Статический: им пользуются и minify(), и minifyString(). */
    abstract public static function __minify(string $content, array $config): string;

    /**
     * Минификация строки, у которой нет файла: код, который механизмы собирают на лету и
     * встраивают в страницу (<script>/<style> капчи). Результат кешируется по содержимому:
     * тот же код на следующем запросе не разбирается заново.
     */
    final public static function minifyString(string $content, array $config = []): string {
        if (trim($content) === '') return $content;

        $cache = CacheManager::make(static::class.':'.md5($content).':'.md5(serialize($config)), [
            'driver' => 'filesystem',
            'dir'    => static::config('cache_dir'),
            'file'   => 'inline.min',
            'ttl'    => -1
        ]);

        return (string)$cache->remember(fn() => static::__minify($content, $config) ?: $content);
    }

    protected function __init(): void {
        $this->cache = CacheManager::make($this->file->getPathname(), [
            'driver' => 'filesystem',
            'dir' => static::config('cache_dir'),
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

        if (($config['force'] ?? false) || !is_file($cache->file) || ($cache->getMeta()['stamp'] ?? null) !== $instance->mtime)
            $cache->set($this->__minify($raw = $instance->getRaw(), $config) ?: $raw, 0, '', ['stamp' => $instance->mtime]);

        return $instance->make($cache->file);
    }
}
