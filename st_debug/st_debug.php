<?php

namespace ST_system;

class Debug {
    
    public static function get_timestamp($format = null) { //"d-m-Y H:i:s"
        $microtime = microtime(true);

        if ($format === null)
            return $microtime;

        $DateTime = new DateTime();
        $DateTime->setTimestamp($microtime);

        return $DateTime->format($format);
    }

    public static class Dump {

        private static $DateTimeFormat = 'd-m-Y H:i:s';
        private static $FileExtension = '.html';

        private static function get_output($content) {
            $timestamp = Debug::get_timestamp();
            
            ob_start();
            var_dump($content);
            $output = ob_get_clean();

            $DateTime = new DateTime();
            $DateTime->setTimestamp($timestamp);
            $ms = (int)(($timestamp - floor($timestamp)) * 1000);
            $mcs = sprintf("%03d", ($timestamp - floor($timestamp)) * 1000000);

            return '<pre>'.$DateTime->format(self::$DateTimeFormat).':'.$ms.':'.$mcs.PHP_EOL.$output.'</pre>';
        }

        private static function get_dir_path($dir_path) {
            return $_SERVER["DOCUMENT_ROOT"].'/'.$dir_path;
        }

        private static function get_full_path($full_path) {
            return str_replace('//', '/', $full_path);
        }

        private static function get_file_name($file_name, $add_timestamp) {
            $last_slash_position = strrpos($file_name, '/');
            $last_dot_position = strrpos($file_name, '.', $last_slash_position);
    
            if (!$last_dot_position)
                $file_name .= self::$FileExtension;
    
            if ($add_timestamp) {
                $last_dot_position = strrpos($file_name, '.', $last_slash_position);
                $file_name = substr_replace($file_name, '_'.Debug::get_timestamp(self::$DateTimeFormat).'.', $last_dot_position, 1);
            }
    
            return $file_name;
        }
        
        /*
            [
                'content' => null,
                'dont_echo' => false
            ]
        */
        public static function here($PARAMS = []) {

            $content = isset($PARAMS['content']) ? $PARAMS['content'] : null;
            $dont_echo = isset($PARAMS['dont_echo']) ? (bool)$PARAMS['dont_echo'] : false;

            $output = self::get_output($content);
    
            if ($dont_echo) 
                return $output;
            
            echo $output;
            return true;
        }

        /*
            [
                'content' => null,
                'file_name' => 'log',
                'dir_path' => 'logs',
                'all_to_one_file' => true,
                'add_timestamp' => false,
                'append' => true,
            ]
        */
        public static function to_file($PARAMS = []) {

            $content = isset($PARAMS['content']) ? $PARAMS['content'] : null;
            $file_name_from_dir_path = isset($PARAMS['file_name']) ? htmlspecialchars($PARAMS['file_name']) : 'log';
            $dir_path_from_document_root = isset($PARAMS['dir_path']) ? htmlspecialchars($PARAMS['dir_path']) : 'logs';

            $all_dumps_to_one_file = isset($PARAMS['all_to_one_file']) ? (bool)$PARAMS['all_to_one_file'] : true; 
            $add_timestamp_to_name = $all_dumps_to_one_file ? false : isset($PARAMS['add_timestamp']) ? (bool)$PARAMS['add_timestamp'] : false;
            $need_to_append_file = $add_timestamp_to_name ? false : isset($PARAMS['append']) ? (bool)$PARAMS['append'] : true;

            $output = self::get_output($content);
            $file_name = self::get_file_name($file_name_from_dir_path, $add_timestamp_to_name);
            $dir_path = self::get_dir_path($dir_path_from_document_root);

            if (!is_dir($dir_path)) mkdir($dir_path, 0777, true);

            $full_path = self::get_full_path($dir_path.'/'.$file_name);

            return file_put_contents($full_path, $output, !$need_to_append_file ?: FILE_APPEND);
        }

        /*
            [
                'content' => null,
                'email_to' => null,
                'subject' => 'logs'
            ]
        */
        public static function to_email($PARAMS = []) {

            $content = isset($PARAMS['content']) ? $PARAMS['content'] : null;
            $email_to = isset($PARAMS['email_to']) ? htmlspecialchars($PARAMS['email_to']) : null;
            $subject = isset($PARAMS['subject']) ? htmlspecialchars($PARAMS['subject']) : null;

            $output = self::get_output($content);

            return mail($email_to, $subject, $output);
        }

    }

    public static class Access {
        
        private static $PasswordName = 'pass';
        private static $DieFunc = function () {LocalRedirect("/");}

        private static $ExistingTransmissionMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

        /*
            [
                'name' => self::$PasswordName,
                'value' => Debug::get_timestamp('dmY'),
                'die_func' => self::$DieFunc
            ]
        */
        public static function set_password($PARAMS = []) {

            $password_name = isset($PARAMS['name']) ? htmlspecialchars($PARAMS['name']) : self::$PasswordName;
            $password_value = isset($PARAMS['value']) ? htmlspecialchars($PARAMS['value']) : Debug::get_timestamp('dmY');
            $die_func = (isset($PARAMS['die_func']) && is_callable($PARAMS['die_func'])) ? $PARAMS['die_func'] : self::$DieFunc;

            if (!isset($_REQUEST[$password_name]) || ($_REQUEST[$password_name] != $password_value))
                return $die_func();

        }

    }

}
