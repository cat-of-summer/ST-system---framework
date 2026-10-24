<?php

namespace ST_system\Tests\Unit;

use ST_system\Debug;
use ST_system\Main;
use ST_system\Tests\TestCase;
use ST_system\View;

/**
 * Место вызова (Main::caller): первый кадр стека вне фреймворка и вне skip - на любой глубине
 * вложенности файлов проекта и любой глубине прохода через сам фреймворк. На нём стоят
 * относительные пути (Main::preparePath с числовой базой, File, Loader) и строка "откуда
 * вызвано" у Debug.
 */
final class CallerTest extends TestCase {

    /**
     * Цепочка файлов f1 → f2 → … → fN: каждый возвращает closure, которая подключает следующий и
     * зовёт его closure; последний выполняет $body. Возвращает пути файлов по порядку.
     *
     * @return string[]
     */
    private function chain(int $length, string $body, string $dir = ''): array {
        $dir   = $dir !== '' ? $dir : $this->tmpDir('chain');
        $files = [];

        for ($i = 1; $i <= $length; $i++) $files[] = str_replace('\\', '/', "{$dir}/f{$i}.php");

        foreach ($files as $i => $file) {
            $code = isset($files[$i + 1])
                ? "<?php\nreturn function (...\$args) {\n    return (require ".var_export($files[$i + 1], true).")(...\$args);\n};\n"
                : "<?php\nreturn function (...\$args) {\n    {$body}\n};\n";

            file_put_contents($file, $code);
        }

        return $files;
    }

    private function enter(array $files, ...$args) {
        return (require $files[0])(...$args);
    }

    /**
     * Массив PHP-кодом в одну строку. Многострочный аргумент сдвинул бы строку вызова: PHP 7.4
     * пишет в кадр строку, где вызов закончился.
     */
    private static function inline(array $value): string {
        return str_replace("\n", ' ', var_export($value, true));
    }

    private static function self(): string {
        return str_replace('\\', '/', __FILE__);
    }

    public function chainLengths(): array {
        return array_combine(array_map(fn($n) => "length {$n}", range(1, 8)), array_map(fn($n) => [$n], range(1, 8)));
    }

    // ---- Main::caller на цепочках файлов проекта --------------------------------------------

    /** @dataProvider chainLengths */
    public function testDeepestFileIsTheCaller(int $length): void {
        $files  = $this->chain($length, 'return \ST_system\Main::caller();');
        $caller = $this->enter($files);

        $this->assertSame(end($files), $caller['file']);
        $this->assertSame(3, $caller['line']); // строка вызова в теле closure
        $this->assertSame('caller', $caller['function']);
        $this->assertSame(Main::class, $caller['class']);
        $this->assertSame('::', $caller['type']);
        $this->assertSame(0, $caller['depth']);
    }

    /** @dataProvider chainLengths */
    public function testSkippingFilesWalksUpTheChain(int $length): void {
        $files = $this->chain($length, 'return \ST_system\Main::caller(...$args);');

        // Пропуск k последних файлов - место вызова в файле перед ними.
        for ($k = 1; $k < $length; $k++) {
            $skip   = array_slice($files, -$k);
            $caller = $this->enter($files, $skip);

            $this->assertSame($files[$length - $k - 1], $caller['file'], "skip {$k} of {$length}");
            $this->assertSame(3, $caller['line']);
        }

        // Пропущены все - место вызова в этом тесте (enter()).
        $this->assertSame(self::self(), $this->enter($files, $files)['file']);
    }

    /** @dataProvider chainLengths */
    public function testSkippingDirectorySkipsWholeChain(int $length): void {
        $files = $this->chain($length, 'return \ST_system\Main::caller(...$args);');

        $this->assertSame(self::self(), $this->enter($files, [dirname($files[0])])['file']);
        $this->assertSame(self::self(), $this->enter($files, [dirname($files[0]).'/'])['file']);
    }

    public function testDepthPointsIntoTheGivenTrace(): void {
        $files = $this->chain(4, '$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS); return [$t, \ST_system\Main::caller($args[0], $t)];');
        [$trace, $caller] = $this->enter($files, array_slice($files, -2));

        $this->assertSame($files[1], $caller['file']);
        $this->assertSame($caller['file'], str_replace('\\', '/', $trace[$caller['depth']]['file']));
        $this->assertSame($caller['line'], $trace[$caller['depth']]['line']);
    }

    public function testSkipIsPrefixOfWholeSegments(): void {
        $root = $this->tmpDir('prefix');
        mkdir("{$root}/lib");
        mkdir("{$root}/lib2");

        $files = $this->chain(1, 'return \ST_system\Main::caller(...$args);', "{$root}/lib2");

        // lib не накрывает lib2: совпадение только по целым сегментам пути.
        $this->assertSame($files[0], $this->enter($files, ["{$root}/lib"])['file']);
        $this->assertSame(self::self(), $this->enter($files, ["{$root}/lib2"])['file']);
    }

    public function testSkipAcceptsBackslashes(): void {
        $files = $this->chain(2, 'return \ST_system\Main::caller(...$args);');

        $this->assertSame($files[0], $this->enter($files, [str_replace('/', '\\', $files[1])])['file']);
        $this->assertSame(self::self(), $this->enter($files, [str_replace('/', '\\', dirname($files[0])).'\\'])['file']);
    }

    public function testFramesWithoutFileAreIgnored(): void {
        // call_user_func и array_map дают кадры без file: они не место вызова.
        $files  = $this->chain(1, 'return call_user_func(fn() => array_map(fn() => \ST_system\Main::caller(), [1])[0]);');
        $caller = $this->enter($files);

        $this->assertSame($files[0], $caller['file']);
    }

    public function testCallerFromHere(): void {
        $line   = __LINE__ + 1;
        $caller = Main::caller();

        $this->assertSame(self::self(), $caller['file']);
        $this->assertSame($line, $caller['line']);
        $this->assertSame(self::self(), Main::callerFile());
    }

    public function testNullWhenEverythingIsSkipped(): void {
        $this->assertNull(Main::caller(['/a'], [['function' => 'f', 'file' => '/a/x.php', 'line' => 1], ['function' => 'g', 'file' => '/a/b/y.php', 'line' => 2]]));
        $this->assertNull(Main::callerFile(['/a/x.php', '/a/b'], [['function' => 'f', 'file' => '/a/x.php', 'line' => 1], ['function' => 'g', 'file' => '/a/b/y.php', 'line' => 2]]));
        $this->assertNull(Main::caller([], []));
        $this->assertNull(Main::caller([], [['function' => 'x'], ['function' => 'y', 'file' => '']]));
    }

    public function testTraceOnlyInsideFrameworkGivesNull(): void {
        $src   = str_replace('\\', '/', dirname((new \ReflectionClass(Main::class))->getFileName()));
        $trace = [
            ['function' => 'a', 'file' => "{$src}/View.php", 'line' => 1],
            ['function' => 'b', 'file' => "{$src}/Storage/File.php", 'line' => 2],
        ];

        $this->assertNull(Main::caller([], $trace));

        $trace[] = ['function' => 'c', 'file' => '/project/index.php', 'line' => 3, 'class' => 'X', 'type' => '->'];
        $this->assertSame(
            ['file' => '/project/index.php', 'line' => 3, 'function' => 'c', 'class' => 'X', 'type' => '->', 'depth' => 2],
            Main::caller([], $trace)
        );
    }

    // ---- Через фреймворк: глубина его кадров не важна -----------------------------------------

    /** @dataProvider chainLengths */
    public function testPreparePathIsRelativeToCallingFile(int $length): void {
        $files = $this->chain($length, 'return \ST_system\Main::preparePath("data/x.json");');

        $this->assertSame(dirname(end($files)).'/data/x.json', $this->enter($files));
    }

    /** @dataProvider chainLengths */
    public function testFileMakeIsRelativeToCallingFile(int $length): void {
        $files = $this->chain($length, 'return \ST_system\Storage\File::make("data/x.json")->getPathname();');

        $this->assertSame(dirname(end($files)).'/data/x.json', str_replace('\\', '/', $this->enter($files)));
    }

    /** @dataProvider chainLengths */
    public function testLoaderRegisterDirWithoutDir(int $length): void {
        $dir   = $this->tmpDir('loader');
        $ns    = 'CallerFixture'.bin2hex(random_bytes(4));
        mkdir("{$dir}/lib/Sub", 0777, true);
        file_put_contents("{$dir}/lib/Sub/Thing.php", "<?php namespace {$ns}\\Sub; final class Thing { const OK = 'ok'; }");

        // Последний файл цепочки регистрирует lib без __DIR__: путь - от его каталога.
        $files = $this->chain($length, "\\ST_system\\Loader::registerDir('lib', ['prefix' => '{$ns}']); return \\{$ns}\\Sub\\Thing::OK;", $dir);

        $this->assertSame('ok', $this->enter($files));
    }

    public function testLoaderRequireIsRelativeToCallingFile(): void {
        $dir = $this->tmpDir('require');
        file_put_contents("{$dir}/inc.php", '<?php $GLOBALS["__caller_inc"] = __FILE__;');

        $files = $this->chain(3, '\ST_system\Loader::require("inc.php"); return $GLOBALS["__caller_inc"];', $dir);

        $this->assertSame("{$dir}/inc.php", str_replace('\\', '/', $this->enter($files)));
        unset($GLOBALS['__caller_inc']);
    }

    public function testTemplateAndSlotClosureSeeTheirOwnFiles(): void {
        $dir = $this->tmpDir('view');
        mkdir("{$dir}/c");
        file_put_contents("{$dir}/c/inner.php", '<?= \ST_system\Main::callerFile() ?>|<?php \ST_system\View::slot(); ?>');
        file_put_contents("{$dir}/c/outer.php", '<?php \ST_system\View::c("inner", [], function () { echo \ST_system\Main::callerFile(); }); ?>');

        $source = View::config('source');
        View::setConfig(['source' => ['c' => "{$dir}/c"], 'contributors' => [], 'cache' => ['use' => false]]);
        self::setStatic(View::class, 'sources', null);

        try {
            // inner: свой файл; closure слота: файл outer, где она написана - через кадры View.
            $this->assertSame("{$dir}/c/inner.php|{$dir}/c/outer.php", str_replace('\\', '/', (string)View::c('outer')));
        } finally {
            View::setConfig(['source' => $source]);
            self::setStatic(View::class, 'sources', null);
        }
    }

    // ---- Debug: строка "откуда вызвано" -------------------------------------------------------

    /** @dataProvider chainLengths */
    public function testDebugShortLinePointsToCallingFile(int $length): void {
        $log   = $this->tmpDir('dbg');
        $cfg   = self::inline(['dir' => $log, 'file' => 'x.log', 'pre' => false, 'time' => false, 'output_type' => 'json']);
        $files = $this->chain($length, "\\ST_system\\Debug::toFile(1, {$cfg});");

        $this->enter($files);

        $this->assertStringContainsString(end($files).' on line 3.', (string)file_get_contents("{$log}/x.log"));
    }

    public function testDebugSkipsProjectWrappers(): void {
        $log  = $this->tmpDir('dbg');
        $cfg  = self::inline(['dir' => $log, 'file' => 'w.log', 'pre' => false, 'time' => false, 'output_type' => 'json']);
        $wrap = $this->tmpDir('wrap').'/helpers.php';
        $fn   = 'caller_wrap_'.bin2hex(random_bytes(4));
        file_put_contents($wrap, "<?php function {$fn}(\$v) { \\ST_system\\Debug::toFile(\$v, {$cfg}); }");
        require $wrap;

        $files = $this->chain(2, "{$fn}(1);");

        Debug::setConfig(['caller' => ['skip' => [$wrap]]]);
        try {
            $this->enter($files);
        } finally {
            Debug::setConfig(['caller' => ['skip' => []]]);
        }

        // Обёртка пропущена: строка - вызов обёртки в файле цепочки.
        $this->assertStringContainsString("{$fn}() in {$files[1]} on line 3.", (string)file_get_contents("{$log}/w.log"));
    }

    /** @dataProvider chainLengths */
    public function testDebugChainStartsAtCallingFile(int $length): void {
        $log   = $this->tmpDir('dbg');
        $cfg   = self::inline(['dir' => $log, 'file' => 'c.log', 'pre' => false, 'time' => false, 'output_type' => 'json', 'backtrace' => true]);
        $files = $this->chain($length, "\\ST_system\\Debug::toFile(1, {$cfg});");

        $this->enter($files);

        $lines = explode("\n", (string)file_get_contents("{$log}/c.log"));

        // Первая строка - вызов в последнем файле, дальше по одному уровню на каждый файл цепочки.
        $this->assertStringStartsWith('↘ ', $lines[0]);
        $this->assertStringContainsString(end($files).' on line 3.', $lines[0]);

        foreach (array_reverse(array_slice($files, 0, -1)) as $i => $file)
            $this->assertStringContainsString($file.' on line 3.', $lines[$i + 1], "level ".($i + 1));
    }
}
