<?php

namespace ST_system\Tests\Unit;

use ST_system\Config;
use ST_system\Tests\TestCase;

final class ConfigTest extends TestCase {

    private array $touched = [];

    protected function tearDown(): void {
        foreach ($this->touched as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        Config::reload();

        parent::tearDown();
    }

    private function name(string $base): string {
        return $this->touched[] = 'ST_TEST_'.$base.'_'.strtoupper(bin2hex(random_bytes(3)));
    }

    public function testEnvLookupOrder(): void {
        $name = $this->name('ORDER');

        putenv("{$name}=from-getenv");
        $this->assertSame('from-getenv', Config::env($name));

        Config::reload();
        $_SERVER[$name] = 'from-server';
        $this->assertSame('from-server', Config::env($name));

        Config::reload();
        $_ENV[$name] = 'from-env';
        $this->assertSame('from-env', Config::env($name));
    }

    public function testEnvIsCachedUntilReload(): void {
        $name = $this->name('CACHE');

        $this->assertSame('fallback', Config::env($name, 'fallback'));

        $_ENV[$name] = 'late';
        $this->assertSame('fallback', Config::env($name, 'fallback'));

        Config::reload();
        $this->assertSame('late', Config::env($name, 'fallback'));
    }

    public function testComposerRootIsDetected(): void {
        $root = Config::env('COMPOSER_ROOT');

        $this->assertFileExists($root.'/composer.json');
        $this->assertSame(realpath(dirname(__DIR__, 2)), realpath($root));
    }

    public function testIni(): void {
        $this->assertSame((string)ini_get('memory_limit'), Config::ini('memory_limit'));
        $this->assertSame('dflt', Config::ini('no.such.ini.key', 'dflt'));
    }

    public function testRuntimeConfigAndImmutableFill(): void {
        Config::setConfig('tests.runtime.value', 1);
        $this->assertSame(1, Config::config('tests.runtime.value'));
        $this->assertSame(['value' => 1], Config::config('tests.runtime'));
        $this->assertSame('d', Config::config('tests.runtime.missing', 'd'));

        Config::fillConfig('tests.fill', ['a' => 1, 'b' => ['c' => 2]]);
        Config::fillConfig('tests.fill', ['a' => 9, 'd' => 3]);
        $this->assertSame(['a' => 1, 'b' => ['c' => 2], 'd' => 3], Config::config('tests.fill'));

        Config::setImmutableConfig('TestsImmutable', 'x', 1);
        Config::fillImmutableConfig('TestsImmutable', '', ['x' => 9, 'y' => 2]);
        $this->assertSame(['x' => 1, 'y' => 2], Config::getImmutableConfig('TestsImmutable'));
        $this->assertSame(2, Config::getImmutableConfig('TestsImmutable', 'y'));
        $this->assertNull(Config::getImmutableConfig('TestsImmutable', 'z'));
    }

    /**
     * init() разрешён один раз на процесс.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitLoadsDotenvAndConfigDirectory(): void {
        $dir = $this->tmpDir('config');

        $this->writeFile("{$dir}/.env", implode("\n", [
            "\xEF\xBB\xBF# comment",
            'PLAIN=value',
            'export EXPORTED=yes',
            'SPACED = trimmed   # inline comment',
            'HASH=a#b',
            'EMPTY=',
            'SINGLE=\'raw $PLAIN \n\'',
            'DOUBLE="esc\tq\" $PLAIN ${EXPORTED}"',
            'MULTI="line1',
            'line2"',
            'REF=$PLAIN-suffix',
            'PRESET=from-file',
            '1INVALID=x',
            'no equals sign',
        ])."\r\n");

        $this->writeFile("{$dir}/app.php", '<?php return ["name" => "php-config", "nested" => ["k" => 1]];');
        $this->writeFile("{$dir}/db.json", '{"host": "localhost", "port": 5432}');
        $this->writeFile("{$dir}/mail", "# ini-like\nFROM = \"robot@example.com\"\nRETRIES=3\n");

        $_SERVER['PRESET'] = 'from-server';

        Config::init(['config_path' => $dir]);

        $this->assertSame('value', $_ENV['PLAIN']);
        $this->assertSame('value', getenv('PLAIN'));
        $this->assertSame('yes', Config::env('EXPORTED'));
        $this->assertSame('trimmed', Config::env('SPACED'));
        $this->assertSame('a#b', Config::env('HASH'));
        $this->assertSame('', Config::env('EMPTY', 'dflt'));
        $this->assertSame('raw $PLAIN \n', Config::env('SINGLE'));
        $this->assertSame("esc\tq\" value yes", Config::env('DOUBLE'));
        $this->assertSame("line1\nline2", Config::env('MULTI'));
        $this->assertSame('value-suffix', Config::env('REF'));
        $this->assertSame('from-server', Config::env('PRESET'));
        $this->assertArrayNotHasKey('1INVALID', $_ENV);

        $this->assertSame('php-config', Config::config('app.name'));
        $this->assertSame(1, Config::config('app.nested.k'));
        $this->assertSame(5432, Config::config('db.port'));
        $this->assertSame('robot@example.com', Config::config('mail.FROM'));
        $this->assertSame('3', Config::config('mail.RETRIES'));
        $this->assertNull(Config::config('missing.key'));

        $this->expectException(\LogicException::class);
        Config::init();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitWithSingleConfigFile(): void {
        $file = $this->writeFile($this->tmpDir('config').'/settings.json', '{"a": {"b": 1}, "c": 2}');

        Config::init(['config_path' => $file, 'dotenv_path' => $this->tmpDir('noenv')]);

        $this->assertSame(1, Config::config('a.b'));
        $this->assertSame(2, Config::config('c'));
    }
}
