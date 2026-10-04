<?php

namespace ST_system\MCP;

use ST_system\HTTP\Request;
use ST_system\HTTP\Response;
use ST_system\HTTP\Route;
use ST_system\Traits\HasConfig;
use ST_system\MCP\Tools\ClosureTool;
use ST_system\MCP\Tools\Tool;
use ST_system\MCP\Tools\ToolDefinition;

/**
 * Объявление MCP-сервера в синтаксисе Route:
 *
 *     Route::point('mcp')->group(function () {
 *         Route::middleware(RequireToken::class);
 *
 *         Server::route('', ['name' => 'app', 'version' => '1.0.0', 'instructions' => '…'], function () {
 *             Server::tool(SomeTool::class);
 *
 *             Server::prefix('vault_')->group(function () {
 *                 Server::middleware(OpenVault::class);
 *                 Server::tool('tree', fn (array $args, Context $ctx) => Result::ok('…'))
 *                     ->description('…')->readOnly();
 *             });
 *         });
 *     });
 *
 * route() регистрирует маршрут Route::any и наследует prefix и middleware группы Route.
 * Замыкание с инструментами выполняется лениво — только на запрос к этому маршруту.
 * create() собирает тот же сервер (Dispatcher) без маршрута: для тестов и других транспортов.
 *
 * Конфиг (setConfig или файл конфига класса):
 * - storage — каталог сессий и ожидающих ответа вопросов;
 * - session_ttl — сколько живёт сессия без запросов, с;
 * - elicitation_timeout — сколько ждать ответа человека, с;
 * - poll_interval — как часто проверять, пришёл ли ответ, с;
 * - ping_interval — как часто слать ping в SSE-поток, пока ждём, с;
 * - protocols — поддерживаемые версии протокола, новые первыми.
 */
final class Server {
    use HasConfig;

    /** @var ?Dispatcher диспетчер, который сейчас собирает замыкание create() */
    private static $building = null;
    /** @var self[] */
    private static $stack = [];

    /** @var string */
    private $prefix;
    /** @var array */
    private $middlewares;

    private function __construct(string $prefix = '', array $middlewares = []) {
        $this->prefix      = $prefix;
        $this->middlewares = $middlewares;
    }

    protected static function getDefaultConfig(): array {
        return [
            'storage'             => '~/storage/mcp',
            'session_ttl'         => 86400,
            'elicitation_timeout' => 300,
            'poll_interval'       => 0.25,
            'ping_interval'       => 15,
            'protocols'           => Dispatcher::VERSIONS,
        ];
    }

    /**
     * MCP-сервер на маршруте $uri текущей группы Route (методы — как у Route::any).
     *
     * @param callable(Dispatcher): void $define объявления Server::tool / prefix / middleware
     */
    public static function route(string $uri, array $info, callable $define): void {
        Route::any($uri, static function (Request $request) use ($info, $define): Response {
            return self::create($info, $define)->handleRequest($request);
        });
    }

    /** @param ?callable(Dispatcher): void $define */
    public static function create(array $info = [], ?callable $define = null): Dispatcher {
        $dispatcher = new Dispatcher($info);

        if ($define === null)
            return $dispatcher;

        // Сохранение и восстановление — на случай create() внутри create() (тесты, вложенные сборки).
        $saved = [self::$building, self::$stack];

        self::$building = $dispatcher;
        self::$stack    = [new self()];

        try {
            $define($dispatcher);
        } finally {
            [self::$building, self::$stack] = $saved;
        }

        return $dispatcher;
    }

    /**
     * Инструмент: имя класса Tool, экземпляр Tool или имя и замыкание
     * fn (array $args, Context $ctx): Result. Для замыкания возвращает ToolDefinition — цепочку
     * описания (title, description, input, readOnly…), для класса — сам экземпляр.
     *
     * @param string|Tool $tool
     * @return ToolDefinition|Tool
     */
    public static function tool($tool, ?callable $handler = null) {
        $group = self::current();

        if ($handler !== null) {
            $definition = new ToolDefinition(new ClosureTool((string)$tool, $handler));
            self::$building->add($definition->tool(), $group->prefix, $group->middlewares);

            return $definition;
        }

        if (is_string($tool)) {
            if (!class_exists($tool))
                throw new \InvalidArgumentException("Класс инструмента не найден: {$tool}");

            $tool = new $tool();
        }

        if (!$tool instanceof Tool)
            throw new \InvalidArgumentException('Server::tool() ждёт класс или экземпляр '.Tool::class.' либо имя и замыкание.');

        self::$building->add($tool, $group->prefix, $group->middlewares);

        return $tool;
    }

    /** Группа с префиксом имён инструментов; middleware родителя наследуются. */
    public static function prefix(string $prefix): self {
        $parent = self::current();

        return new self($parent->prefix.$prefix, $parent->middlewares);
    }

    /**
     * Middleware инструментов до конца текущей группы, как Route::middleware.
     * Элемент — класс со статическим handle(), callable или [middleware, ...аргументы];
     * вызов — (array $args, Context $ctx, callable $next, ...аргументы): Result.
     *
     * @param mixed $middlewares один middleware или список
     */
    public static function middleware($middlewares): self {
        $group = self::current();
        $group->middlewares = array_merge(
            $group->middlewares,
            is_array($middlewares) && !is_callable($middlewares) ? $middlewares : [$middlewares]
        );

        return $group;
    }

    public function group(callable $define): void {
        self::current();

        self::$stack[] = $this;

        try {
            $define();
        } finally {
            array_pop(self::$stack);
        }
    }

    private static function current(): self {
        if (self::$building === null || self::$stack === [])
            throw new \LogicException('Инструменты и группы MCP объявляются внутри Server::route() или Server::create().');

        return end(self::$stack);
    }
}
