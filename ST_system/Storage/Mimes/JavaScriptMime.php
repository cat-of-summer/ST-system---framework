<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;

class JavaScriptMime extends Mime {
    public function toHTML(array $config = []): string {
        $type = $config['type'] ?? 'text/javascript';
        $async = $config['async'] ? 'async' : '';
        $defer = $config['defer'] ? 'defer' : '';
        
        return "<script src='{$this->file->getRelativePath()}' type='{$type}' $async $defer></script>";
    }
}