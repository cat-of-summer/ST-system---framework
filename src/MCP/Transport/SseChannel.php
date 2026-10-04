<?php

namespace ST_system\MCP\Transport;

/**
 * SSE-ответ Streamable HTTP: каждое сообщение — событие message с JSON в data.
 * Заголовки ставит HttpTransport, в том числе X-Accel-Buffering: no — иначе nginx
 * копит поток в буфере и клиент не видит ни вопросов, ни ping.
 */
final class SseChannel implements Channel {

    public function send(array $message): void {
        echo "event: message\ndata: ".json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        $this->flush();
    }

    public function ping(): void {
        echo ": ping\n\n";
        $this->flush();
    }

    public function closed(): bool {
        return connection_aborted() === 1;
    }

    private function flush(): void {
        while (ob_get_level() > 0) @ob_end_flush();
        flush();
    }
}
