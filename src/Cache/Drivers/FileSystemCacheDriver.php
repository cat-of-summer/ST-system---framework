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

    private static array $initialized = [];

    public function initDir(string $dir = ''): bool {
        if ($dir === '') $dir = $this->attributes['dir'];

        if (isset(static::$initialized[$dir])) return static::$initialized[$dir];
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return static::$initialized[$dir] = is_dir($dir) && is_writable($dir);
    }

    protected function writeBlob(string $file, string $payload): void {
        $this->initDir($this->attributes['dir']);
        @file_put_contents($this->attributes['dir'].'/'.$file, $payload, LOCK_EX);
    }

    protected function readBlob(string $file): ?string {
        // Читаем под LOCK_SH на самом файле (а не на sidecar .lock): не плодим ФС-записи на
        // каждое чтение и корректно координируемся с LOCK_EX из writeBlob на том же дескрипторе.
        $path = $this->attributes['dir'].'/'.$file;

        $fh = @fopen($path, 'rb');
        if ($fh === false) return null;

        try {
            if (!flock($fh, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$path}");

            $content = stream_get_contents($fh);
            return $content === false ? null : (string)$content;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
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
        // LOCK_SH на самом .meta без sidecar: writeMeta пишет атомарно (tmp+rename), поэтому
        // читатель всегда видит целостный старый-или-новый файл.
        $path = $this->attributes['dir'].'/'.$file.'.meta';

        $fh = @fopen($path, 'rb');
        if ($fh === false) return null;

        try {
            if (!flock($fh, LOCK_SH))
                throw new \RuntimeException("Cannot acquire shared lock on {$path}");

            $content = stream_get_contents($fh);
            if ($content === false) return null;

            $decoded = @json_decode($content, true);
            return is_array($decoded) ? $decoded : null;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    protected function blobExists(string $file): bool {
        return is_file($this->attributes['dir'].'/'.$file);
    }


    private static function purgeInitialized(string $dir): void {
        foreach (array_keys(static::$initialized) as $known)
            if ($known === $dir || strncmp($known, $dir.'/', strlen($dir) + 1) === 0)
                unset(static::$initialized[$known]);
    }

    protected function purgeStorage(): void {
        static::purgeInitialized($this->attributes['dir']);
        if (!is_dir($this->attributes['dir'])) return;
        $this->purgeDirectory($this->attributes['dir']);
        @rmdir($this->attributes['dir']);
    }

    protected function purgeBaseStorage(): void {
        static::purgeInitialized($this->attributes['base_dir']);
        if (!is_dir($this->attributes['base_dir'])) return;
        $this->purgeDirectory($this->attributes['base_dir']);
    }

    protected function purgeExpiredStorage(): void {
        static::purgeInitialized($this->attributes['dir']);
        if (!is_dir($this->attributes['dir'])) return;
        $this->dropExpiredIn($this->attributes['dir']);
        @rmdir($this->attributes['dir']);
    }

    protected function purgeExpiredBaseStorage(): void {
        $base = $this->attributes['base_dir'];
        static::purgeInitialized($base);
        if (!is_dir($base)) return;

        $entries = @scandir($base);
        if (!is_array($entries)) return;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $sub = $base.'/'.$entry;
            if (!is_dir($sub)) continue;
            $this->dropExpiredIn($sub);
            @rmdir($sub);
        }
    }

    private function dropExpiredIn(string $dir): void {
        $metas = @glob(rtrim($dir, '/').'/*.meta');
        if (!is_array($metas)) return;

        $now = time();
        foreach ($metas as $meta_path) {
            $content = @file_get_contents($meta_path);
            if ($content === false) continue;
            $decoded = @json_decode($content, true);
            if (!is_array($decoded)) continue;

            $expires = $decoded['expires_in'] ?? 0;
            if ($expires === -1 || $expires >= $now) continue;

            @unlink(substr($meta_path, 0, -strlen('.meta')));
            @unlink($meta_path);
        }
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
