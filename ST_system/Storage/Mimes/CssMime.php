<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Minifiable;

class CssMime extends Mime {

    use Minifiable;
    
    public function toHTML(array $config = []): string {
        $type = $config['type'] ?? 'text/css';
        $media = $config['media'] ? "media='{$config['media']}'" : '';

        return "<link rel='stylesheet' href='{$this->file->getRelativePath()}' type='{$type}' $media>";
    }

    public function __minify(string $content, array $config): string {
        $content = preg_replace('!/\*[\s\S]*?\*/!', '', $content);
        $content = preg_replace('/\s*([{};:>,+~])\s*/', '$1', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/;}/', '}', $content);
        $content = preg_replace('/:\s+/', ':', $content);

        return trim($content);
    }
}