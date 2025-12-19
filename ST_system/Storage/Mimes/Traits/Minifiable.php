<?php
namespace ST_system\Storage\Mimes\Traits;

use ST_system\Traits\HasConfig;
use ST_system\Storage\File;

trait Minifiable {

    use HasConfig;

    protected static array $CONFIG = [];
    private bool $is_minified = false;

    protected function __init(): void {
        static $is_cache_init = false;

        if (!$is_cache_init) {
            static::set_config([
                'cache_dir' => File::prepare_path(rtrim(File::config('cache_dir'), '/').'/minified_cache/')
            ]);

            if (!is_dir(static::config('cache_dir'))) {
                @mkdir(static::config('cache_dir'), 0775, true);

                if (!is_dir(static::config('cache_dir')))
                    throw new \RuntimeException("Cannot create cache directory");
            }

            $is_cache_init = true;
        }
    }

    final public function minify(array $config = []): File {
        $instance = $this->file->isUri()
            ? $this->file->fetch()
            : $this->file;

        if ($instance->is_minified)
            return $instance;

        $cache_directory = static::config('cache_dir').'/'.md5($instance->getPathname()).'/';
        
        if (!is_dir($cache_directory)) {
            mkdir($cache_directory, 0775, true);

            if (!is_dir($cache_directory))
                throw new \RuntimeException("Cannot create cache directory");
        }

        $new_instance = $instance->make($cache_directory.$instance->getFilename());

        if (($config['force'] ?? false) || !$new_instance->isFile()) {
            $content = $this->__minify($instance->getRaw(), $config);

            $new_instance->putContents($content);
        }

        return $new_instance;
    }

    abstract public function __minify(string $content, array $config): string;
}