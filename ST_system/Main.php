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
    
}