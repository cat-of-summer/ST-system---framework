<?php

namespace ST_system\MCP\Transport;

use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\MCP\Context;
use ST_system\MCP\Server;
use ST_system\MCP\Dispatcher;
use ST_system\MCP\Elicitation\ChannelElicitor;
use ST_system\MCP\Elicitation\NoElicitation;
use ST_system\MCP\State\PendingStore;
use ST_system\MCP\State\SessionStore;

/**
 * Streamable HTTP для php-fpm: один POST — одно сообщение JSON-RPC.
 *
 * - initialize создаёт сессию, её id уходит в заголовке Mcp-Session-Id;
 * - уведомления и ответы клиента (на elicitation/create) — 202 без тела;
 * - запросы — JSON; вызов инструмента со streams() — SSE-поток, если клиент его принимает:
 *   в поток уходят вопросы человеку и ping, а последним событием — результат;
 * - GET-поток сервера не нужен (405), DELETE закрывает сессию.
 * Пакеты сообщений (JSON-массивы) отклоняются: из протокола они убраны в 2025-06-18.
 */
final class HttpTransport {

    /** @var Dispatcher */
    private $dispatcher;
    /** @var callable(): Channel */
    private $channels;

    /** @param ?callable(): Channel $channels фабрика канала SSE (в тестах — канал в память) */
    public function __construct(Dispatcher $dispatcher, ?callable $channels = null) {
        $this->dispatcher   = $dispatcher;
        $this->channels = $channels ?? static function (): Channel { return new SseChannel(); };
    }

    public function handleRequest(Request $request): Response {
        return $this->handle(
            strtoupper((string)$request->method()),
            (string)file_get_contents('php://input'),
            array_change_key_case((array)($request->headers() ?? []), CASE_LOWER),
            $request
        );
    }

    /** @param array<string,string> $headers имена в нижнем регистре */
    public function handle(string $method, string $body, array $headers, ?Request $request = null): Response {
        switch ($method) {
            case 'POST':   return $this->post($body, $headers, $request);
            case 'DELETE': return $this->delete($headers);
        }

        return Response::json(Dispatcher::error(null, -32000, 'Поддерживаются POST и DELETE.'))
            ->status(405)->header('Allow', 'POST, DELETE');
    }

    private function post(string $body, array $headers, ?Request $request): Response {
        $message = json_decode($body, true);

        if (!is_array($message))
            return self::json(Dispatcher::error(null, -32700, 'Тело запроса — не JSON.'), 400);

        if ($message !== [] && array_keys($message) === range(0, count($message) - 1))
            return self::json(Dispatcher::error(null, -32600, 'Пакеты сообщений не поддерживаются: одно сообщение на запрос.'), 400);

        if (($message['jsonrpc'] ?? null) !== '2.0')
            return self::json(Dispatcher::error($message['id'] ?? null, -32600, 'Ожидается JSON-RPC 2.0.'), 400);

        if (($message['method'] ?? null) === 'initialize')
            return $this->initialize($message);

        $sessionId = (string)($headers['mcp-session-id'] ?? '');
        if ($sessionId === '')
            return self::json(Dispatcher::error($message['id'] ?? null, -32000, 'Нет заголовка Mcp-Session-Id: сначала initialize.'), 400);

        $session = SessionStore::get($sessionId);
        if ($session === null)
            return self::json(Dispatcher::error($message['id'] ?? null, -32001, 'Сессия не найдена или истекла: выполните initialize заново.'), 404);

        // Ответ клиента на наш вопрос (elicitation/create): его ждёт воркер с SSE-потоком.
        if (!isset($message['method'])) {
            if (isset($message['id']) && (isset($message['result']) || isset($message['error'])))
                PendingStore::answer($sessionId, (string)$message['id'], $message);

            return self::accepted();
        }

        // Уведомление (notifications/initialized, notifications/cancelled…) — ответа не требует.
        if (!array_key_exists('id', $message))
            return self::accepted();

        $protocol = (string)($session['protocol'] ?? $this->dispatcher->latest());

        if ($this->streams($message) && self::acceptsStream($headers))
            return $this->stream($message, $session, $protocol, $request);

        $context = new Context(new NoElicitation(), $protocol, null, $session, $request);

        return self::json($this->dispatcher->handle($message, $context), 200, $sessionId);
    }

    private function initialize(array $message): Response {
        $params   = is_array($message['params'] ?? null) ? $message['params'] : [];
        $protocol = $this->dispatcher->negotiate($params['protocolVersion'] ?? null);

        $sessionId = SessionStore::create(
            $protocol,
            is_array($params['capabilities'] ?? null) ? $params['capabilities'] : [],
            is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : []
        );

        return self::json(Dispatcher::result($message['id'] ?? null, $this->dispatcher->initializeResult($protocol)), 200, $sessionId);
    }

    private function delete(array $headers): Response {
        $found = SessionStore::delete((string)($headers['mcp-session-id'] ?? ''));

        return Response::raw('')->status($found ? 204 : 404);
    }

    /**
     * SSE-ответ: вопросы человеку и ping идут в тот же поток, что и результат. Клиенту без
     * elicitation поток тоже открывается — ради ожидания. Ожидание держит воркер fpm, поэтому
     * время скрипта не ограничено, а обрыв соединения отслеживается явно
     * (ignore_user_abort + Channel::closed).
     */
    private function stream(array $message, array $session, string $protocol, ?Request $request): Response {
        $dispatcher    = $this->dispatcher;
        $channels  = $this->channels;
        $timeout   = max(1, (int)Server::config('elicitation_timeout'));
        $poll      = (float)Server::config('poll_interval');
        $pingEvery = max(1, (int)Server::config('ping_interval'));

        return Response::stream(static function () use ($dispatcher, $channels, $message, $session, $protocol, $request, $timeout, $poll, $pingEvery): void {
            @set_time_limit(0);
            ignore_user_abort(true);

            $channel  = $channels();
            $elicitor = !empty($session['elicitation'])
                ? new ChannelElicitor($channel, (string)$session['id'], $timeout, $poll, $pingEvery)
                : new NoElicitation();

            $response = $dispatcher->handle($message, new Context($elicitor, $protocol, $channel, $session, $request));

            if (!$channel->closed())
                $channel->send($response);
        })->headers([
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Mcp-Session-Id'    => (string)$session['id'],
        ]);
    }

    /** Поток нужен только инструментам, которые спрашивают человека или долго ждут. */
    private function streams(array $message): bool {
        if (($message['method'] ?? '') !== 'tools/call') return false;

        $tool = $this->dispatcher->tool((string)($message['params']['name'] ?? ''));
        return $tool !== null && $tool->streams();
    }

    private static function acceptsStream(array $headers): bool {
        return strpos(strtolower((string)($headers['accept'] ?? '')), 'text/event-stream') !== false;
    }

    private static function json(array $payload, int $status = 200, ?string $sessionId = null): Response {
        $response = Response::json($payload)->status($status);

        return $sessionId !== null ? $response->header('Mcp-Session-Id', $sessionId) : $response;
    }

    private static function accepted(): Response {
        return Response::raw('')->status(202);
    }
}
