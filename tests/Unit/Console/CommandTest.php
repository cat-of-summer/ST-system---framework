<?php

namespace ST_system\Tests\Unit\Console;

use ST_system\Console\Command;
use ST_system\Tests\TestCase;

final class CommandTest extends TestCase {

    public function testArgumentsAndOptionsFromSignature(): void {
        $command = SignatureCommand::fetch(['file.txt'], ['f' => true, 'level' => '3']);

        $this->assertInstanceOf(SignatureCommand::class, $command);
        $this->assertSame(
            ['source' => 'file.txt', 'target' => null, 'mode' => 'copy'],
            $command->handle()['arguments']
        );
        $this->assertSame(
            ['force' => true, 'level' => '3', 'format' => 'json', 'no-interaction' => false],
            $command->handle()['options']
        );
    }

    public function testAllPositionalArgumentsAndUnknownOptions(): void {
        $command = new SignatureCommand(['a', 'b', 'move'], ['extra' => 'x', 'n' => true]);

        $this->assertSame(['source' => 'a', 'target' => 'b', 'mode' => 'move'], $command->handle()['arguments']);
        $this->assertSame('x', $command->handle()['options']['extra']);
        $this->assertTrue($command->handle()['options']['no-interaction']);
        $this->assertSame('a', $command->handle()['source']);
    }

    public function testNonInteractiveAskReturnsDefault(): void {
        $command = new SignatureCommand(['a'], ['no-interaction' => true]);

        $this->assertSame('dflt', $command->handle()['asked']);
    }

    public function testGetSignature(): void {
        $this->assertStringStartsWith('copy {source}', SignatureCommand::getSignature());
    }

    public function testGetNameIsFirstWordOfSignature(): void {
        $this->assertSame('copy', SignatureCommand::getName());
        $this->assertSame('backup_cron', OptionsCommand::getName());
        $this->assertSame('cache:clear', BareCommand::getName());
        $this->assertSame('', NamelessCommand::getName());
    }

    /** Команда завершает процесс через exit(1), поэтому запускается отдельным PHP. */
    public function testMissingRequiredArgumentExits(): void {
        $script = sprintf(
            '<?php require %s; require %s; new %s([]);',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export(__FILE__, true),
            SignatureCommand::class
        );
        $file = $this->writeFile($this->tmpDir().'/run.php', $script);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output, $code);

        $this->assertSame(1, $code);
        $this->assertSame(['Missing required argument: source'], $output);
    }
}

final class SignatureCommand extends Command {

    protected static string $signature = 'copy {source} {target?} {mode=copy} {--f|force} {--level=} {--format=json}';

    public function handle() {
        return [
            'arguments' => $this->argument(),
            'options'   => $this->option(),
            'source'    => $this->argument('source'),
            'asked'     => $this->ask('Question?', 'dflt'),
        ];
    }
}

final class OptionsCommand extends Command {

    protected static string $signature = 'backup_cron{--user}';

    public function handle() {}
}

final class BareCommand extends Command {

    protected static string $signature = ' cache:clear ';

    public function handle() {}
}

final class NamelessCommand extends Command {

    protected static string $signature = '{--user}';

    public function handle() {}
}
