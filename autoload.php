<?php

spl_autoload_register(function (string $class_name) {
    $prefix = 'ST_system\\';

    if (strncmp($class_name, $prefix, strlen($prefix)) !== 0) return;

    $path = __DIR__.'/src/'.str_replace('\\', '/', substr($class_name, strlen($prefix))).'.php';

    if (file_exists($path)) require_once $path;
});
