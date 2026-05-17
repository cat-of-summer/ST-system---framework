<?php
namespace ST_system\Storage\Mimes\Traits;

use ST_system\Storage\File;

trait Combinable {

    final public function combine($files, array $config = []): File {
        $files = $this->__resolveFiles($files);

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

    private function __resolveFiles($input): array {
        $result = [];
        foreach ((array)$input as $item) {
            if ($item instanceof File) { $result[] = $item; continue; }
            if (!is_string($item) || $item === '') continue;

            if (filter_var($item, FILTER_VALIDATE_URL)) {
                $result[] = File::make($item);
                continue;
            }

            $found = $this->file->is_uri
                ? File::find($item)
                : $this->file->find($item);

            $result = array_merge($result, $found ?: [File::make($item)]);
        }
        return $result;
    }

    protected function __combineExtension(): string {
        return $this->file->getExtension();
    }

    abstract protected function __combine(array $files, array $config): string;
}
