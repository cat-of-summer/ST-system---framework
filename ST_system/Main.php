<?php

namespace ST_system;

final class Main {

    public static function timestamp(string $format = '') {
        $now = time();
        $ts  = function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);

        if ($format) {
            $dt = (new \DateTime())->setTimestamp($now);
            $ts = $dt->format($format) . substr((string)$ts, strpos((string)$ts, '.'));
        }

        return $ts;
    }

    public static function plural_form($n, $forms) { //(1, ["яблоко", "яблока", "яблок"]);
        $n = (int)$n;
        return $forms[($n % 10 == 1 && $n % 100 != 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2)];
    }
    
    public static function merge(...$arrays) {
        $result = [];
        foreach ($arrays as $array)
            $result = self::_merge($result, $array);
    
        return $result;
    }
    
    private static function _merge($array1, $array2) {

        if (is_callable($array1) && is_callable($array2))
            return fn() => self::_merge($array1(), $array2());

        foreach ($array2 as $key => $value) {
            if (
                array_key_exists($key, $array1) &&
                is_array($array1[$key]) &&
                is_array($value)
            )
                $array1[$key] = self::_merge($array1[$key], $value);
            elseif (
                array_key_exists($key, $array1) &&
                is_callable($array1[$key]) &&
                is_callable($value)
            ) {
                $array1[$key] = fn(...$ARGS) => self::_merge($array1[$key](...$ARGS), $value(...$ARGS));
            }
            else
                $array1[$key] = $value;
        }
    
        return $array1;
    }

    public static function serialize($value) {
        $visited = [];
        return static::_serialize($value, $visited);
    }

    private static function _serialize($value, array &$visited = []): string {
        switch (true) {
            case is_string($value):
                return 's:'.$value;
            break;
            case is_int($value):
                return 'i:'.(string)$value;
            break;
            case is_float($value):
                return 'd:'.sprintf('%.14G', $value);
            break;
            case is_bool($value):
                return 'b:'.($value ? '1' : '0');
            break;
            case $value === null:
                return 'n';
            break;
            case is_array($value):
                $is_list = true; $i = 0;
                foreach ($value as $key => $_)
                    if ($key !== $i++) {
                        $is_list = false;
                        break;
                    }
                
                if ($is_list) {
                    $parts = array_map(function($v) use (&$visited) { return static::_serialize($v, $visited); }, $value);
                    sort($parts, SORT_STRING);
                    return 'l:['.implode(',', $parts).']';
                } else {
                    $keys = array_keys($value);
                    usort($keys, function($a, $b) {
                        $ka = is_int($a) ? 'i:' . $a : 's:' . $a;
                        $kb = is_int($b) ? 'i:' . $b : 's:' . $b;
                        if ($ka === $kb) return 0;
                        return ($ka < $kb) ? -1 : 1;
                    });
                    return 'a:{'.implode(',', array_map(function($k) use (&$visited, $value) { return (is_int($k) ? 'ki:' : 'ks:').$k.'='.static::_serialize($value[$k], $visited); }, $keys )).'}';
                }
            break;
            case is_object($value):
                $id = spl_object_hash($value);

                if (isset($visited[$id]))
                    throw new \RuntimeException('Detected cyclic reference in object graph — not supported.');
                
                $visited[$id] = true;

                $result = 'o:'.get_class($value).':'.static::_serialize($value instanceof \JsonSerializable
                        ? $value->jsonSerialize()
                        : (array)$value,
                    $visited
                );

                unset($visited[$id]);

                return $result;
            break;
            default:
                throw new \RuntimeException('Unsupported type in serialize');
        }
    }

    public static function prepare_path(string $path): string {
        if (strpos($path, '~') === 0)
            $path = $_SERVER['DOCUMENT_ROOT'].'/'.trim($path, '/~');
        elseif (strpos($path, '/') !== 0)
            $path = __DIR__.'/'.trim($path, '/');
        
        $path = rtrim($path, '/');

        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
    
            if ($segment === '..') {
                array_pop($stack);
                continue;
            }

            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }
}