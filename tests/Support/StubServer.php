<?php

namespace ST_system\Tests\Support;

/**
 * Встроенный веб-сервер PHP на localhost для тестов HTTP-клиентов.
 * Отвечает JSON-эхом запроса; `/status/{code}` отдаёт указанный статус.
 */
final class StubServer {

    private static $process = null;
    private static string $url = '';

    public static function url(): string {
        if (self::$process !== null)
            return self::$url;

        $port   = self::freePort();
        $router = __DIR__.'/stub-router.php';
        $null   = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        self::$process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            // Лог сервера не читается — в pipe он рано или поздно заблокировал бы процесс.
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes
        );

        if (!is_resource(self::$process))
            throw new \RuntimeException('Не удалось запустить php -S');

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                register_shutdown_function([self::class, 'stop']);

                return self::$url = "http://127.0.0.1:{$port}";
            }
            usleep(50000);
        }

        self::stop();
        throw new \RuntimeException("php -S не поднялся на порту {$port}");
    }

    public static function stop(): void {
        if (self::$process === null) return;

        proc_terminate(self::$process);
        proc_close(self::$process);
        self::$process = null;
    }

    private static function freePort(): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
