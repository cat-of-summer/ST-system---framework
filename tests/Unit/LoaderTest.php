<?php

namespace ST_system\Tests\Unit;

use ST_system\Loader;
use ST_system\Tests\TestCase;

final class LoaderTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['st_loaded'] = [];
    }

    private function fixtureDir(): string {
        $dir = $this->tmpDir('loader');
        $this->writeFile("{$dir}/a.php", '<?php $GLOBALS["st_loaded"][] = "a";');
        $this->writeFile("{$dir}/sub/b.php", '<?php $GLOBALS["st_loaded"][] = "b";');
        $this->writeFile("{$dir}/note.txt", 'not php');

        return $dir;
    }

    public function testRequireDirectoryRecursively(): void {
        Loader::require($this->fixtureDir());

        $loaded = $GLOBALS['st_loaded'];
        sort($loaded);
        $this->assertSame(['a', 'b'], $loaded);
    }

    public function testRequireWithOptionsArray(): void {
        Loader::require($this->fixtureDir(), ['recursive' => false]);

        $this->assertSame(['a'], $GLOBALS['st_loaded']);
    }

    public function testIncludeOnceSkipsAlreadyIncluded(): void {
        $dir = $this->fixtureDir();

        Loader::include_once("{$dir}/a.php");
        Loader::include_once("{$dir}/a.php");

        $this->assertSame(['a'], $GLOBALS['st_loaded']);
    }

    public function testIncludeSkipsFilesWithSyntaxErrors(): void {
        $dir = $this->tmpDir('broken');
        $this->writeFile("{$dir}/broken.php", '<?php $GLOBALS["st_loaded"][] = "broken"');

        Loader::include("{$dir}/broken.php");

        $this->assertSame([], $GLOBALS['st_loaded']);
    }

    public function testRegisterDirAutoloadsByPrefix(): void {
        $dir = $this->tmpDir('autoload');
        $ns  = 'StFixture'.bin2hex(random_bytes(3));
        $this->writeFile("{$dir}/Models/User.php", "<?php namespace {$ns}\\Models; class User {}");

        Loader::registerDir($dir, ['prefix' => "\\{$ns}\\"]);

        $this->assertTrue(class_exists("{$ns}\\Models\\User"));
        $this->assertFalse(class_exists("{$ns}\\Models\\Missing"));
    }

    public function testRegisterClassMapsSingleFile(): void {
        $dir   = $this->tmpDir('classmap');
        $class = 'StMapped'.bin2hex(random_bytes(3));
        $this->writeFile("{$dir}/lib/impl.php", "<?php namespace Vendor; class {$class} {}");

        Loader::registerClass($dir, $class, 'lib/impl', 'Vendor');

        $this->assertTrue(class_exists("Vendor\\{$class}"));
    }

    public function testUnknownMethodThrows(): void {
        $this->expectException(\Exception::class);

        Loader::load('x');
    }
}
