<?php

namespace ST_system;

use ST_system\Main;
use ST_system\Rule;
use ST_system\Traits\HasConfig;
use ST_system\Traits\Events\HasStaticEvents;
use ST_system\Traits\HasInstance;

/**
 * Dump-методы — приватные методы экземпляра, вызываются статически через __callStatic.
 * Методы из конфига `dump_methods` (по умолчанию toEmail) получают уже отформатированный вывод.
 *
 * @method static void here(mixed $content, array $config = [])
 * @method static void toConsole(mixed $content, array $config = [])
 * @method static int|false toFile(mixed $content, array $config = [])
 * @method static int|false toStream(mixed $content, array $config = [])
 * @method static void exception(mixed $content, array $config = [])
 * @method static bool toEmail(mixed $content, array $config = [])
 */
final class Debug {

    use HasInstance;
    use HasConfig;
    use HasStaticEvents;

    protected static function getReservedEvents(): array {
        return ['on_error', 'on_exception', 'on_shutdown'];
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
                'dir'      => '~logs',
                'file'     => 'log.html',
                'max_size' => 2 * 1024 * 1024,
                'keep'     => 0.5,
            ],
            'handle_error' => [
                'reporting' => [
                    'level' => E_ALL,
                ],
                'error' => [
                    'level' => [
                        E_WARNING, E_NOTICE,
                        E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE,
                        E_RECOVERABLE_ERROR, E_DEPRECATED, E_USER_DEPRECATED,
                    ],
                ],
                'exception' => [
                    'level' => [\Throwable::class],
                ],
                'shutdown' => [
                    'level' => [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                ],
                'output' => [
                    'method' => 'toFile',
                    'display' => false,
                    'config'  => ['append' => true],
                ],
            ],
            'dump_methods' => [
                'toEmail' => function(string $output, array $config = []): bool {
                    static::applyConfig($config, [
                        'to'      => 'nullable|string',
                        'subject' => 'string|default:dump_to_email_log',
                    ]);
                    
                    return mail($config['to'], $config['subject'], $output);
                },
            ],
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

    /**
     * Ключи getOutput(). Dump-метод со своей схемой обязан включать их: applyConfig
     * отбрасывает необъявленные ключи, и без этого pre/backtrace/output_type не доходят до вывода.
     */
    private static function outputSchema(): array {
        return [
            'output_type'             => 'nullable|string|@format.output',
            'backtrace'               => [Rule::create(function (&$v): bool {
                $v = static::backtraceMode($v);
                return true;
            })->seesSentinel()],
            'pre'                     => ['nullable|bool', Rule::default(true)],
            'time'                    => ['nullable|bool', Rule::default(true)],
            'timestamp_format_output' => 'nullable|string|@format.timestamp.output',
        ];
    }

    /** `true`/`'full'` — вся цепочка вызовов, `false`/`'none'` — без строки места, иначе `'short'`. */
    private static function backtraceMode($v) {
        if ($v === true || $v === 'full') return true;
        if ($v === false || $v === 'none') return false;
        return 'short';
    }

    private function getOutput($content, array $config): string {
        $dumpers = [
            'print_r'     => fn($c) => print_r($c, true),
            'var_export'  => fn($c) => var_export($c, true),
            'var_dump'    => fn($c) => var_dump($c),
            'json_encode' => fn($c) => json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'json'        => fn($c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        static::applyConfig($config, static::outputSchema());

        $dumper = $dumpers[$config['output_type']] ?? $dumpers['json_encode'];

        ob_start();
        echo $dumper($content);
        $output = ob_get_clean();

        $head = [];

        if ($config['time'])
            $head[] = Main::timestamp($config['timestamp_format_output']);

        if ($config['backtrace'] === true)
            $head[] = static::backtrace();
        elseif ($config['backtrace'] === 'short')
            $head[] = static::backtrace(['chain' => false]);

        $inner = implode("\n", array_merge($head, [$output]));

        return $config['pre']
            ? sprintf("<pre>\n%s\n</pre>", $inner)
            : "{$inner}\n";
    }

    private function exception($content, array $config = []): void {
        throw new \Exception($this->getOutput($content, $config));
    }

    private function here($content, array $config = []): void {
        echo $this->getOutput($content, $config);
    }

    private function toConsole($content, array $config = []): void {
        echo '<script>console.log(`'.$this->getOutput($content, $config).'`)</script>';
    }

    private function toFile($content, array $config = []) {
        static::applyConfig($config, [
            'dir'                   => 'string|@filesystem.dir',
            'file'                  => 'string|@filesystem.file',
            'timestamp'             => ['nullable|bool', Rule::default(false)],
            'timestamp_format_file' => 'nullable|string|@format.timestamp.file',
            'merge'                 => ['nullable|bool', Rule::default(true)],
            'append'                => ['nullable|bool', Rule::default(true)],
            'max_size'              => 'int|@filesystem.max_size',
            'keep'                  => 'float|@filesystem.keep',
        ] + static::outputSchema());

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

        $entry  = $this->getOutput($content, $config);
        $append = static::$dumper_counter[$path] === 1
            ? ($config['append'] && file_exists($path))
            : $config['merge'];

        if (!$fp = fopen($path, 'c+b')) return false;
        flock($fp, LOCK_EX);

        if (!$append)
            ftruncate($fp, 0);
        else
            static::trimHead($fp, strlen($entry), $config['max_size'], $config['keep'], $config['pre']
                ? '/^<pre>$/m'
                : ($config['time']
                    ? '/^'.preg_replace('/\d/', '\d', preg_quote(strtok($entry, "\n"), '/')).'$/m'
                    : '/(?<=\n)/'));

        fseek($fp, 0, SEEK_END);
        $written = fwrite($fp, $entry);

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $written;
    }

    /**
     * Ротация лога без архивов: если запись не помещается в $max байт, от файла остаётся хвост
     * около $max * $keep (вместе с новой записью), начиная с ближайшей границы записи: `<pre>`
     * или строки-метки времени, совпадающей по форме с первой строкой новой записи. Перезапись
     * файла случается раз на ~$max * (1 - $keep) байт, остальные вставки — обычный append.
     * $max <= 0 отключает ротацию. Вызывается под flock.
     */
    private static function trimHead($fp, int $incoming, int $max, float $keep, string $boundary): void {
        if ($max <= 0) return;

        $size = fstat($fp)['size'];
        if ($size + $incoming <= $max) return;

        $tail = '';
        $len  = (int)min($size, $max * $keep - $incoming);

        if ($len > 0) {
            fseek($fp, -$len, SEEK_END);
            $tail = (string)stream_get_contents($fp);

            $tail = preg_match($boundary, $tail, $m, PREG_OFFSET_CAPTURE) ? substr($tail, $m[0][1]) : '';
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $tail);
    }

    /**
     * Запись в поток процесса — для docker logs и подобных сборщиков. По умолчанию stderr:
     * stdout в web-SAPI либо пропадает (FPM без catch_workers_output), либо уходит в ответ.
     * Одна запись — одна строка: компактный JSON, без <pre>, переводы строк схлопываются.
     */
    private function toStream($content, array $config = []) {
        static::applyConfig($config, [
            'stream'      => ['nullable|string', Rule::default('stderr')],
            'pre'         => ['nullable|bool', Rule::default(false)],
            'output_type' => ['nullable|string', Rule::default('json')],
        ] + static::outputSchema());

        $stream = in_array($config['stream'], ['stdout', 'stderr'], true) ? 'php://'.$config['stream'] : $config['stream'];
        $line   = preg_replace('/\s*\R\s*/', ' ', trim($this->getOutput($content, $config))).PHP_EOL;

        if (!$fp = fopen($stream, 'ab')) return false;
        $written = fwrite($fp, $line);
        fclose($fp);

        return $written;
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

        if (!empty($config['onError']))     static::on('on_error',     $config['onError']);
        if (!empty($config['onException'])) static::on('on_exception', $config['onException']);
        if (!empty($config['onShutdown']))  static::on('on_shutdown',  $config['onShutdown']);

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
        if (!in_array($severity, static::config('handle_error.error.level'), true)) return;

        $error = compact('severity', 'message', 'file', 'line');

        if (static::fire('on_error', $error) === false)
            static::__callStatic(static::config('handle_error.output.method'), [$error, static::config('handle_error.output.config')]);
    }

    private static function onException(\Throwable $th): void {
        if (!array_filter(static::config('handle_error.exception.level'), fn($c) => $th instanceof $c)) return;

        if (static::fire('on_exception', $th) === false)
            static::__callStatic(static::config('handle_error.output.method'), [[
                'type'    => get_class($th),
                'message' => $th->getMessage(),
                'code'    => $th->getCode(),
                'file'    => $th->getFile(),
                'line'    => $th->getLine(),
                'trace'   => $th->getTrace(),
            ], static::config('handle_error.output.config')]);
    }

    private static function onShutdown(): void {
        $error = error_get_last();

        if (!$error || !in_array($error['type'], static::config('handle_error.shutdown.level'), true)) return;

        if (static::fire('on_shutdown', $error) === false)
            static::__callStatic(static::config('handle_error.output.method'), [$error, static::config('handle_error.output.config')]);
    }

    public static function addDumpMethod(string $name, \Closure $fn): void {
        if (method_exists(static::getInstance(), $name) || array_key_exists($name, static::config('dump_methods')))
            throw new \BadMethodCallException("Dump method '{$name}' is already registered in " . static::class);

        static::setConfig(["dump_methods.{$name}" => $fn]);
    }

    public static function __callStatic(string $name, array $arguments) {
        if (method_exists(static::getInstance(), $name))
            return static::getInstance()->$name($arguments[0] ?? null, $arguments[1] ?? []);

        if (array_key_exists($name, static::config('dump_methods'))) {
            $arguments[0] = static::getInstance()->getOutput($arguments[0] ?? null, $arguments[1] ?? []);

            return static::config('dump_methods')[$name](...$arguments);
        }

        throw new \BadMethodCallException("Method {$name} not found in " . static::class);
    }

}
