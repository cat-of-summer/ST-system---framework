<?php

namespace ST_system;

use ST_system\Main;
use ST_system\Rule;
use ST_system\Traits\HasConfig;
use ST_system\Traits\HasEvents;
use ST_system\Traits\HasInstance;

final class Debug {

    use HasInstance;
    use HasConfig;
    use HasEvents {
        on as private _on;
    }

    protected static function getReservedEvents(): array {
        return ['on_error', 'on_exception', 'on_shutdown'];
    }

    public static function on(string $event, callable $listener): void {
        static::getInstance()->_on($event, $listener);
    }

    protected static function getDefaultConfig(): array {
        return [
            'format' => [
                'timestamp' => [
                    'output' => 'd-m-Y H:i:s',
                    'file'   => 'd-m-Y~H-i-s',
                ],
                'output' => 'json_encode',
            ],
            'filesystem' => [
                'dir'  => '~logs',
                'file' => 'log.html',
            ],
            'handle_error' => [
                'reporting' => [
                    'level' => E_ALL,
                ],
                'shutdown' => [
                    'level' => [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                ],
                'output' => [
                    'method' => 'toFile',
                    'display' => false
                ],
            ]
        ];
    }

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
                'chain'      => true,
                'skip_start' => 0,
                'skip_end'   => 0
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

        $timer = Main::uuid();
        $durations = [];
        for ($i = 0; $i < $iterations; $i++) {
            self::start("{$timer}#{$i}");
                $job();
            $durations[] = self::finish("{$timer}#{$i}");
        }

        $total = array_sum($durations);
        $avg   = $iterations ? $total / $iterations : 0.0;

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
            'durations'  => $durations,
            'iterations' => $iterations,
            'warmup'     => $warmup,
            'avg'        => $avg,
            'median'     => $median,
            'min'        => $min,
            'max'        => $max,
            'total'      => $total,
            'unit'       => 's',
        ];
    }

    public static function linter(string $file_path): array {
        static $short_open_tag = null;

        $file_path = Main::preparePath($file_path, 1);

        if (!is_file($file_path))
            return [
                'ok'     => false,
                'errors' => "The file '{$file_path}' does not exist",
                'result' => '',
                'code'   => 1
            ];

        try {
            if ($short_open_tag === null)
                $short_open_tag = ini_get('short_open_tag');

            @exec('php -d short_open_tag='.$short_open_tag.' -l '.escapeshellarg($file_path).' 2>&1', $output_lines, $exit_code);

            return [
                'ok'     => $exit_code == 0,
                'result' => array_pop($output_lines),
                'errors' => $output_lines,
                'code'   => $exit_code
            ];
        } catch (\Throwable $th) {
            return [
                'ok'     => false,
                'result' => $th->getMessage(),
                'errors' => [$th->getMessage()],
                'code'   => -1
            ];
        }
    }

    private function __construct() {}

    private function getOutput($content, array $config): string {
        $dumpers = [
            'print_r'     => fn($c) => print_r($c, true),
            'var_export'  => fn($c) => var_export($c, true),
            'var_dump'    => fn($c) => var_dump($c),
            'json_encode' => fn($c) => json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];

        $dumper = $dumpers[$config['output_type']] ?? $dumpers['json_encode'];

        ob_start();
        echo $dumper($content);
        $output = ob_get_clean();

        $inner = sprintf("%s\n%s\n%s",
            Main::timestamp($config['timestamp_format_output']),
            $config['backtrace']
                ? static::backtrace()
                : static::backtrace(['chain' => false]),
            $output
        );

        return $config['pre']
            ? sprintf("<pre>\n%s\n</pre>", $inner)
            : "{$inner}\n";
    }

    private function exception($content, array $config = []): void {
        static::applyConfig($config, [
            'output_type'             => 'nullable|string|@format.output',
            'backtrace'               => ['nullable|bool', Rule::default(false)],
            'pre'                     => ['nullable|bool', Rule::default(true)],
            'timestamp_format_output' => 'nullable|string|@format.timestamp.output',
        ]);
        throw new \Exception($this->getOutput($content, $config));
    }

    private function here($content, array $config = []): void {
        static::applyConfig($config, [
            'output_type'             => 'nullable|string|@format.output',
            'backtrace'               => ['nullable|bool', Rule::default(false)],
            'pre'                     => ['nullable|bool', Rule::default(true)],
            'timestamp_format_output' => 'nullable|string|@format.timestamp.output',
        ]);
        echo $this->getOutput($content, $config);
    }

    private function toConsole($content, array $config = []): void {
        static::applyConfig($config, [
            'output_type'             => 'nullable|string|@format.output',
            'backtrace'               => ['nullable|bool', Rule::default(false)],
            'pre'                     => ['nullable|bool', Rule::default(true)],
            'timestamp_format_output' => 'nullable|string|@format.timestamp.output',
        ]);
        echo '<script>console.log(`'.$this->getOutput($content, $config).'`)</script>';
    }

    private function toEmail($content, array $config = []): bool {
        static::applyConfig($config, [
            'output_type'             => 'nullable|string|@format.output',
            'backtrace'               => ['nullable|bool', Rule::default(false)],
            'pre'                     => ['nullable|bool', Rule::default(true)],
            'timestamp_format_output' => 'nullable|string|@format.timestamp.output',
            'to'                      => 'nullable|string',
            'subject'                 => ['nullable|string', Rule::default('dump_to_email_log')],
        ]);
        return mail($config['to'], $config['subject'], $this->getOutput($content, $config));
    }

    private function toFile($content, array $config = []) {
        static::applyConfig($config, [
            'output_type'             => 'nullable|string|@format.output',
            'backtrace'               => ['nullable|bool', Rule::default(false)],
            'pre'                     => ['nullable|bool', Rule::default(true)],
            'timestamp_format_output' => 'nullable|string|@format.timestamp.output',
            'dir'                     => 'string|@filesystem.dir',
            'file'                    => 'string|@filesystem.file',
            'timestamp'               => ['nullable|bool', Rule::default(false)],
            'timestamp_format_file'   => 'nullable|string|@format.timestamp.file',
            'merge'                   => ['nullable|bool', Rule::default(true)],
            'append'                  => ['nullable|bool', Rule::default(false)],
        ]);

        $dir  = Main::preparePath($config['dir'], 3);
        $file = trim($config['file'], '/');

        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $info = pathinfo($file);
        $ext  = $info['extension'] ?? 'html';
        $base = $info['filename'];

        if ($config['timestamp'])
            $base .= '_'.Main::timestamp($config['timestamp_format_file']);

        $path = $dir.DIRECTORY_SEPARATOR.$base.'.'.$ext;

        static::$dumper_counter[$path] = (static::$dumper_counter[$path] ?? 0) + 1;

        return file_put_contents($path, $this->getOutput($content, $config), (
            (static::$dumper_counter[$path] ?? 0) === 1
                ? ($config['append'] && file_exists($path))
                : $config['merge']
        ) ? FILE_APPEND : 0);
    }

    public static function handleError(array $config = []): void {
        static $done = false;
        if ($done)
            throw new \LogicException(__CLASS__.'::handleError() can be called only once per request');

        static::applyConfig($config, [
            'onError'     => 'callable|nullable',
            'onException' => 'callable|nullable',
            'onShutdown'  => 'callable|nullable',
            'display'     => 'nullable|bool|@handle_error.output.display',
            'dir'         => 'string|@filesystem.dir',
            'file'        => 'string|@filesystem.file',
        ]);

        if (!empty($config['onError']))     static::getInstance()->_on('on_error',     $config['onError']);
        if (!empty($config['onException'])) static::getInstance()->_on('on_exception', $config['onException']);
        if (!empty($config['onShutdown']))  static::getInstance()->_on('on_shutdown',  $config['onShutdown']);

        error_reporting(static::config('handle_error.reporting.level'));

        $display = $config['display'] ? '1' : '0';
        ini_set('display_errors',         $display);
        ini_set('display_startup_errors', $display);
        ini_set('log_errors', '1');

        $dir = Main::preparePath($config['dir'], 3);
        $file = trim($config['file'], '/');
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        ini_set('error_log', $dir . DIRECTORY_SEPARATOR . $file);

        set_error_handler(fn(int $severity, string $message, string $file, int $line) => static::onError($severity, $message, $file, $line));
        set_exception_handler(fn(\Throwable $th) => static::onException($th));
        register_shutdown_function(fn() => static::onShutdown());

        $done = true;
    }

    private static function onError(int $severity, string $message, string $file, int $line): void {
        $error = compact('severity', 'message', 'file', 'line');

        if (static::getInstance()->fire('on_error', $error) === false)
            call_user_func([static::class, static::config('handle_error.output.method')], $error);
    }

    private static function onException(\Throwable $th): void {
        if (static::getInstance()->fire('on_exception', $th) === false)
            call_user_func([static::class, static::config('handle_error.output.method')], $th->getMessage());
    }

    private static function onShutdown(): void {
        $error = error_get_last();

        if (!$error || !in_array($error['type'], static::config('handle_error.shutdown.level'), true)) return;

        if (static::getInstance()->fire('on_shutdown', $error) === false)
            call_user_func([static::class, static::config('handle_error.output.method')], $error);
    }

    public static function __callStatic(string $name, array $arguments) {
        $instance = static::getInstance();
        if (method_exists($instance, $name))
            return $instance->$name($arguments[0] ?? null, $arguments[1] ?? []);
        throw new \BadMethodCallException("Method {$name} not found in " . static::class);
    }

}
