<?php

namespace ST_system\Tests\Unit;

use ST_system\Main;
use ST_system\Tests\TestCase;

final class MainTest extends TestCase {

    /** @dataProvider pluralIndexCases */
    public function testPluralIndex(int $n, string $locale, int $expected): void {
        $this->assertSame($expected, Main::pluralIndex($n, $locale));
    }

    public function pluralIndexCases(): array {
        return [
            [1, 'ru', 0], [21, 'ru', 0], [101, 'ru', 0],
            [2, 'ru', 1], [4, 'ru', 1], [22, 'ru', 1],
            [0, 'ru', 2], [5, 'ru', 2], [11, 'ru', 2], [12, 'ru', 2], [14, 'ru', 2], [111, 'ru', 2],
            [-1, 'ru', 0], [-3, 'uk', 1], [7, 'be', 2],
            [1, 'en', 0], [0, 'en', 1], [5, 'en', 1], [-1, 'de', 0],
        ];
    }

    public function testPluralForm(): void {
        $forms = ['файл', 'файла', 'файлов'];

        $this->assertSame('файл', Main::pluralForm(1, $forms));
        $this->assertSame('файла', Main::pluralForm(3, $forms));
        $this->assertSame('файлов', Main::pluralForm(11, $forms));
        $this->assertSame('файл', Main::pluralForm(21, $forms));
        $this->assertSame('файл', Main::pluralForm(-1, $forms));
        $this->assertSame('файла', Main::pluralForm('2', $forms));
        $this->assertSame('files', Main::pluralForm(5, ['file', 'files'], 'en'));
    }

    public function testBasename(): void {
        $this->assertSame('Route', Main::basename('ST_system\HTTP\Route'));
        $this->assertSame('c', Main::basename('a/b/c'));
        $this->assertSame('Plain', Main::basename('Plain'));
    }

    public function testGlue(): void {
        $this->assertSame('cache/views/Home', Main::glue(['/cache/', '/views/', 'Home'], '/'));
        $this->assertSame('a/b', Main::glue(['', 'a', '', 'b'], '/'));
        $this->assertSame('a.b', Main::glue(['.a.', 'b'], '.'));
    }

    /** @dataProvider caseCases */
    public function testCaseConversions(string $input, string $studly, string $camel, string $snake, string $kebab): void {
        $this->assertSame($studly, Main::studlyCase($input));
        $this->assertSame($camel, Main::camelCase($input));
        $this->assertSame($snake, Main::snakeCase($input));
        $this->assertSame($kebab, Main::kebabCase($input));
    }

    public function caseCases(): array {
        return [
            ['user_profile-name', 'UserProfileName', 'userProfileName', 'user_profile_name', 'user-profile-name'],
            ['UserProfileName', 'UserProfileName', 'userProfileName', 'user_profile_name', 'user-profile-name'],
            ['httpServer', 'HttpServer', 'httpServer', 'http_server', 'http-server'],
            ['HTTPServer', 'HttpServer', 'httpServer', 'http_server', 'http-server'],
            ['some value 2', 'SomeValue2', 'someValue2', 'some_value_2', 'some-value-2'],
        ];
    }

    public function testReadable(): void {
        $this->assertSame('User profile name', Main::readable('userProfileName'));
        $this->assertSame('Http server', Main::readable('HTTP_SERVER'));
    }

    public function testMergeIsDeep(): void {
        $base     = ['cache' => ['driver' => 'filesystem', 'ttl' => 60], 'list' => [1, 2, 3]];
        $override = ['cache' => ['driver' => 'redis'], 'list' => [9]];

        $this->assertSame(
            ['cache' => ['driver' => 'redis', 'ttl' => 60], 'list' => [9, 2, 3]],
            Main::merge($base, $override)
        );
        $this->assertSame(['a' => 3, 'b' => 2], Main::merge(['a' => 1], ['b' => 2], ['a' => 3]));
        $this->assertSame([], Main::merge());
    }

    public function testMergeCombinesClosuresLazily(): void {
        $calls = 0;
        $a = function () use (&$calls) { $calls++; return ['x' => 1, 'z' => ['k' => 1]]; };
        $b = function () use (&$calls) { $calls++; return ['x' => 2, 'y' => 3]; };

        $merged = Main::merge(['fn' => $a], ['fn' => $b]);

        $this->assertSame(0, $calls);
        $this->assertInstanceOf(\Closure::class, $merged['fn']);
        $this->assertSame(['x' => 2, 'z' => ['k' => 1], 'y' => 3], $merged['fn']());
        $this->assertSame(2, $calls);
    }

    public function testHashIgnoresOrder(): void {
        $this->assertSame(Main::hash(['b' => 2, 'a' => 1]), Main::hash(['a' => 1, 'b' => 2]));
        $this->assertSame(Main::hash([1, 2, 3]), Main::hash([3, 2, 1]));
        $this->assertSame(32, strlen(Main::hash('x')));
    }

    public function testHashDistinguishesTypesAndValues(): void {
        $hashes = [
            Main::hash('1'), Main::hash(1), Main::hash(1.0), Main::hash(true),
            Main::hash(null), Main::hash([1]), Main::hash(['1']), Main::hash(['a' => 1]),
        ];

        $this->assertSame($hashes, array_unique($hashes));
    }

    public function testHashOfObjectsAndCycles(): void {
        $a = new \stdClass();
        $a->x = 1;
        $b = new \stdClass();
        $b->x = 1;

        $this->assertSame(Main::hash($a), Main::hash($b));

        $a->self = $a;
        $this->assertSame(32, strlen(Main::hash($a)));
    }

    public function testHashRejectsResources(): void {
        $this->expectException(\RuntimeException::class);

        $handle = fopen('php://memory', 'r');
        try {
            Main::hash($handle);
        } finally {
            fclose($handle);
        }
    }

    public function testUuidFormatAndVersion(): void {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-%d[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

        $v7 = Main::uuid();
        $v4 = Main::uuid(4);

        $this->assertMatchesRegularExpression(sprintf($pattern, 7), $v7);
        $this->assertMatchesRegularExpression(sprintf($pattern, 4), $v4);
        $this->assertNotSame(Main::uuid(4), Main::uuid(4));
    }

    public function testUuidV7CarriesUnixMilliseconds(): void {
        $before = (int)(microtime(true) * 1000);
        $uuid   = Main::uuid();
        $after  = (int)(microtime(true) * 1000);

        $ms = hexdec(str_replace('-', '', substr($uuid, 0, 13)));

        $this->assertGreaterThanOrEqual($before, $ms);
        $this->assertLessThanOrEqual($after, $ms);
    }

    public function testUuidV7IsTimeOrdered(): void {
        $first = Main::uuid();
        usleep(2000);
        $second = Main::uuid();

        $this->assertLessThan(0, strcmp(substr($first, 0, 13), substr($second, 0, 13)));
    }

    public function testDotGet(): void {
        $data = ['cache' => ['driver' => 'redis', 'hosts' => ['redis1', 'redis2']], 'n' => null];

        $this->assertSame('redis', Main::dotGet($data, 'cache.driver'));
        $this->assertSame('redis2', Main::dotGet($data, 'cache.hosts.1'));
        $this->assertSame('x', Main::dotGet($data, 'cache.missing', 'x'));
        $this->assertSame('x', Main::dotGet($data, 'cache.driver.deeper', 'x'));
        $this->assertNull(Main::dotGet($data, 'n', 'x'));
    }

    public function testDotSet(): void {
        $data = ['cache' => 'scalar'];

        Main::dotSet($data, 'cache.driver', 'redis');
        Main::dotSet($data, 'a.b.c', 1);

        $this->assertSame(['cache' => ['driver' => 'redis'], 'a' => ['b' => ['c' => 1]]], $data);
    }

    public function testDotFlattenKeepsListsWhole(): void {
        $this->assertSame(
            ['cache.driver' => 'redis', 'cache.hosts' => ['h1', 'h2'], 'debug' => true, 'empty' => []],
            Main::dotFlatten(['cache' => ['driver' => 'redis', 'hosts' => ['h1', 'h2']], 'debug' => true, 'empty' => []])
        );
        $this->assertSame(['p.a' => 1], Main::dotFlatten(['a' => 1], 'p'));
    }

    public function testArrayIsList(): void {
        $this->assertTrue(Main::arrayIsList([]));
        $this->assertTrue(Main::arrayIsList([1, 2, 3]));
        $this->assertFalse(Main::arrayIsList(['a' => 1]));
        $this->assertFalse(Main::arrayIsList([1 => 'a', 0 => 'b']));
        $this->assertFalse(Main::arrayIsList([1 => 'a']));
    }

    public function testFormatBytesAutoUnit(): void {
        $this->assertSame('1.5 MB', Main::formatBytes(1572864));
        $this->assertSame('500 B', Main::formatBytes(500));
        $this->assertSame('2 MB', Main::formatBytes(1572864, '', 0));
        $this->assertSame('0 B', Main::formatBytes(0));
        $this->assertSame('1 GB', Main::formatBytes(1073741824));
    }

    public function testFormatBytesSingleUnitReturnsNumber(): void {
        $this->assertSame(1.5, Main::formatBytes(1572864, 'mb'));
        $this->assertSame(1536.0, Main::formatBytes(1572864, 'kb'));
        $this->assertSame(1536.0, Main::formatBytes(1572864, 'KiB'));
        $this->assertSame(500, Main::formatBytes(500, 'b'));
        $this->assertSame(500, Main::formatBytes(500, 'xyz'));
    }

    public function testFormatBytesComposite(): void {
        $this->assertSame('1 GB 512 MB', Main::formatBytes(1610612736, 'GB MB'));
        $this->assertSame('1 gb, 512 mb', Main::formatBytes(1610612736, 'gb, mb'));
        $this->assertSame('size: 2 kb', Main::formatBytes(2048, 'size: kb'));
        $this->assertSame('1 GB (\B)', Main::formatBytes(1073741824, 'GB (\\\\\B)'));
    }

    public function testPreparePathNormalizesAbsolutePaths(): void {
        $this->assertSame('/a/c', Main::preparePath('/a/./b/../c/'));
        $this->assertSame('/', Main::preparePath('/'));
        $this->assertSame('/a', Main::preparePath('//a//'));
    }

    public function testPreparePathResolvesAgainstBase(): void {
        $this->assertSame('/base/dir/x/y', Main::preparePath('x/y', '/base/dir'));
        $this->assertSame('/base/cache', Main::preparePath('../cache', '/base/dir'));
        $this->assertSame(str_replace('\\', '/', __DIR__).'/file.php', Main::preparePath('file.php'));
    }

    public function testPreparePathResolvesTildeFromDocumentRoot(): void {
        $this->assertSame(ST_TESTS_ROOT.'/storage/logs', Main::preparePath('~/storage/logs'));
        $this->assertSame(ST_TESTS_ROOT, Main::preparePath('~'));
    }

    public function testPreparePathStrictRejectsParentSegments(): void {
        $this->expectException(\InvalidArgumentException::class);

        Main::preparePath('../../etc/passwd', '/var/www', true);
    }

    public function testTimestamp(): void {
        $this->assertIsNumeric(Main::timestamp());
        $this->assertSame(date('Y'), Main::timestamp('Y'));
    }
}
