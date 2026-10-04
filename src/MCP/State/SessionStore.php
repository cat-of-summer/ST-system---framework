<?php

namespace ST_system\MCP\State;

use ST_system\MCP\Server;

/**
 * Сессии MCP: id выдаётся на initialize и приходит обратно в заголовке Mcp-Session-Id.
 * В сессии — согласованная версия протокола, данные клиента и умеет ли он elicitation.
 * Сессия без запросов дольше Server::config('session_ttl') считается брошенной.
 */
final class SessionStore {

    public static function create(string $protocol, array $capabilities, array $clientInfo = []): string {
        // Старые сессии и забытые вопросы подчищаются здесь: новые сессии редки, а cron
        // ради этого не нужен.
        $ttl = (int)Server::config('session_ttl');
        Storage::prune('sessions', $ttl);
        Storage::prune('pending', $ttl);

        $id = bin2hex(random_bytes(16));

        Storage::write("sessions/{$id}.json", [
            'id'          => $id,
            'protocol'    => $protocol,
            'elicitation' => self::supportsForms($capabilities['elicitation'] ?? null),
            'client'      => $clientInfo,
            'created_at'  => time(),
        ]);

        return $id;
    }

    public static function get(string $id): ?array {
        if (!self::valid($id)) return null;

        $session = Storage::read("sessions/{$id}.json");
        if ($session === null) return null;

        // Отметка активности — mtime файла: перезапись на каждый запрос лишняя, touch дешевле.
        @touch(Storage::path("sessions/{$id}.json"));

        return $session;
    }

    public static function delete(string $id): bool {
        if (!self::valid($id) || !Storage::exists("sessions/{$id}.json")) return false;

        Storage::delete("sessions/{$id}.json");
        return true;
    }

    /**
     * Нужен режим формы. Клиенты 2025-06-18 объявляют просто elicitation: {} — это он и есть;
     * клиенты 2025-11-25 перечисляют режимы, и тогда form должен быть в списке.
     *
     * @param mixed $capability
     */
    public static function supportsForms($capability): bool {
        if (!is_array($capability)) return false;
        if ($capability === [] || !array_intersect(['form', 'url'], array_keys($capability))) return true;

        return array_key_exists('form', $capability);
    }

    public static function valid(string $id): bool {
        return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
    }
}
