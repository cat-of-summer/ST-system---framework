<?php

namespace ST_system\MCP\State;

use ST_system\Main;
use ST_system\MCP\Server;

/**
 * Файловое состояние MCP: сессии и ожидающие ответа вопросы. Каталог — Server::config('storage').
 *
 * Под php-fpm каждый HTTP-запрос живёт в своём процессе, и общей памяти у них нет: ответ
 * клиента на elicitation/create приходит отдельным POST в другой воркер. Поэтому состояние
 * лежит в файлах, запись атомарна (tmp + rename).
 */
final class Storage {

    public static function path(string $relative): string {
        return rtrim(Main::preparePath((string)Server::config('storage')), '/').'/'.ltrim($relative, '/');
    }

    public static function read(string $relative): ?array {
        $path = self::path($relative);
        if (!is_file($path)) return null;

        $data = @json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public static function write(string $relative, array $data): void {
        $path = self::path($relative);
        $dir  = dirname($path);

        if (!is_dir($dir))
            @mkdir($dir, 0775, true);

        $tmp = $path.'.'.getmypid().'.'.bin2hex(random_bytes(3)).'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $path);
    }

    public static function exists(string $relative): bool {
        return is_file(self::path($relative));
    }

    public static function delete(string $relative): void {
        @unlink(self::path($relative));
    }

    /** Удалить файлы старше $maxAge секунд в подкаталоге (рекурсивно) и опустевшие каталоги. */
    public static function prune(string $relative, int $maxAge, ?int $now = null): void {
        $root = self::path($relative);
        if (!is_dir($root)) return;

        $limit = ($now ?? time()) - $maxAge;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            if ($f->isDir()) @rmdir($f->getPathname());   // не пустой — не удалится
            elseif ($f->getMTime() < $limit) @unlink($f->getPathname());
        }
    }
}
