<?php

namespace ST_system\Tests\Unit;

use ST_system\Lang;
use ST_system\Tests\TestCase;

/**
 * Фикстура повторяет устройство настоящего хранилища: в корне только каталоги,
 * фразы лежат в `<каталог>/<локаль>.php|json`. Каталог даёт ключу префикс.
 */
final class LangTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = $this->tmpDir('lang');

        $this->writeFile("{$this->dir}/common/en.php", '<?php return '.var_export([
            'hello'   => 'Hello, :name!',
            'apples'  => 'one apple|:count apples',
            'nested'  => ['deep' => ['key' => 'Deep']],
        ], true).';');

        $this->writeFile("{$this->dir}/common/ru.php", '<?php return '.var_export([
            'hello'  => 'Привет, :name!',
            'apples' => ['яблоко', 'яблока', 'яблок'],
        ], true).';');

        $this->writeFile("{$this->dir}/fallback/en.php", '<?php return ["only_en" => "English only"];');
        $this->writeFile("{$this->dir}/pages/ru.json", json_encode(['title' => 'Страница', 'copy' => 'Общая']));
        $this->writeFile("{$this->dir}/pages/en.json", json_encode(['title' => 'Page', 'footer' => 'Footer']));
        $this->writeFile("{$this->dir}/pages/index/ru.php", '<?php return ["title" => "Главная"];');
        $this->writeFile("{$this->dir}/admin/en.php", '<?php return ["panel" => "Admin panel"];');

        Lang::setConfig([
            'dir'      => $this->dir,
            'locale'   => 'ru',
            'fallback' => 'en',
            'cache'    => ['use' => false],
            'plural'   => [],
        ]);

        // Строковый ключ заменяет поддерево целиком, массив — сливает по ключам.
        Lang::setConfig('source', ['admin' => 'admin', 'page' => 'pages']);
    }

    public function testGetWithPlaceholders(): void {
        $this->assertSame('Привет, анна!', Lang::get('common.hello', ['name' => 'анна']));
        $this->assertSame('Hello, Ann!', Lang::get('common.hello', ['name' => 'Ann'], null, 'en'));
    }

    public function testPlaceholderCaseVariants(): void {
        Lang::set('greet', ':name, :Name, :NAME');

        $this->assertSame('анна, Анна, АННА', Lang::get('greet', ['name' => 'анна']));
    }

    public function testFallbackDefaultAndMissingKey(): void {
        $this->assertSame('English only', Lang::get('fallback.only_en'));
        $this->assertSame('Deep', Lang::get('common.nested.deep.key', [], null, 'en'));
        $this->assertSame('dflt', Lang::get('no.such.key', [], 'dflt'));
        $this->assertSame('no.such.key', Lang::get('no.such.key'));
    }

    public function testHas(): void {
        $this->assertTrue(Lang::has('common.hello'));
        $this->assertTrue(Lang::has('fallback.only_en'));
        $this->assertFalse(Lang::has('no.such.key'));
    }

    public function testParentPhrasesAreInheritedByChildScope(): void {
        $this->assertSame('Главная', Lang::get('pages.index.title'));
        // page:index.copy -> index.copy -> copy (из pages/ru.json).
        $this->assertSame('Главная', Lang::page('index.title'));
        $this->assertSame('Общая', Lang::page('index.copy'));
    }

    public function testScopeMergesOwnPhrasesAlongPathWithFallback(): void {
        $this->assertSame(
            ['title' => 'Главная', 'footer' => 'Footer', 'copy' => 'Общая'],
            Lang::get('pages.index')
        );

        // Несуществующий хвост наследует фразы предков (docs/src/Lang.php.md, «Чтение scope»).
        $this->assertSame(['title' => 'Страница', 'footer' => 'Footer', 'copy' => 'Общая'], Lang::get('pages.zzz'));
    }

    public function testChoice(): void {
        $this->assertSame('яблоко', Lang::choice('common.apples', 1));
        $this->assertSame('яблока', Lang::choice('common.apples', 3));
        $this->assertSame('яблок', Lang::choice('common.apples', 11));
        $this->assertSame('one apple', Lang::choice('common.apples', 1, [], null, 'en'));
        $this->assertSame('5 apples', Lang::choice('common.apples', 5, [], null, 'en'));
    }

    public function testCustomPluralRule(): void {
        Lang::setConfig('plural', ['en' => fn(int $n) => $n === 0 ? 1 : 0]);

        $this->assertSame('one apple', Lang::choice('common.apples', 5, [], null, 'en'));
        $this->assertSame('0 apples', Lang::choice('common.apples', 0, [], null, 'en'));
    }

    public function testRuntimeSetOverridesFiles(): void {
        $key = 'runtime_'.bin2hex(random_bytes(3));

        Lang::set($key, 'Значение');
        Lang::set(['group' => [$key => 'В группе']]);
        Lang::set('common.hello', 'Переопределено');

        $this->assertSame('Значение', Lang::get($key));
        $this->assertSame('В группе', Lang::get("group.{$key}"));
        $this->assertSame('Переопределено', Lang::get('common.hello'));
        $this->assertSame($key, Lang::get($key, [], null, 'de'));
    }

    public function testNamedSource(): void {
        $this->assertSame('Admin panel', Lang::admin('panel'));
        $this->assertSame('Admin panel', Lang::get('admin:panel'));
    }

    public function testSourceCallEventCanRewriteKey(): void {
        Lang::on('source_call', function (string $source, &$key) {
            if ($source === 'admin' && $key === 'alias') $key = 'panel';
        });

        $this->assertSame('Admin panel', Lang::admin('alias'));
    }

    public function testUnknownSourceThrows(): void {
        $this->expectException(\BadMethodCallException::class);

        Lang::nothing('x');
    }

    public function testSourceOutsideDirIsRejected(): void {
        Lang::setConfig('source', ['bad' => '../outside']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("источник 'bad' выходит за пределы dir");

        Lang::get('bad:x');
    }

    public function testLocaleFromClosure(): void {
        Lang::setConfig('locale', fn() => 'en');

        $this->assertSame('en', Lang::config('locale'));
        $this->assertSame('Hello, A!', Lang::get('common.hello', ['name' => 'A']));
    }

    public function testUsedFilesAreTracked(): void {
        Lang::get('common.hello');

        $this->assertContains("{$this->dir}/common/ru.php", Lang::getUsedFiles());
    }

    public function testCompiledCacheIsRebuiltWhenFileChanges(): void {
        $cacheDir = $this->tmpDir('lang-cache');
        Lang::setConfig(['cache' => ['use' => true, 'dir' => $cacheDir]]);

        $this->assertSame('Привет, A!', Lang::get('common.hello', ['name' => 'A']));
        $this->assertNotEmpty(glob("{$cacheDir}/*"));

        $this->writeFile("{$this->dir}/common/ru.php", '<?php return ["hello" => "Здравствуйте, :name!"];');
        touch("{$this->dir}/common/ru.php", time() + 10);

        // Сбросить память процесса, оставив скомпилированную карту на диске.
        Lang::setConfig('dir', $this->dir);

        $this->assertSame('Здравствуйте, A!', Lang::get('common.hello', ['name' => 'A']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLockLocale(): void {
        Lang::lockLocale('ru');

        $this->assertSame('ru', Lang::config('locale'));

        $this->expectException(\LogicException::class);
        Lang::setConfig('locale', 'en');
    }
}
