<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Minifiable;
use ST_system\Storage\Mimes\Traits\Combinable;

class CssMime extends Mime {

    use Minifiable;
    use Combinable;

    public function toHTML(array $config = []): string {
        $type = $config['type'] ?? 'text/css';
        $media = $config['media'] ? "media='{$config['media']}'" : '';

        return "<link rel='stylesheet' href='{$this->file->getRelativePath()}' type='{$type}' $media>";
    }

    public static function __minify(string $content, array $config): string {
        $content = preg_replace('!/\*[\s\S]*?\*/!', '', $content);
        $content = preg_replace('/\s*([{};:>,+~])\s*/', '$1', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/;}/', '}', $content);
        $content = preg_replace('/:\s+/', ':', $content);

        return trim($content);
    }

    protected function __combine(array $files, array $config): string {
        return implode("\n", array_map(fn($f) => $f->getRaw(), $files));
    }

    protected function __combineExtension(): string {
        return 'css';
    }
}
