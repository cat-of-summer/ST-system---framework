<?php

namespace ST_system\Tests\Support;

/**
 * Встроенный веб-сервер PHP на localhost для тестов HTTP.
 *
 * url() — эхо-сервер для HTTP-клиентов: отвечает JSON-эхом запроса, `/status/{code}` отдаёт
 * указанный статус. app() — сервер с другим роутером (мини-приложением) и переменными
 * окружения; на каждый роутер поднимается свой процесс.
 */
final class StubServer {

    /** @var array<string,array{process:resource,url:string}> */
    private static array $servers = [];

    public static function url(): string {
        return self::app(__DIR__.'/stub-router.php');
    }

    /**
     * @param array<string,string> $env переменные окружения процесса; PHP_CLI_SERVER_WORKERS > 1
     *                                   даёт параллельные запросы (нужно для SSE + встречного POST)
     */
    public static function app(string $router, array $env = []): string {
        if (isset(self::$servers[$router]))
            return self::$servers[$router]['url'];

        $port = self::freePort();
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            // Лог сервера не читается — в pipe он рано или поздно заблокировал бы процесс.
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            $env !== [] ? $env + getenv() : null
        );

        if (!is_resource($process))
            throw new \RuntimeException('Не удалось запустить php -S');

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);

                if (self::$servers === [])
                    register_shutdown_function([self::class, 'stop']);

                self::$servers[$router] = ['process' => $process, 'url' => "http://127.0.0.1:{$port}"];

                return self::$servers[$router]['url'];
            }
            usleep(50000);
        }

        proc_terminate($process);
        proc_close($process);
        throw new \RuntimeException("php -S не поднялся на порту {$port}");
    }

    public static function stop(): void {
        foreach (self::$servers as $server) {
            proc_terminate($server['process']);
            proc_close($server['process']);
        }

        self::$servers = [];
    }

    private static function freePort(): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
