<?php

namespace ST_system\Tests\Unit;

use ST_system\Config;
use ST_system\Tests\TestCase;

final class ConfigInstanceTest extends TestCase {

    private function php(string $path, string $body): string {
        return $this->writeFile($path, "<?php\nreturn $body;\n");
    }

    public function testTopLevelKeysComeFromFilesAndDirectories(): void {
        $dir = $this->tmpDir('repo');
        $this->php("$dir/settings.php", "['effort' => 'high']");
        $this->writeFile("$dir/cors.json", '{"origins": ["https://a.test"]}');
        $this->php("$dir/policy/tools.php", "['*' => 'R']");

        $repo = new Config($dir);

        $this->assertSame(['cors', 'policy', 'settings'], array_keys($repo->all()));
        $this->assertSame('high', $repo->get('settings.effort'));
        $this->assertSame(['https://a.test'], $repo->get('cors.origins'));
        $this->assertSame('R', $repo->get('policy.tools.*'));
        $this->assertSame('dflt', $repo->get('nope.key', 'dflt'));
        $this->assertTrue($repo->has('policy'));
        $this->assertFalse($repo->has('nope'));
    }

    /** Файл X.php правит только нужное в каталоге X/: словари сливаются глубоко. */
    public function testFileIsMergedOverItsDirectory(): void {
        $dir = $this->tmpDir('repo');
        $this->php("$dir/providers/openrouter.php", "['base_url' => 'https://or.test', 'options' => ['only_free' => false, 'paid' => ['a', 'b']]]");
        $this->php("$dir/providers/deepseek.php", "['base_url' => 'https://ds.test']");
        $this->php("$dir/providers.php", "['openrouter' => ['options' => ['only_free' => true, 'paid' => ['c']]]]");

        $providers = (new Config($dir))->get('providers');

        $this->assertSame('https://or.test', $providers['openrouter']['base_url']);
        $this->assertTrue($providers['openrouter']['options']['only_free']);
        $this->assertSame(['c'], $providers['openrouter']['options']['paid'], 'список заменяется целиком');
        $this->assertSame('https://ds.test', $providers['deepseek']['base_url']);
    }

    public function testNestedDirectoriesAreDeeperKeys(): void {
        $dir = $this->tmpDir('repo');
        $this->php("$dir/policy/paths/customer.php", "['**' => 'R']");
        $this->php("$dir/policy/paths.php", "['owner' => ['**' => 'RWDX']]");

        $paths = (new Config($dir))->get('policy.paths');

        $this->assertSame(['customer' => ['**' => 'R'], 'owner' => ['**' => 'RWDX']], $paths);
    }

    public function testClosuresSurviveTheMerge(): void {
        $dir = $this->tmpDir('repo');
        $this->php("$dir/providers/x.php", "['request' => fn (array \$b): array => \$b + ['a' => 1]]");

        $request = (new Config($dir))->get('providers.x.request');

        $this->assertInstanceOf(\Closure::class, $request);
        $this->assertSame(['a' => 1], $request([]));
    }

    public function testStrictModeRefusesAFileWithoutAnArray(): void {
        $dir = $this->tmpDir('repo');
        $this->writeFile("$dir/settings.php", "<?php\nreturn 'x';\n");

        $this->assertSame(['settings' => []], (new Config($dir, false))->all());

        $this->expectException(\UnexpectedValueException::class);
        (new Config($dir))->all();
    }

    public function testHiddenAndForeignFilesAreIgnored(): void {
        $dir = $this->tmpDir('repo');
        $this->php("$dir/settings.php", "['a' => 1]");
        $this->writeFile("$dir/settings.php.example", "<?php return ['a' => 2];");
        $this->writeFile("$dir/.gitkeep", '');
        $this->writeFile("$dir/README.md", '# x');

        $repo = new Config($dir);

        $this->assertSame(['settings' => ['a' => 1]], $repo->all());
        $this->assertSame(["$dir" . DIRECTORY_SEPARATOR . 'settings.php'], $repo->files());
    }

    public function testChangesAreSeenAfterReload(): void {
        $dir = $this->tmpDir('repo');
        $this->php("$dir/settings.php", "['a' => 1]");
        $repo = new Config($dir);
        $this->assertSame(1, $repo->get('settings.a'));
        $this->assertFalse($repo->changed());
        $hash = $repo->contentHash();

        $this->php("$dir/models.php", "['slots' => ['main' => 'x/y']]");

        $this->assertTrue($repo->changed());
        $this->assertNull($repo->get('models.slots.main'), 'до refresh() читается прежний снимок');
        $repo->refresh();
        $this->assertSame('x/y', $repo->get('models.slots.main'));
        $this->assertFalse($repo->changed());
        $this->assertNotSame($hash, $repo->contentHash());
    }

    public function testMissingDirectoryIsEmpty(): void {
        $repo = new Config($this->tmpDir('repo') . '/none');

        $this->assertSame([], $repo->all());
        $this->assertSame([], $repo->files());
        $this->assertSame([], $repo->load('settings'));
    }
}
