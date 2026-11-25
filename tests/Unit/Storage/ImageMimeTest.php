<?php

namespace ST_system\Tests\Unit\Storage;

use ST_system\Storage\Mimes\ImageMime;
use ST_system\Tests\TestCase;

final class ImageMimeTest extends TestCase {

    private $binary;

    protected function setUp(): void {
        $this->binary = ImageMime::config('metadata.binary');
        self::setStatic(ImageMime::class, 'METADATA_TOOL', null);
    }

    protected function tearDown(): void {
        ImageMime::setConfig(['metadata.binary' => $this->binary]);
        self::setStatic(ImageMime::class, 'METADATA_TOOL', null);

        parent::tearDown();
    }

    public function testMetadataIsOffByDefault(): void {
        self::assertFalse(ImageMime::config('convert.config.metadata'));
    }

    public function testMetadataToolWithoutBinary(): void {
        ImageMime::setConfig(['metadata.binary' => '']);

        self::assertFalse(ImageMime::metadataToolAvailable());
    }

    public function testMetadataToolWithMissingBinary(): void {
        ImageMime::setConfig(['metadata.binary' => 'st-system-no-such-binary']);

        self::assertFalse(ImageMime::metadataToolAvailable());
    }

    /** exec в disable_functions: на PHP 8 его вызов - Error, проверка должна ответить false. */
    public function testMetadataToolWithDisabledExec(): void {
        if (!function_exists('proc_open'))
            self::markTestSkipped('proc_open недоступен');

        $script = $this->writeFile($this->tmpDir('image-mime').'/check.php', '<?php '
            .'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
            .'echo json_encode(\ST_system\Storage\Mimes\ImageMime::metadataToolAvailable());');

        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=exec', '-d', 'display_errors=stderr', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $err);
        self::assertSame('false', trim($out), $err);
    }
}
