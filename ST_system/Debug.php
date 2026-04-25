<?php

namespace ST_system;

use ST_system\Main;
use ST_system\Traits\HasConfig;

final class Debug {

    use HasConfig;

    protected static array $CONFIG = [
        'timestamp_format_output' => 'd-m-Y H:i:s',
        'timestamp_format_file' => 'd-m-Y~H-i-s',
        'dir' => '~logs',
        'file' => 'log.html',
        'output_type' => 'json_encode'
    ];

    private static array $dumper_counter = [];
    private static array $timers = [];

    public static function backtrace(array $config = []): string {
        $get_trace_func = function($trace) {
            $call = isset($trace['class'])
                ? "{$trace['class']}{$trace['type']}{$trace['function']}()"
                : "{$trace['function']}()";

            $file = isset($trace['file']) ? $trace['file'] : '[internal function]';
            $line = isset($trace['line']) ? $trace['line'] : '[unknown line]';

            return "{$call} in {$file} on line {$line}.\n";
        };

        $config = array_merge(
            [
                'chain' => true,
                'skip_start' => 0,
                'skip_end' => 0
            ],
            $config
        );

        $result = "";
        if ($config['chain']) {
            $full_backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT);

            for ($level = $config['skip_start'] + 2; $level < count($full_backtrace) - $config['skip_end']; $level++)
                $result .= str_repeat("    ", $level - $config['skip_start'] - 2).'↘ '.$get_trace_func($full_backtrace[$level]);
        } else {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT);
            $result = $get_trace_func($bt[min(4, count($bt) - 1)] ?? []);
        }
        
        return $result;
    }

    public static function start(string $name = 'default'): void {
        static::$timers[$name] = Main::timestamp();
    }

    public static function finish(string $name = 'default'): float {
        if (!isset(static::$timers[$name]))
            throw new \InvalidArgumentException("Timer '$name' was not started.");
        
        $result = Main::timestamp() - static::$timers[$name];

        unset(static::$timers[$name]);

        return $result;
    }

    public static function benchmark(callable $job, int $iterations = 10, int $warmup = 0): array {
        for ($w = 0; $w < $warmup; $w++)
            $job();
        
        $timer = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyz'), 0, 8);
        $durations = [];
        for ($i = 0; $i < $iterations; $i++) {
            self::start("{$timer}#{$i}");
                $job();
            $durations[] = self::finish("{$timer}#{$i}");
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

    public static function linter(string $file_path): array {
        static $short_open_tag = null;

        $file_path = Main::preparePath($file_path, 1);

        if (!is_file($file_path)) 
            return [
                'ok' => false,
                'errors' => "The file '{$file_path}' does not exist",
                'result' => '',
                'code' => 1
            ];
        
        try {
            if ($short_open_tag === null)
                $short_open_tag = ini_get('short_open_tag');
    
            @exec('php -d short_open_tag='.$short_open_tag.' -l '.escapeshellarg($file_path).' 2>&1', $output_lines, $exit_code);

            return [
                'ok' => $exit_code == 0,
                'result' => array_pop($output_lines),
                'errors' => $output_lines,
                'code' => $exit_code
            ];
        } catch (\Throwable $th) {
            return [
                'ok' => false,
                'result' => $th->getMessage(),
                'errors' => [$th->getMessage()],
                'code' => -1
            ];
        }
    }

    private array $config = [];

    private function get_output($content): string {
        $this->config = array_merge(
            [
                'backtrace' => false,
                'pre' => true,
            ],
            $this->config
        );

        $dumpers = [
            'print_r' => fn($c) => print_r($c, true),
            'var_export' => fn($c) => var_export($c, true),
            'var_dump' => fn($c) => var_dump($c),
            'json_encode' => fn($c) => json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];

        $dumper = $dumpers[$this->config['output_type'] ?? 'json_encode'] ?? $dumpers['json_encode'];

        ob_start();
        echo $dumper($content);
        $output = ob_get_clean();

        $inner = sprintf("%s\n%s\n%s",
            Main::timestamp($this->config['timestamp_format_output']), 
            $this->config['backtrace']
                ? static::backtrace()
                : static::backtrace(['chain' => false]),
            $output
        );

        return $this->config['pre']
            ? sprintf("<pre>\n%s\n</pre>", $inner)
            : "{$inner}\n";
    }

    private function __construct(array $config = []) {
        $this->config = array_merge(
            static::config(),
            $config
        );

        $this->config['dir'] = Main::preparePath($this->config['dir'], 3);
        $this->config['file'] = trim($this->config['file'], DIRECTORY_SEPARATOR);
    }

    private function throw($content): void {
        throw new \Exception(
            static::get_output($content)
        );
    }

    private function here($content): void {
        echo static::get_output($content);
    }

    private function to_console($content): void {
        echo '<script>console.log(`'.static::get_output($content).'`)</script>';
    }

    private function to_email($content): bool {
        $this->config = array_merge(
            [
                'to' => null,
                'subject' => 'dump_to_email_log',
            ],
            $this->config
        );

        return mail($this->config['to'], $this->config['subject'], static::get_output($content));
    }

    private function to_file($content) {
        $this->config = array_merge(
            [
                'timestamp' => false,
                'merge' => true,
                'append' => false,
            ],
            $this->config
        );

        if (!is_dir($this->config['dir'])) mkdir($this->config['dir'], 0777, true);

        $info = pathinfo($this->config['file']);
        $ext = $info['extension'] ?? 'html';
        $base = $info['filename'];

        if ($this->config['timestamp'])
            $base .= '_'.Main::timestamp($this->config['timestamp_format_file']);
        
        $path = $this->config['dir'].DIRECTORY_SEPARATOR.$base.'.'.$ext;

        static::$dumper_counter[$path] = (static::$dumper_counter[$path] ?? 0) + 1;

        return file_put_contents($path, static::get_output($content), (
            (self::$dumper_counter[$path] ?? 0) === 1
                ? ($this->config['append'] && file_exists($path))
                : $this->config['merge']
            ) ? FILE_APPEND : 0
        );
    }

    public static function __callStatic(string $name, array $arguments) {
        $instance = new static($arguments[1] ?? []);
         if (method_exists($instance, $name))
            return $instance->$name($arguments[0] ?? null);

        throw new \BadMethodCallException("Method {$name} not found in ".static::class);
    }

}