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

    public function testDefinitionDescribesTheSignature(): void {
        $definition = RestCommand::definition();

        $this->assertSame(
            [
                ['name' => 'target', 'required' => true, 'default' => null, 'rest' => false],
                ['name' => 'text', 'required' => true, 'default' => null, 'rest' => true],
            ],
            $definition['arguments']
        );
        $this->assertSame(['depth'], array_keys($definition['options']));
        $this->assertSame('3', $definition['options']['depth']['default']);
    }

    public function testRestArgumentTakesTheTailAsOneString(): void {
        $command = new RestCommand(['a', 'one', 'two', 'three']);

        $this->assertSame(['target' => 'a', 'text' => 'one two three'], $command->handle());
    }

    public function testOptionalRestArgument(): void {
        $this->assertSame(['text' => null], (new OptionalRestCommand())->handle());
        $this->assertSame(['text' => 'one two'], (new OptionalRestCommand(['one', 'two']))->handle());
    }

    public function testMissingArgumentCanBeHandledBySubclass(): void {
        $command = new SoftCommand();

        $this->assertSame(['source' => null], $command->handle());
        $this->assertSame(['source'], SoftCommand::$missing);
    }

    public function testWithInputReturnsACopy(): void {
        $command = new SoftCommand();
        $bound = $command->withInput(['x']);

        $this->assertNotSame($command, $bound);
        $this->assertInstanceOf(SoftCommand::class, $bound);
        $this->assertSame(['source' => 'x'], $bound->handle());
        $this->assertSame(['source' => null], $command->handle());
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

final class RestCommand extends Command {

    protected static string $signature = 'say {target} {text*} {--depth=3}';

    public function handle() {
        return $this->argument();
    }
}

final class OptionalRestCommand extends Command {

    protected static string $signature = 'say {text?*}';

    public function handle() {
        return $this->argument();
    }
}

final class SoftCommand extends Command {

    public static array $missing = [];

    protected static string $signature = 'soft {source}';

    protected static function missingArgument(string $name): void {
        self::$missing[] = $name;
    }

    public function handle() {
        return $this->argument();
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
