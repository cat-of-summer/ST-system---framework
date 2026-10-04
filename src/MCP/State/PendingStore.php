<?php

namespace ST_system\MCP\State;

/**
 * Вопросы сервера клиенту (elicitation/create), ждущие ответа.
 *
 * Воркер, держащий SSE-поток вызова инструмента, регистрирует вопрос (expect) и опрашивает
 * файл (take). Ответ клиента приходит отдельным POST в другой воркер, и тот кладёт его сюда
 * (answer). Ответ на незарегистрированный вопрос отбрасывается — чужая сессия или
 * выдуманный id не создадут файлов.
 */
final class PendingStore {

    public static function expect(string $session, string $requestId): void {
        Storage::write(self::file($session, $requestId), ['waiting' => true, 'created_at' => time()]);
    }

    /** @return bool принят ли ответ (вопрос был и ещё ждёт) */
    public static function answer(string $session, string $requestId, array $message): bool {
        $current = Storage::read(self::file($session, $requestId));
        if ($current === null || empty($current['waiting'])) return false;

        Storage::write(self::file($session, $requestId), ['waiting' => false, 'message' => $message]);
        return true;
    }

    /** Ответ, если пришёл; вопрос при этом снимается. */
    public static function take(string $session, string $requestId): ?array {
        $current = Storage::read(self::file($session, $requestId));
        if ($current === null || !empty($current['waiting'])) return null;

        Storage::delete(self::file($session, $requestId));
        return is_array($current['message'] ?? null) ? $current['message'] : [];
    }

    public static function forget(string $session, string $requestId): void {
        Storage::delete(self::file($session, $requestId));
    }

    private static function file(string $session, string $requestId): string {
        // id запроса приходит от клиента — в имя файла попадает только его хэш.
        return 'pending/'.preg_replace('/[^a-f0-9]/', '', $session).'/'.sha1($requestId).'.json';
    }
}
