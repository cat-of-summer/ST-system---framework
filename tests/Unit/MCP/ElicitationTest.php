<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\MCP\Context;
use ST_system\MCP\Elicitation\ChannelElicitor;
use ST_system\MCP\Elicitation\Forms;
use ST_system\MCP\Elicitation\NoElicitation;
use ST_system\MCP\Result;
use ST_system\MCP\Server;
use ST_system\MCP\State\PendingStore;
use ST_system\Tests\Support\FakeElicitor;
use ST_system\Tests\Support\MemoryChannel;
use ST_system\Tests\TestCase;

final class ElicitationTest extends TestCase {

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Вопрос человеку уходит в поток запроса, ответ приходит через PendingStore. */
    public function testChannelElicitorRoundTrip(): void {
        $channel = new MemoryChannel();

        // «Пауза» опроса — момент, когда клиент прислал ответ отдельным POST.
        $elicitor = new ChannelElicitor($channel, self::SESSION, 5, 0.25, 15, function () use ($channel): void {
            $question = end($channel->sent);
            PendingStore::answer(self::SESSION, $question['id'], ['jsonrpc' => '2.0', 'id' => $question['id'],
                'result' => ['action' => 'accept', 'content' => ['verdict' => 'yes']]]);
        });

        $answer = $elicitor->ask('Верно?', ['type' => 'object', 'properties' => []]);

        $this->assertSame(['action' => 'accept', 'content' => ['verdict' => 'yes']], $answer);
        $this->assertSame('elicitation/create', $channel->sent[0]['method']);
        $this->assertSame('Верно?', $channel->sent[0]['params']['message']);
        $this->assertArrayNotHasKey('mode', $channel->sent[0]['params']);
    }

    public function testErrorAnswerIsCancel(): void {
        $channel  = new MemoryChannel();
        $elicitor = new ChannelElicitor($channel, self::SESSION, 5, 0.25, 15, function () use ($channel): void {
            $question = end($channel->sent);
            PendingStore::answer(self::SESSION, $question['id'], ['jsonrpc' => '2.0', 'id' => $question['id'], 'error' => ['code' => -1, 'message' => 'нет']]);
        });

        $this->assertSame(['action' => 'cancel'], $elicitor->ask('?', []));
    }

    public function testChannelElicitorTimesOutAndPings(): void {
        $channel  = new MemoryChannel();
        $elicitor = new ChannelElicitor($channel, self::SESSION, 3, 1.0, 1, function () {});

        $this->assertSame(['action' => 'timeout'], $elicitor->ask('?', []));
        $this->assertGreaterThan(0, $channel->pings);
    }

    public function testChannelElicitorStopsWhenClientLeaves(): void {
        $channel = new MemoryChannel();
        $channel->gone = true;

        $elicitor = new ChannelElicitor($channel, self::SESSION, 300, 1.0, 1, function () {});

        $this->assertSame(['action' => 'timeout'], $elicitor->ask('?', []));
        $this->assertSame(1, $channel->pings, 'ушедшего клиента не ждут все 300 секунд');
    }

    public function testConfirmAcceptedByHuman(): void {
        $elicitor = new FakeElicitor([['action' => 'accept', 'content' => ['confirmed' => true]]]);

        $this->assertNull((new Context($elicitor))->confirm('Удалить блок?'));
        $this->assertSame('Удалить блок?', $elicitor->asked[0]['message']);
        $this->assertSame('boolean', $elicitor->asked[0]['schema']['properties']['confirmed']['type']);
    }

    /** Клиент без кнопок присылает напечатанный ответ. */
    public function testConfirmUnderstandsTypedAnswers(): void {
        $this->assertNull((new Context(new FakeElicitor([['action' => 'accept', 'content' => ['confirmed' => 'Да']]])))->confirm('?'));
        $this->assertNotNull((new Context(new FakeElicitor([['action' => 'accept', 'content' => ['confirmed' => 'нет']]])))->confirm('?'));
    }

    public function testConfirmRefusals(): void {
        $no = (new Context(new FakeElicitor([['action' => 'accept', 'content' => ['confirmed' => false]]])))->confirm('Удалить?');
        $this->assertTrue($no->error);
        $this->assertStringStartsWith('Человек отказался', $no->text);

        $declined = (new Context(new FakeElicitor([['action' => 'decline']])))->confirm('Удалить?');
        $this->assertStringStartsWith('Человек отказался', $declined->text);

        $silent = (new Context(new FakeElicitor([['action' => 'timeout']])))->confirm('Удалить?');
        $this->assertStringStartsWith('Человек не подтвердил', $silent->text);
    }

    /** С elicitation аргумент confirm не обходит вопрос человеку. */
    public function testConfirmArgumentIgnoredWhenHumanCanBeAsked(): void {
        $elicitor = new FakeElicitor([['action' => 'decline']]);
        $context  = (new Context($elicitor))->withArguments(['confirm' => true]);

        $this->assertNotNull($context->confirm('Удалить?'));
        $this->assertCount(1, $elicitor->asked);
    }

    public function testConfirmWithoutElicitationNeedsArgument(): void {
        $refusal = (new Context(new NoElicitation()))->confirm('Удалить блок «db»?');
        $this->assertTrue($refusal->error);
        $this->assertStringContainsString('confirm: true', $refusal->text);

        $this->assertNull((new Context(new NoElicitation()))->withArguments(['confirm' => true])->confirm('Удалить?'));
    }

    public function testConfirmsAddsParameterAndStreams(): void {
        $dispatcher = Server::create([], function () {
            Server::tool('remove', function (array $args, Context $ctx) {
                return $ctx->confirm("Удалить {$args['id']}?") ?? Result::ok('удалено');
            })
                ->confirms()
                ->input(['id' => ['type' => 'string', 'description' => 'id', 'required' => true]])
                ->destructive();
        });

        $tool = $dispatcher->tool('remove');
        $this->assertTrue($tool->streams());
        $this->assertTrue($tool->asksHuman());
        $this->assertSame(['id', 'confirm'], array_keys($tool->inputSchema()['properties']), 'confirms() до input() не теряется');

        $call = function (array $arguments) use ($dispatcher) {
            return $dispatcher->handle(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'remove', 'arguments' => $arguments]],
                new Context(new NoElicitation())
            )['result'];
        };

        $this->assertTrue($call(['id' => 'db'])['isError']);
        $this->assertSame('удалено', $call(['id' => 'db', 'confirm' => true])['content'][0]['text']);
    }

    public function testFormsByProtocolVersion(): void {
        $new = (new Forms('2025-11-25'))->choice('verdict', 'Верно?', ['yes' => 'Да', 'no' => 'Нет']);
        $this->assertSame([['const' => 'yes', 'title' => 'Да'], ['const' => 'no', 'title' => 'Нет']], $new['properties']['verdict']['oneOf']);

        $old = (new Forms('2025-06-18'))->choice('verdict', 'Верно?', ['yes' => 'Да', 'no' => 'Нет']);
        $this->assertSame(['yes', 'no'], $old['properties']['verdict']['enum']);
        $this->assertSame(['Да', 'Нет'], $old['properties']['verdict']['enumNames']);

        $text = (new Forms())->text('note', 'Замечание', '', 'черновик', false);
        $this->assertSame([], $text['required']);
        $this->assertSame('черновик', $text['properties']['note']['default']);
    }

    public function testFormsMatch(): void {
        $options = ['yes' => 'Да, верно', 'edit' => 'Поправить'];
        $aliases = ['yes' => ['да', 'ок'], 'edit' => ['правк', 'поправ']];

        $this->assertSame('yes', Forms::match('yes', $options));
        $this->assertSame('edit', Forms::match('2', $options));
        $this->assertSame('yes', Forms::match('Да, верно!', $options));
        $this->assertSame('edit', Forms::match('нужна правка', $options, $aliases));
        $this->assertNull(Forms::match('интернет', ['no' => 'Нет'], ['no' => ['нет']]), 'корень ищется с начала слова');
        $this->assertNull(Forms::match(['x'], $options));
        $this->assertSame('true', Forms::match(true, ['true' => 'Да', 'false' => 'Нет']));
    }
}
