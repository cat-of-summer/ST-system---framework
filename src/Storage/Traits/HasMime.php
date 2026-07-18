<?php

namespace ST_system\Storage\Traits;

trait HasMime {

    private array $detected_mime = [];

    protected function detectMime(string $path): string {
        if ($path === '' || !is_file($path)) return '';

        $key = $path.':'.@filemtime($path);

        if (isset($this->detected_mime[$key]))
            return $this->detected_mime[$key];

        $mime = '';

        if (function_exists('finfo_open')) {

            static $finfo = null;
            if ($finfo === null) $finfo = finfo_open(FILEINFO_MIME_TYPE) ?: false;

            if ($finfo !== false)
                $mime = @finfo_file($finfo, $path);
        }

        if ($mime == '')
            $mime = @mime_content_type($path) ?: '';

        return $this->detected_mime[$key] = (string)$mime;
    }
}
