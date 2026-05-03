<?php

namespace ST_system\Cache\Drivers;

use ST_system\Cache\CacheDriver;
use ST_system\Main;

class FileSystemCacheDriver extends CacheDriver {

    protected static function getDefaultConfig(): array {
        return [
            'dir'  => '~/cache/',
            'file' => 'data',
            'ttl'  => 3600,
        ];
    }

    protected function __init(array $config): void {
        $this->attributes['base_dir'] = Main::preparePath($config['dir'], 3);
        $this->attributes['dir']      = $this->attributes['base_dir'].'/'.$this->id;
    }

    protected function __rebind(array $override): void {
        if (isset($override['dir']) && $override['dir'] !== '' && $override['dir'] !== null)
            $this->attributes['base_dir'] = Main::preparePath($override['dir'], 3);
        $this->attributes['dir'] = $this->attributes['base_dir'].'/'.$this->id;
    }

    public function isAvailable(): bool {
        return $this->initDir($this->attributes['base_dir']);
    }

    protected function getFileAttribute(): string {
        return $this->attributes['dir'].'/'.$this->attributes['file'];
    }

    protected function getMetaAttribute(): string {
        return $this->getFileAttribute().'.meta';
    }

    private function initDir(string $dir): bool {
        static $initialized = [];
        if (isset($initialized[$dir])) return $initialized[$dir];
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $initialized[$dir] = is_dir($dir) && is_writable($dir);
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->initDir($this->attributes['dir']);
        @file_put_contents($this->attributes['dir'].'/'.$file, $payload, LOCK_EX);
    }

    protected function readBlob(string $file): ?string {
        $path = $this->attributes['dir'].'/'.$file;
        if (!is_file($path)) return null;

        $lock = fopen($path.'.lock', 'c+');
        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$path}.lock");

        try {
            if (!flock($lock, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$path}.lock");

            $content = @file_get_contents($path);
            return $content === false ? null : (string)$content;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($path.'.lock');
        }
    }

    protected function writeMeta(string $file, array $meta): void {
        $this->initDir($this->attributes['dir']);
        $path = $this->attributes['dir'].'/'.$file.'.meta';

        $lock = fopen($path.'.lock', 'c+');
        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$path}.lock");

        try {
            if (!flock($lock, LOCK_EX))
                throw new \RuntimeException("Cannot acquire exclusive lock on {$path}.lock");

            $tmp  = $path.'.tmp.'.bin2hex(random_bytes(3));
            $json = @json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false)
                throw new \RuntimeException("Failed to encode JSON for meta");

            if (@file_put_contents($tmp, $json) === false)
                throw new \RuntimeException("Failed to write temp meta file {$tmp}");

            if (!rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("Failed to rename {$tmp} to {$path}");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($path.'.lock');
        }
    }

    protected function readMeta(string $file): ?array {
        $path = $this->attributes['dir'].'/'.$file.'.meta';
        if (!is_file($path)) return null;

        $lock = fopen($path.'.lock', 'c+');
        if ($lock === false) throw new \RuntimeException("Cannot open lock file {$path}.lock");

        try {
            if (!flock($lock, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$path}.lock");

            $content = @file_get_contents($path);
            if ($content === false) return null;

            $decoded = @json_decode($content, true);
            return is_array($decoded) ? $decoded : null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($path.'.lock');
        }
    }

    protected function blobExists(string $file): bool {
        return is_file($this->attributes['dir'].'/'.$file);
    }

    protected function purgeStorage(): void {
        if (!is_dir($this->attributes['dir'])) return;
        $this->purgeDirectory($this->attributes['dir']);
        @rmdir($this->attributes['dir']);
    }

    protected function purgeBaseStorage(): void {
        if (!is_dir($this->attributes['base_dir'])) return;
        $this->purgeDirectory($this->attributes['base_dir']);
    }

    private function purgeDirectory(string $dir): void {
        $it    = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        $lock_file = $dir.'/'.'.lock';
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
}
