<?php
namespace ST_system\Storage\Mimes\Traits;

use ST_system\Storage\File;

trait Combinable {

    final public function combine(array $files, array $config = []): File {
        $paths = array_map(fn($f) => $f->getPathname(), $files);
        $key   = md5(implode("\n", $paths));
        $ext   = $this->__combineExtension();

        $cache = $this->cache->make($this->file->getPathname(), [
            'file' => $key.'.combined.'.$ext
        ]);

        $latest = 0;
        foreach ($files as $f) $latest = max($latest, (int)@filemtime((string)$f->getRealPath()));
        $current = is_file($cache->file) ? (int)filemtime($cache->file) : 0;

        if (($config['force'] ?? false) || $current < $latest)
            $cache->set($this->__combine($files, $config));

        return File::make($cache->file);
    }

    protected function __combineExtension(): string {
        return $this->file->getExtension();
    }

    abstract protected function __combine(array $files, array $config): string;
}
