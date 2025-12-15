<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;

class DefaultMime extends Mime {
    public function toHTML(array $config = []): string {
        $text = $config['text'] ?? 'Перейти';
        $target = $config['target'] ? "target='{$config['target']}'" : '';

        return "<a href='{$this->file->getRelativePath()}' $target>{$text}</a>";
    }
}