<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\MCP\Result;
use ST_system\MCP\Server;
use ST_system\MCP\ToolsDoc;
use ST_system\Tests\TestCase;

final class ToolsDocTest extends TestCase {

    private const LONG = 'Описание инструмента для модели: когда вызывать, что вернёт и что делать дальше с результатом.';

    public function testResultCarriesTextAndStructuredContent(): void {
        $ok = Result::ok('Готово.', ['id' => 'x'])->toArray();

        $this->assertFalse($ok['isError']);
        $this->assertSame(['id' => 'x'], $ok['structuredContent']);
        $this->assertStringStartsWith("Готово.\n\n{", $ok['content'][0]['text']);

        $plain = Result::error('Нет.')->toArray();
        $this->assertTrue($plain['isError']);
        $this->assertSame([['type' => 'text', 'text' => 'Нет.']], $plain['content']);
        $this->assertArrayNotHasKey('structuredContent', $plain);

        $this->assertSame('{}', json_encode(Result::ok('', [])->toArray()['structuredContent']));
    }

    public function testProblemsFindIncompleteDescriptions(): void {
        $doc = new ToolsDoc(Server::create(['instructions' => 'коротко'], function () {
            Server::tool('bare', function () { return Result::ok(''); })
                ->input(['id' => ['type' => 'string', 'required' => true]]);
        }));

        $this->assertSame([
            'instructions сервера пусты или слишком коротки',
            'bare: нет названия для человека',
            'bare: описание короче 80 символов',
            'bare.id: у параметра нет описания',
        ], $doc->problems());
    }

    public function testCompleteServerHasNoProblemsAndRendersMarkdown(): void {
        $doc = new ToolsDoc(Server::create(['instructions' => self::LONG], function () {
            Server::tool('remove', function () { return Result::ok(''); })
                ->title('Удаление')
                ->description(self::LONG)
                ->input([
                    'id'   => ['type' => 'string', 'description' => 'id | блока', 'required' => true],
                    'mode' => ['type' => 'string', 'enum' => ['soft', 'hard'], 'default' => 'soft', 'description' => 'как удалять'],
                ])
                ->destructive()
                ->confirms();
        }), ['title' => 'Справочник', 'note' => 'Собран командой.']);

        $this->assertSame([], $doc->problems());

        $markdown = $doc->markdown();
        $this->assertStringStartsWith("# Справочник\n\n> Собран командой.", $markdown);
        $this->assertStringContainsString('### remove', $markdown);
        $this->assertStringContainsString('**Удаление** · меняет состояние · спрашивает человека · необратимо', $markdown);
        $this->assertStringContainsString('| `id` | string | да | id \| блока |', $markdown);
        $this->assertStringContainsString('| `mode` | `soft` \| `hard` | нет | как удалять По умолчанию: `"soft"`. |', $markdown);
        $this->assertStringContainsString('| `confirm` | boolean | нет |', $markdown);
    }
}
