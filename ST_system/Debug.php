<?

namespace ST_system;

class Debug {

    public static $DateTimeFormat = 'd-m-Y H:i:s';
    public static $DateTimeFileFormat = 'd-m-Y~H-i-s';
    public static $default_dir_path = 'logs';
    public static $default_file_name = 'log.html';
    public static $dump_method = 'json_encode'; //'var_dump', 'print_r', 'var_export', 'json_encode'

    private static $dump_call_counter = [];
    private static array $timers = [];

    private static function get_output($content, $add_tree_backtrace) {
        $timestamp_value = microtime(true);
        
        ob_start();
        switch (self::$dump_method) {
            case 'print_r':
                print_r($content);
                break;
            case 'var_export':
                var_export($content);
                break;
            case 'var_dump':
                var_dump($content);
                break;
            default:
                print json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
        }
        $output = ob_get_clean();
        
        $DateTime = new \DateTime();
        $DateTime->setTimestamp((int)$timestamp_value);
        
        $timestamp = $DateTime->format(self::$DateTimeFormat).strstr((string)$timestamp_value, '.', false);
        $backtrace = $add_tree_backtrace ? self::get_backtrace(['skip_start' => 1]) : self::get_backtrace(['skip_start' => 1, 'chain' => false]) ;

        return '<pre>'.PHP_EOL.$timestamp.PHP_EOL.$backtrace.$output.PHP_EOL.'</pre>';
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

    public static function dump_throw($content, $PARAMS = []) {
        /*
            [
                'add_backtrace' => false
            ]
        */

        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        throw new \Exception($output);
        exit;
    }

    public static function dump_here($content, $PARAMS = []) {
        /*
            [
                'print' => true,
                'add_backtrace' => false
            ]
        */

        $to_print = isset($PARAMS['print']) ? (bool)$PARAMS['print'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        if ($to_print) 
            print $output;
        
        return $output;
    }

    public static function dump_to_console($content, $PARAMS = []) {
        /*
            [
                'print' => true,
                'add_backtrace' => false
            ]
        */

        $to_print = isset($PARAMS['print']) ? (bool)$PARAMS['print'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        if ($to_print) 
            print "<script>console.log('{$output}')</script>";
        
        return $output;
    }

    public static function dump_to_file($content, $PARAMS = []) {
        /*
            [
                'file_name' => self::$default_file_name, //log.txt, log, log.html
                'dir_path' => self::$default_dir_path,
                'merge_dumps' => true,
                'add_backtrace' => false,
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
                $file_name = substr_replace($file_name, '_'. date(self::$DateTimeFileFormat).'.', $last_dot_position, 1);
            }

            return $file_name;
        };

        $file_name_from_dir_path = isset($PARAMS['file_name']) ? htmlspecialchars($PARAMS['file_name']) : self::$default_file_name;
        $dir_path_from_document_root = isset($PARAMS['dir_path']) ? htmlspecialchars($PARAMS['dir_path']) : self::$default_dir_path;
        $merge_dumps_in_one_file = isset($PARAMS['merge_dumps']) ? (bool)$PARAMS['merge_dumps'] : true;
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;
        $add_timestamp_to_name = isset($PARAMS['add_timestamp']) ? (bool)$PARAMS['add_timestamp'] : false;
        $need_to_append_files = isset($PARAMS['append']) ? (bool)$PARAMS['append'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);
        $file_name = $prepare_file_name_func($file_name_from_dir_path, $add_timestamp_to_name);
        $dir_path = $_SERVER["DOCUMENT_ROOT"].'/'.$dir_path_from_document_root;
        $full_path = str_replace('//', '/', $dir_path.'/'.$file_name);

        if (!is_dir($dir_path)) mkdir($dir_path, 0777, true);

    if (self::$dump_call_counter[$full_path])
        self::$dump_call_counter[$full_path]++;
    else
        self::$dump_call_counter[$full_path] = 1;

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
                'add_backtrace' => false
            ]
        */

        $email_to = isset($PARAMS['to']) ? htmlspecialchars($PARAMS['to']) : null;
        $subject = isset($PARAMS['subject']) ? htmlspecialchars($PARAMS['subject']) : 'dump_to_email_log';
        $add_tree_backtrace_to_content = isset($PARAMS['add_backtrace']) ? (bool)$PARAMS['add_backtrace'] : false;

        $output = self::get_output($content, $add_tree_backtrace_to_content);

        return mail($email_to, $subject, $output);
    }

    public static function start_timer(string $name = 'default') {
        
        self::$timers[$name] = function_exists('hrtime')
            ? hrtime(true)
            : microtime(true);
    }

    public static function end_timer(string $name = 'default') {
        if (!isset(self::$timers[$name]))
            throw new \InvalidArgumentException("Timer '$name' was not started.");
        
        $result = function_exists('hrtime')
            ? (hrtime(true) - self::$timers[$name]) / 1e9
            : microtime(true) - self::$timers[$name];

        unset(self::$timers[$name]);

        return $result;
    }

    public static function benchmark(callable $job, int $iterations = 10, int $warmup = 0) {
        for ($w = 0; $w < $warmup; $w++)
            $job();
        
        $durations = [];
        for ($i = 0; $i < $iterations; $i++) {
            self::start_timer("default#{$i}");
                $job();
            $sec = self::end_timer("default#{$i}");

            $durations[] = $sec;
        }

        $total = array_sum($durations);
        $avg = $iterations ? $total / $iterations : 0.0;

        $sorted = $durations;
        sort($sorted, SORT_NUMERIC);

        $mid = (int)floor($iterations / 2);
        if ($iterations % 2 === 0)
            $median = ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        else
            $median = $sorted[$mid];
    
        $min = $sorted[0] ?? 0.0;
        $max = $sorted[$iterations - 1] ?? 0.0;

        return [
            'durations' => $durations,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'avg' => $avg,
            'median' => $median,
            'min' => $min,
            'max' => $max,
            'total' => $total,
            'unit' => 's',
        ];
    }

}