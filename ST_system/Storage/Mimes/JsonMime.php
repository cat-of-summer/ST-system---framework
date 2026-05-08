<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;

class JsonMime extends Mime {

    public function get($data) { return @json_decode($data, true); }
    public function set($data, int &$flags = 0) { return @json_encode($data); }

    public function toHTML(array $config = []): string {
        return "<script type='application/json'>{$this->file->getRaw()}</script>";
    }
}
