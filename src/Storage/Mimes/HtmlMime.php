<?php

namespace ST_system\Storage\Mimes;

use ST_system\Storage\Mimes\Mime;
use ST_system\Storage\Mimes\Traits\Extractable;

class HtmlMime extends Mime {

    use Extractable;

    public function get($data) {
        return (string)$data;
    }

    protected function loadDom(string $html): \DOMDocument {
        $dom = new \DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        ));
        libxml_clear_errors();

        return $dom;
    }

    public function purge(bool $storage = true): void {
        $this->purgeDom();
        parent::purge($storage);
    }
}
