<?php

namespace ST_system\Storage\Mimes\Traits;

trait Parsable {

    /** Разобранное представление данных через get(); без аргумента — из файла. */
    public function parse($data = null) {
        return $this->get($data ?? ($this->file ? $this->file->getRaw() : null));
    }
}
