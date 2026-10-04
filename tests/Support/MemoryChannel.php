<?php

namespace ST_system\Tests\Support;

use ST_system\MCP\Transport\Channel;

/** SSE-поток MCP в память: что сервер отправил клиенту. */
final class MemoryChannel implements Channel {

    public array $sent  = [];
    public int   $pings = 0;
    public bool  $gone  = false;

    public function send(array $message): void {
        $this->sent[] = $message;
    }

    public function ping(): void {
        $this->pings++;
    }

    public function closed(): bool {
        return $this->gone;
    }
}
