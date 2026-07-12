<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;

class TextPlainMime extends Mime {

    public function toHTML(array $config = []): string { return $this->file->getContents(); }
}
