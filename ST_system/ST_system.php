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
}

class Debug {

    public static $DateTimeFormat = 'd-m-Y H:i:s';
    public static $DateTimeFileFormat = 'd-m-Y~H-i-s';

    private static $dump_call_counter = [];

    private static function get_output($content, $add_tree_backtrace) {
        $timestamp_value = Main::get_timestamp();
        
        ob_start();
        var_dump($content);
        $output = ob_get_clean();

        $DateTime = new \DateTime();
        $DateTime->setTimestamp((int)$timestamp_value);
        $ms = (int)(($timestamp_value - floor($timestamp_value)) * 1000);
        $mcs = sprintf("%03d", ($timestamp_value - floor($timestamp_value)) * 1000000);
        
        $timestamp = $DateTime->format(self::$DateTimeFormat).':'.$ms.':'.$mcs;
        $backtrace = $add_tree_backtrace ? self::get_backtrace(['skip_start' => 1]) : self::get_backtrace(['skip_start' => 1, 'chain' => false]) ;

        return '<pre>'.PHP_EOL.$timestamp.PHP_EOL.$backtrace.PHP_EOL.$output.PHP_EOL.'</pre>';
    }
    
    public static function get_backtrace($PARAMS = []) {
        /*
            [
                'chain' => true,
                'skip_start' => 0,
                'skip_end' => 0
            ]
        */

        $get_trace_func = function($trace) {
            $call = isset($trace['class'])
                ? "{$trace['class']}{$trace['type']}{$trace['function']}()"
                : "{$trace['function']}()";

            $file = isset($trace['file']) ? $trace['file'] : '[internal function]';
            $line = isset($trace['line']) ? $trace['line'] : '[unknown line]';

            return "{$call} in {$file} on line {$line}.".PHP_EOL;
        };

        $chain = isset($PARAMS['chain']) ? (bool)$PARAMS['chain'] : true;
        $skip_start = isset($PARAMS['skip_start']) ? (int)$PARAMS['skip_start'] + 1 : 1;
        $skip_end = isset($PARAMS['skip_end']) ? (int)$PARAMS['skip_end'] : 0;

        if ($chain) {
            $full_backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

            $result = "";
            for ($level = $skip_start; $level < count($full_backtrace) - $skip_end; $level++) {
                $indent = str_repeat("    ", $level - $skip_start).'↘ ';

                $result .= $indent.$get_trace_func($full_backtrace[$level]);
            }

        } else {
            $last_caller = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT)[2];

            $result = $get_trace_func($last_caller);
        }

        return $result;
    }

    public static function dump_here($content, $PARAMS = []) {
        /*
            [
                'echo' => true,
                'add_backtrace' => true
            ]
        */

        $echo = isset($PARAMS['echo']) ? (bool)$PARAMS['echo'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : true;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        if ($echo) 
            echo $output;
        
        return $output;
    }

    public static function dump_to_file($content, $PARAMS = []) {
        /*
            [
                'file_name' => 'log', //log.txt
                'dir_path' => 'logs',
                'merge_dumps' => true,
                'add_backtrace' => true,
                'add_timestamp' => false,
                'append' => false,
            ]
        */

        $prepare_file_name_func = function ($file_name, $add_timestamp) {
            $last_slash_position = strrpos($file_name, '/');
            $last_dot_position = strrpos($file_name, '.', $last_slash_position);
    
            if (!$last_dot_position)
                $file_name .= '.html';
    
            if ($add_timestamp) {
                $last_dot_position = strrpos($file_name, '.', $last_slash_position);
                $file_name = substr_replace($file_name, '_'.Main::get_timestamp(self::$DateTimeFileFormat).'.', $last_dot_position, 1);
            }
    
            return $file_name;
        };

        $file_name_from_dir_path = isset($PARAMS['file_name']) ? htmlspecialchars($PARAMS['file_name']) : 'log';
        $dir_path_from_document_root = isset($PARAMS['dir_path']) ? htmlspecialchars($PARAMS['dir_path']) : 'logs';
        $merge_dumps_in_one_file = isset($PARAMS['merge_dumps']) ? (bool)$PARAMS['merge_dumps'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : true;
        $add_timestamp_to_name = isset($PARAMS['add_timestamp']) ? (bool)$PARAMS['add_timestamp'] : false;
        $need_to_append_files = isset($PARAMS['append']) ? (bool)$PARAMS['append'] : false;
    
        $output = self::get_output($content, $add_tree_backtrace_to_content);
        $file_name = $prepare_file_name_func($file_name_from_dir_path, $add_timestamp_to_name);
        $dir_path = $_SERVER["DOCUMENT_ROOT"].'/'.$dir_path_from_document_root;
        $full_path = str_replace('//', '/', $dir_path.'/'.$file_name);

        if (!is_dir($dir_path)) mkdir($dir_path, 0777, true);

        self::$dump_call_counter[$full_path]++;

        $need_to_append_current_file = (self::$dump_call_counter[$full_path] == 1) 
            ? (file_exists($full_path) && $need_to_append_files) 
            : $merge_dumps_in_one_file;

        return file_put_contents($full_path, $output, !$need_to_append_current_file ?: FILE_APPEND);
    }

    public static function dump_to_email($content, $PARAMS = []) {
        /*
            [
                'to' => null,
                'subject' => 'dump_to_email_log',
                'add_backtrace' => true
            ]
        */

        $email_to = isset($PARAMS['to']) ? htmlspecialchars($PARAMS['to']) : null;
        $subject = isset($PARAMS['subject']) ? htmlspecialchars($PARAMS['subject']) : 'dump_to_email_log';
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : true;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        return mail($email_to, $subject, $output);
    }

}

class Access {
        
    private static $PasswordName = 'pass';
    private static $ExistingTransmissionMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

    public static function set_password($PARAMS = []) {
        /*
            [
                'name' => self::$PasswordName,
                'value' => Main::get_timestamp('dm'),
                'die_func' => function () {
                    header("Location: /");
                    exit;
                }
            ]
        */

        $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::$PasswordName;
        $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : Main::get_timestamp('dm');
        $die_func = (isset($PARAMS['die_func']) && is_callable($PARAMS['die_func'])) ? $PARAMS['die_func'] : function () {
            header("Location: /");
            exit;
        };

        if (!isset($_REQUEST[$password_name]) || ($_REQUEST[$password_name] != $password_value))
            return $die_func();

    }

    public static function handle_cors($PARAMS = []) {
        /*
            [
                'origins' => ['*'],
                'methods' => self::$ExistingTransmissionMethods,
                'headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
            ]
        */
        
        $allowed_origins = (isset($PARAMS['origins']) && is_array($PARAMS['origins'])) 
            ? array_map('htmlspecialchars', $PARAMS['origins']) 
            : ['*'];
            
        $allowed_methods = (isset($PARAMS['methods']) && is_array($PARAMS['methods'])) 
            ? array_map('strtoupper', $PARAMS['methods']) 
            : self::$ExistingTransmissionMethods;

        $allowed_headers = (isset($PARAMS['headers']) && is_array($PARAMS['headers'])) 
            ? array_map('htmlspecialchars', $PARAMS['headers']) 
            : ['Content-Type', 'Authorization', 'X-Requested-With'];
        
        header("Access-Control-Allow-Credentials: true");
    
        if (in_array('*', $allowed_origins))
            header("Access-Control-Allow-Origin: *");
        else {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
            if (in_array($origin, $allowed_origins)) 
                header("Access-Control-Allow-Origin: $origin");
        }

        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            
            header("Access-Control-Allow-Methods: " . implode(", ", $allowed_methods));
            header("HTTP/1.1 200 OK");

            exit;
        }
        
        header("Access-Control-Allow-Headers: " . implode(", ", $allowed_headers));
    }
        
}

