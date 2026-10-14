<?php

namespace ST_system\Captcha\Drivers\Game;

use ST_system\Storage\File;

/**
 * Картинки для игр из ключей конфига `images`, `extensions`, `max_images`: файлы, каталоги
 * (рекурсивно), URL. Общее у пазла и Memory.
 */
trait ImageSources {

    private static function mime(string $bytes): string {
        if (strncmp($bytes, "\xFF\xD8", 2) === 0)                                  return 'image/jpeg';
        if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';

        return 'image/png';
    }

    /** Картинка как data URI — так её получает браузер. */
    private static function dataUri(string $bytes): string {
        return 'data:'.self::mime($bytes).';base64,'.base64_encode($bytes);
    }

    /** Локальный путь картинки: URL скачивается (и кешируется) через File::fetch(). */
    private static function local(File $file): string {
        return $file->is_uri ? $file->fetch()->getPathname() : $file->getPathname();
    }

    /** File, путь к файлу или каталогу, URL — по одному или списком. */
    private function resolveImages($value): array {
        $images = [];
        $paths  = [];

        foreach (is_array($value) ? $value : [$value] as $source) {
            if ($source instanceof File) {
                $images[] = $source;
                continue;
            }

            $source = trim((string)$source);
            if ($source === '') continue;

            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $images[] = File::make($source);
                continue;
            }

            $absolute = strpos($source, '/') === 0
                || strpos($source, '~') === 0
                || preg_match('#^[a-zA-Z]:[\\\\/]#', $source);

            $paths[] = $absolute ? $source : '~/'.ltrim($source, '/\\');
        }

        if ($paths) {
            $extensions = [];
            foreach ((array)$this->attributes['extensions'] as $ext) {
                $extensions[] = strtolower($ext);
                $extensions[] = strtoupper($ext);
            }

            foreach (File::find($paths, [
                'extension' => array_values(array_unique($extensions)),
                'recursive' => true,
                'max_files' => (int)$this->attributes['max_images'],
            ]) as $file)
                if ($file instanceof File) $images[] = $file;
        }

        return array_slice($images, 0, (int)$this->attributes['max_images']);
    }
}
