<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Parsable;

class TextPlainMime extends Mime {

    use Parsable;

    public function toHTML(array $config = []): string { return $this->file->getContents(); }
}
