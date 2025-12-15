<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\File;

abstract class Mime {

    protected File $file;

    protected function __init(): void {}

    final public function __construct(File $file) {
        $this->file = $file;

        $this->__init();
    }

    abstract public function toHTML(array $config = []): string;
}