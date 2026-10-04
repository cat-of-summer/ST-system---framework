<?php

namespace ST_system\MCP;

use ST_system\HTTP\Request;
use ST_system\MCP\Transport\Channel;
use ST_system\MCP\Elicitation\Elicitor;
use ST_system\MCP\Elicitation\Forms;

/**
 * Обстановка вызова инструмента:
 * - как спросить человека и какую версию протокола знает клиент;
 * - есть ли SSE-поток, в котором можно ждать (null — ответ идёт JSON, ждать нельзя);
 * - сессия MCP и HTTP-запрос;
 * - атрибуты, которые tool-middleware передаёт инструменту.
 */
final class Context {

    /** @var Elicitor */
    public $elicitor;
    /** @var string */
    public $protocol;
    /** @var ?Channel */
    public $stream;

    /** @var ?array */
    private $session;
    /** @var ?Request */
    private $request;
    /** @var array */
    private $arguments = [];
    /** @var array<string,mixed> */
    private $attributes = [];

    public function __construct(Elicitor $elicitor, string $protocol = Dispatcher::LATEST, ?Channel $stream = null, ?array $session = null, ?Request $request = null) {
        $this->elicitor = $elicitor;
        $this->protocol = $protocol;
        $this->stream   = $stream;
        $this->session  = $session;
        $this->request  = $request;
    }

    public function forms(): Forms {
        return new Forms($this->protocol);
    }

    /** Сессия MCP: id, protocol, elicitation, client (clientInfo из initialize). */
    public function session(): ?array {
        return $this->session;
    }

    public function sessionId(): ?string {
        return $this->session['id'] ?? null;
    }

    /** HTTP-запрос, с которым пришёл вызов; null вне HTTP (тесты, stdio). */
    public function request(): ?Request {
        return $this->request;
    }

    /** Проверенные аргументы текущего вызова. */
    public function arguments(): array {
        return $this->arguments;
    }

    /** @internal Dispatcher ставит аргументы перед вызовом инструмента. */
    public function withArguments(array $arguments): self {
        $this->arguments = $arguments;
        return $this;
    }

    /** @param mixed $value */
    public function set(string $key, $value): self {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    public function has(string $key): bool {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Подтверждение человека перед опасной операцией.
     *
     * Если клиент умеет elicitation, вопрос задаётся формой: confirm из аргументов тогда не
     * учитывается, иначе модель обходила бы вопрос сама. Если не умеет (или ответ идёт без
     * потока), нужен аргумент confirm: true. Модель получает отказ с просьбой спросить
     * человека в чате и повторить вызов.
     *
     *     if ($refusal = $ctx->confirm("Удалить блок {$name}?")) return $refusal;
     *
     * @return ?Result null — подтверждено; иначе готовый результат с отказом
     */
    public function confirm(string $message, string $title = 'Подтвердить'): ?Result {
        if ($this->elicitor->supported()) {
            $answer = $this->elicitor->ask($message, $this->forms()->confirm('confirmed', $title));
            $action = $answer['action'] ?? 'cancel';

            if ($action === 'accept') {
                $value = Forms::match($answer['content']['confirmed'] ?? null, ['true' => 'Да', 'false' => 'Нет'], [
                    'true'  => ['да', 'yes', 'ок', 'ok', 'подтвер'],
                    'false' => ['нет', 'no', 'отмен', 'cancel'],
                ]);

                if ($value === 'true') return null;
            }

            if ($action === 'accept' || $action === 'decline')
                return Result::error("Человек отказался: {$message}\nЭто окончательное решение: не повторяйте вызов без его просьбы.");

            return Result::error("Человек не подтвердил операцию (закрыл вопрос или не ответил): {$message}\nЭто не отказ: спросите его в чате, прежде чем вызывать снова.");
        }

        if (($this->arguments['confirm'] ?? null) === true)
            return null;

        return Result::error("Нужно подтверждение человека: {$message}\nСпросите человека в чате и, если он согласен, повторите вызов с confirm: true.");
    }

    /** Свойство confirm для inputSchema инструментов, вызывающих confirm(). */
    public static function confirmProperty(): array {
        return [
            'type'        => 'boolean',
            'description' => 'true — человек подтвердил операцию в чате. Нужен, только если клиент не умеет спрашивать человека сам (elicitation); иначе вопрос задаст сервер.',
        ];
    }
}
