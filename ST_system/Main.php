<?php

namespace ST_system;

class Main {

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
    
    public static function recursive_merge(...$arrays) {
        $result = [];
        foreach ($arrays as $array)
            $result = self::_recursive_merge($result, $array);
    
        return $result;
    }
    
    private static function _recursive_merge(array $array1, array $array2) {
        foreach ($array2 as $key => $value) {
            if (
                array_key_exists($key, $array1) &&
                is_array($array1[$key]) &&
                is_array($value)
            )
                $array1[$key] = self::_recursive_merge($array1[$key], $value);
            else
                $array1[$key] = $value;
        }
    
        return $array1;
    }

}