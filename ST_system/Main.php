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
            $result = self::_merging($result, $array);
    
        return $result;
    }
    
    private static function _merging($array1, $array2) {

        if (is_callable($array1) && is_callable($array2))
            return fn() => self::_merging($array1(), $array2());

        foreach ($array2 as $key => $value) {
            if (
                array_key_exists($key, $array1) &&
                is_array($array1[$key]) &&
                is_array($value)
            )
                $array1[$key] = self::_merging($array1[$key], $value);
            elseif (
                array_key_exists($key, $array1) &&
                is_callable($array1[$key]) &&
                is_callable($value)
            ) {
                $array1[$key] = fn(...$ARGS) => self::_merging($array1[$key](...$ARGS), $value(...$ARGS));
            }
            else
                $array1[$key] = $value;
        }
    
        return $array1;
    }
}