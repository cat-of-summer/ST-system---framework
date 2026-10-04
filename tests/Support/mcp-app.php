<?php

// Мини-приложение для McpHttpTest: Route + Server::route под php -S.
// ST_MCP_ROOT — корень приложения (там лягут сессии MCP), токен — 'secret'.

require __DIR__.'/../../vendor/autoload.php';

use ST_system\HTTP\Request;
use ST_system\HTTP\Route;
use ST_system\MCP\Context;
use ST_system\MCP\Result;
use ST_system\MCP\Server;

$_SERVER['DOCUMENT_ROOT'] = getenv('ST_MCP_ROOT');

Server::setConfig(['poll_interval' => 0.05, 'ping_interval' => 1, 'elicitation_timeout' => 20]);

Route::point('mcp')->group(function () {
    Route::middleware(function (Request $request) {
        if ($request->headers('Authorization') !== 'Bearer secret')
            throw new \RuntimeException('Нужен токен.', 401);
    });

    Server::route('', ['name' => 'e2e', 'version' => '1.0.0', 'instructions' => 'Тестовый сервер.'], function () {
        Server::tool('echo', function (array $args, Context $ctx) {
            return Result::ok('эхо', [
                'text'   => $args['text'],
                'client' => $ctx->session()['client']['name'] ?? null,
                'trace'  => $ctx->request()->headers('X-Trace'),
            ]);
        })
            ->title('Эхо')
            ->description('Возвращает текст.')
            ->input(['text' => ['type' => 'string', 'description' => 'текст', 'required' => true]])
            ->readOnly();

        Server::tool('remove', function (array $args, Context $ctx) {
            if ($refusal = $ctx->confirm("Удалить {$args['id']}?")) return $refusal;

            return Result::ok("Удалено: {$args['id']}.");
        })
            ->title('Удаление')
            ->description('Удаляет запись после подтверждения человека.')
            ->input(['id' => ['type' => 'string', 'description' => 'id записи', 'required' => true]])
            ->destructive()
            ->confirms();
    });
});

Route::handleRequest();
