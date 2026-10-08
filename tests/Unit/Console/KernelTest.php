<?php

namespace ST_system\Tests\Unit\Console;

use ST_system\Console\Kernel;
use ST_system\Tests\TestCase;

final class KernelTest extends TestCase {

    private array $commands;

    protected function setUp(): void {
        parent::setUp();
        $this->commands = self::getStatic(Kernel::class, 'commands');
        self::setStatic(Kernel::class, 'commands', []);
    }

    protected function tearDown(): void {
        self::setStatic(Kernel::class, 'commands', $this->commands);
        parent::tearDown();
    }

    public function testRegisterDirKeysCommandsByName(): void {
        $dir = $this->tmpDir('commands');
        $this->writeFile($dir.'/BackupCron.php', self::commandSource('KernelFixture', 'BackupCron', 'backup_cron {--user}'));
        $this->writeFile($dir.'/Sub/UserCreate.php', self::commandSource('KernelFixture\Sub', 'UserCreate', 'user:create {name} {--f|force}'));
        $this->writeFile($dir.'/OnlyOptions.php', self::commandSource('KernelFixture', 'OnlyOptions', '{--user}'));
        foreach (['BackupCron', 'Sub/UserCreate', 'OnlyOptions'] as $file)
            require_once $dir.'/'.$file.'.php';

        Kernel::registerDir($dir, 'KernelFixture');

        $this->assertSame(
            ['backup_cron' => 'KernelFixture\BackupCron', 'user:create' => 'KernelFixture\Sub\UserCreate'],
            self::getStatic(Kernel::class, 'commands')
        );
    }

    /** handleCLI() завершает процесс через exit, поэтому запускается отдельным PHP. */
    public function testHandleCliRunsCommandDeclaredWithOptions(): void {
        $root = $this->tmpDir('app');
        $this->writeFile(
            $root.'/Console/Commands/BackupCron.php',
            self::commandSource('Console\Commands', 'BackupCron', 'backup_cron {--user}')
        );
        $script = sprintf(
            '<?php require %s;'
            .' putenv("DOCUMENT_ROOT=".%2$s); $_SERVER["DOCUMENT_ROOT"] = %2$s;'
            .' spl_autoload_register(function ($c) { $f = %2$s."/".str_replace("\\\\", "/", $c).".php"; if (is_file($f)) require $f; });'
            .' \ST_system\Console\Kernel::handleCLI(["run", "backup_cron", "--user=5"]);',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export($root, true)
        );
        $file = $this->writeFile($this->tmpDir().'/run.php', $script);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output, $code);

        $this->assertSame(['user=5'], $output);
        $this->assertSame(0, $code);
    }

    private static function commandSource(string $namespace, string $class, string $signature): string {
        return sprintf(
            '<?php namespace %s; class %s extends \ST_system\Console\Command {'
            .' protected static string $signature = %s;'
            .' public function handle() { $this->line("user=".$this->option("user")); } }',
            $namespace, $class, var_export($signature, true)
        );
    }
}
