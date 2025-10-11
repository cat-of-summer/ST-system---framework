<?php

namespace ST_system;

final class Main {

    public static function get_timestamp($format = null) { //"d-m-Y H:i:s"
        $microtime = microtime(true);

        if ($format === null)
            return $microtime;

        $DateTime = new \DateTime();
        $DateTime->setTimestamp((int)$microtime);

        return $DateTime->format($format);
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

    public static function prepare_params(array $config, array &$data): array {

        $result = [];
        foreach ($config as $key => $rule) {
            [$default, $rule, $convert] = array_pad($rule, 3, null);

            $val = $data[$key] ?? $default;

            if (is_callable($rule) && !call_user_func($rule, $val, $result))
                $val = $default;
            
            if ($val instanceof \Throwable)
                throw $val;

            if ($val === null)
                continue;
            
            if (is_callable($convert) && ($v = call_user_func($convert, $val, $result)))
                $val = $v;
                        
            $result[$key] = $val;
        }
        
        $data = $result;

        return $data;
    }
}