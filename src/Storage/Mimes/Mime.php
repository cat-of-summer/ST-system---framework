<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\File;

class Mime {

    protected File $file;

    protected function __init(): void {}

    public function get($data) { return $data; }
    public function set($data, int &$flags = 0) { return $data; }

    public function purge(bool $storage = true): void {
        if (isset($this->cache)) $this->cache->purge($storage);
    }

    final public function __construct(File $file) {
        $this->file = $file;

        $this->__init();
    }

    final static function getAttrString(array $attrs): string {
        return implode(' ', array_filter(array_map(
            fn($k, $v) => $v === true ? $k : ($v === false || $v === null ? null : $k.'="'.htmlspecialchars(is_array($v) ? implode(' ', $v) : (string)$v, ENT_QUOTES).'"'),
            array_keys($attrs),
            $attrs
        )));
    }

    public function toHTML(array $config = []): string { return ''; }
}
