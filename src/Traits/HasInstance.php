<?php

namespace ST_system\Traits;

trait HasInstance {

    private static function getInstance(...$args): self {
        static $instance = null;

        if ($instance === null)
            $instance = new static(...$args);

        return $instance;
    }
}
