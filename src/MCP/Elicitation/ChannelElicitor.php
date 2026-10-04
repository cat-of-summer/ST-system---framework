<?php

namespace ST_system\MCP\Elicitation;

use ST_system\MCP\Transport\Channel;
use ST_system\MCP\State\PendingStore;

/**
 * elicitation/create на SSE-поток текущего tools/call и ожидание ответа.
 *
 * Вопрос идёт в поток того же запроса, а не в отдельный GET-поток сессии: так он связан с
 * вызовом инструмента и доходит до клиента, даже если тот GET-поток не открывал. Ответ
 * клиент шлёт отдельным POST — его принимает другой воркер и кладёт в PendingStore, отсюда
 * он забирается опросом. Пока ждём, в поток уходит ping: иначе прокси закроет соединение.
 */
final class ChannelElicitor implements Elicitor {

    /** @var Channel */
    private $channel;
    /** @var string */
    private $session;
    /** @var int */
    private $timeout;
    /** @var float */
    private $poll;
    /** @var int */
    private $pingEvery;
    /** @var callable(float):void */
    private $sleep;

    public function __construct(Channel $channel, string $session, int $timeout = 300, float $poll = 0.25, int $pingEvery = 15, ?callable $sleep = null) {
        $this->channel   = $channel;
        $this->session   = $session;
        $this->timeout   = $timeout;
        $this->poll      = $poll;
        $this->pingEvery = $pingEvery;
        $this->sleep     = $sleep ?? static function (float $s): void { usleep((int)($s * 1e6)); };
    }

    public function supported(): bool {
        return true;
    }

    public function ask(string $message, array $requestedSchema): array {
        $id = 'elicit-'.bin2hex(random_bytes(8));

        PendingStore::expect($this->session, $id);

        $this->channel->send([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => 'elicitation/create',
            // mode не передаётся: форма — режим по умолчанию, а клиенты 2025-06-18 поля не знают.
            'params'  => [
                'message'         => $message,
                'requestedSchema' => $requestedSchema,
            ],
        ]);

        $waited   = 0.0;
        $lastPing = 0.0;

        while ($waited < $this->timeout) {
            $answer = PendingStore::take($this->session, $id);
            if ($answer !== null) return self::parse($answer);

            if ($waited - $lastPing >= $this->pingEvery) {
                $this->channel->ping();
                $lastPing = $waited;

                if ($this->channel->closed()) break;
            }

            ($this->sleep)($this->poll);
            $waited += $this->poll;
        }

        PendingStore::forget($this->session, $id);
        return ['action' => 'timeout'];
    }

    /** Ответ клиента → action/content. Ошибка JSON-RPC вместо результата — как отмена. */
    private static function parse(array $message): array {
        $result = $message['result'] ?? null;
        if (!is_array($result)) return ['action' => 'cancel'];

        $action = in_array($result['action'] ?? '', ['accept', 'decline', 'cancel'], true) ? $result['action'] : 'cancel';

        return ['action' => $action, 'content' => is_array($result['content'] ?? null) ? $result['content'] : []];
    }
}
