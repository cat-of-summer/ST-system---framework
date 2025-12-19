<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\File;

class Mime {

    protected File $file;

    protected function __init(): void {}

    public function get($data) { return $data; }
    public function set($data, int &$flags = 0) { return $data; }

    final public function __construct(File $file) {
        $this->file = $file;

        $this->__init();
    }

    public function toHTML(array $config = []): string { return ''; }
}