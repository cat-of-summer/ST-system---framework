<?php

namespace ST_system;

class st_debug {

    private static $password = 'Y';

    public static function get_timestamp() {
        return date("d-m-Y H:i:s");
    }

    public static function prepare_output($content) {
        $output = print_r($content, true);
        $output = '<pre>'.self::get_timestamp().PHP_EOL.$output.'</pre>';

        return $output;
    }

    private static function prepare_dir_path($dir_path) {
        $dir_path = $_SERVER["DOCUMENT_ROOT"].'/'.$dir_path;

        return $dir_path;
    }

    private static function prepare_full_path($full_path) {
        $full_path = str_replace('//', '/', $full_path);

        return $full_path;
    }

    private static function prepare_file_name($file_name, $add_timestamp = false) {
        $last_slash_position = strrpos($file_name, '/');
        $last_dot_position = strrpos($file_name, '.', $last_slash_position);

        if (!$last_dot_position)
            $file_name .= '.html';

        if ($add_timestamp) {
            $last_dot_position = strrpos($file_name, '.', $last_slash_position);
            $file_name = substr_replace($file_name, '_'.self::get_timestamp().'.', $last_dot_position, 1);
        }

        return $file_name;
    }

    public static function dumpToFile($content,
                                      $file_name_from_dir_path = 'log',
                                      $dir_path_from_document_root = 'logs',
                                      $add_timestamp = false,
                                      $need_to_append = false) {
        
        if ($add_timestamp)
            $need_to_append = true;

        $output = self::prepare_output($content);
        $file_name = self::prepare_file_name($file_name_from_dir_path, $add_timestamp);
        $dir_path = self::prepare_dir_path($dir_path_from_document_root);

		if (!is_dir($dir_path)) 
			mkdir($dir_path, 0777, true);

		$full_path = self::prepare_full_path($dir_path.'/'.$file_name);

		return file_put_contents($full_path, $output, !$need_to_append ?: FILE_APPEND);
    }

    public static function dumpToEmail($content, 
                                       $emailTo, 
                                       $subject = 'dumpToEmail_result') {

        $output = self::prepare_output($content);

        return mail($emailTo, $subject, $output);
    }

    public static function dumpHere($content, $dont_echo = false) {
        $output = self::prepare_output($content);

        if ($dont_echo) 
            return $output;
        
        echo $output;
        return true;
    }

    public static function setGetPassword($password_name = 'test', $password = null) {
        if (!isset($_GET[$password_name])) die();
        if ($password === null) $password = self::$password;
        if ($_GET[$password_name] != $password) die();

        return true;
    }
}