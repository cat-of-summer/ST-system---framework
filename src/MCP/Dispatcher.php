<?php

namespace ST_system\MCP;

use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\MCP\Tools\Args;
use ST_system\MCP\Tools\Tool;
use ST_system\MCP\Tools\ToolError;
use ST_system\MCP\Transport\HttpTransport;

/**
 * Протокол MCP без транспорта: реестр инструментов и ответы на initialize, ping, tools/list,
 * tools/call. Транспорт (Streamable HTTP под php-fpm) — HttpTransport, сборка — Server::create()
 * и Server::route().
 *
 * $info:
 * - name, version — serverInfo (по умолчанию 'mcp', '1.0.0');
 * - title — название для человека;
 * - instructions — строка или callable(): string. Клиент показывает её модели при
 *   подключении. Здесь пишут не список инструментов (его модель и так видит), а порядок
 *   работы и правила;
 * - protocols — поддерживаемые версии протокола, новые первыми (по умолчанию из конфига Server).
 */
final class Dispatcher {

    /** Версии протокола, которые знает эта версия фреймворка, новые первыми. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    public const LATEST   = '2025-11-25';

    /** @var array */
    private $info;
    /** @var array<string,array{tool:Tool,middlewares:array}> */
    private $entries = [];

    public function __construct(array $info = []) {
        $this->info = $info + ['name' => 'mcp', 'version' => '1.0.0'];
    }

    /**
     * @param array $middlewares как у Route: класс с handle(), callable или [middleware, ...аргументы];
     *                           вызов — (array $args, Context $ctx, callable $next, ...аргументы): Result
     */
    public function add(Tool $tool, string $prefix = '', array $middlewares = []): void {
        $name = $prefix.$tool->name();

        if (!preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $name))
            throw new \InvalidArgumentException("Недопустимое имя инструмента: {$name}");

        if (isset($this->entries[$name]))
            throw new \LogicException("Инструмент {$name} уже зарегистрирован");

        $this->entries[$name] = ['tool' => $tool, 'middlewares' => $middlewares];
    }

    public function name(): string {
        return (string)$this->info['name'];
    }

    public function version(): string {
        return (string)$this->info['version'];
    }

    /** @return array<string,Tool> имя с префиксом => инструмент */
    public function tools(): array {
        return array_map(function (array $entry) { return $entry['tool']; }, $this->entries);
    }

    public function tool(string $name): ?Tool {
        return isset($this->entries[$name]) ? $this->entries[$name]['tool'] : null;
    }

    public function instructions(): string {
        $instructions = $this->info['instructions'] ?? '';

        return (string)(is_callable($instructions) && !is_string($instructions) ? $instructions() : $instructions);
    }

    /** @return string[] */
    public function protocols(): array {
        $protocols = $this->info['protocols'] ?? Server::config('protocols');

        return is_array($protocols) && $protocols !== [] ? array_values($protocols) : self::VERSIONS;
    }

    public function latest(): string {
        return $this->protocols()[0];
    }

    /** @param mixed $requested */
    public function negotiate($requested): string {
        return is_string($requested) && in_array($requested, $this->protocols(), true) ? $requested : $this->latest();
    }

    public function initializeResult(string $protocol): array {
        $info = ['name' => $this->name(), 'version' => $this->version()];
        if (!empty($this->info['title'])) $info['title'] = (string)$this->info['title'];

        $result = [
            'protocolVersion' => $protocol,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => $info,
        ];

        $instructions = $this->instructions();
        if ($instructions !== '') $result['instructions'] = $instructions;

        return $result;
    }

    public function toolsList(): array {
        $tools = [];

        foreach ($this->entries as $name => $entry) {
            $tool = $entry['tool'];
            $item = [
                'name'        => $name,
                'title'       => $tool->title(),
                'description' => $tool->description(),
                'inputSchema' => self::schema($tool->inputSchema()),
            ];

            if (($output = $tool->outputSchema()) !== null)
                $item['outputSchema'] = self::schema($output);

            $item['annotations'] = ['title' => $tool->title()] + $tool->annotations();

            $tools[] = $item;
        }

        return ['tools' => $tools];
    }

    /** Запрос JSON-RPC (есть id и method) → ответ. */
    public function handle(array $request, Context $context): array {
        $id     = $request['id'] ?? null;
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        switch ($request['method'] ?? '') {
            case 'initialize':
                return self::result($id, $this->initializeResult($this->negotiate($params['protocolVersion'] ?? null)));

            case 'ping':
                return self::result($id, new \stdClass());

            case 'tools/list':
                return self::result($id, $this->toolsList());

            case 'tools/call':
                $name = (string)($params['name'] ?? '');

                if (!isset($this->entries[$name]))
                    return self::error($id, -32602, "Неизвестный инструмент: {$name}.");

                return self::result($id, $this->call($name, $params['arguments'] ?? [], $context)->toArray());
        }

        return self::error($id, -32601, 'Метод не поддерживается: '.($request['method'] ?? '').'.');
    }

    /** Streamable HTTP поверх запроса фреймворка: так сервер подключается к Route. */
    public function handleRequest(Request $request): Response {
        return (new HttpTransport($this))->handleRequest($request);
    }

    /**
     * Ошибки аргументов и отказы операций — результат с isError: модель должна их увидеть.
     *
     * @param mixed $arguments
     */
    private function call(string $name, $arguments, Context $context): Result {
        $entry = $this->entries[$name];
        $tool  = $entry['tool'];

        try {
            $args = Args::validate($tool->inputSchema(), $arguments, $tool->rules());
            $context->withArguments($args);

            return $this->pipeline($tool, $entry['middlewares'])($args, $context);
        } catch (\InvalidArgumentException $e) {
            return Result::error('Неверные параметры: '.$e->getMessage());
        } catch (ToolError $e) {
            return Result::error($e->getMessage(), $e->getData());
        } catch (\Throwable $e) {
            error_log("[mcp] {$name}: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
            return Result::error('Внутренняя ошибка сервера: '.$e->getMessage());
        }
    }

    /** Middleware в порядке объявления оборачивают вызов инструмента: первый — снаружи. */
    private function pipeline(Tool $tool, array $middlewares): callable {
        $next = static function (array $args, Context $context) use ($tool): Result {
            $context->withArguments($args);
            return $tool->call($args, $context);
        };

        foreach (array_reverse($middlewares) as $entry) {
            if (is_array($entry) && !is_callable($entry)) {
                $middleware = array_shift($entry);
                $extra      = $entry;
            } else {
                $middleware = $entry;
                $extra      = [];
            }

            $target = is_string($middleware) && class_exists($middleware) ? [$middleware, 'handle'] : $middleware;
            $inner  = $next;

            $next = static function (array $args, Context $context) use ($target, $extra, $inner): Result {
                $result = call_user_func_array($target, array_merge([$args, $context, $inner], $extra));

                if (!$result instanceof Result)
                    throw new \UnexpectedValueException('Middleware инструмента должен вернуть Result.');

                return $result;
            };
        }

        return $next;
    }

    /** Пустые properties уходят объектом {}, а не массивом []: клиенты проверяют тип. */
    private static function schema(array $schema): array {
        if (array_key_exists('properties', $schema)) {
            if ($schema['properties'] === [])
                $schema['properties'] = new \stdClass();
            elseif (is_array($schema['properties']))
                foreach ($schema['properties'] as $key => $property)
                    if (is_array($property)) $schema['properties'][$key] = self::schema($property);
        }

        if (isset($schema['items']) && is_array($schema['items']))
            $schema['items'] = self::schema($schema['items']);

        return $schema;
    }

    /** @param mixed $id @param mixed $result */
    public static function result($id, $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @param mixed $id */
    public static function error($id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
