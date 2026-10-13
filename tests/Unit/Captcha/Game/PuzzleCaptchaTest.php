<?php

namespace ST_system\Tests\Unit\Captcha\Game;

use ST_system\Captcha\CaptchaManager;
use ST_system\Captcha\Drivers\Game\Puzzle\PuzzleCaptchaDriver;
use ST_system\Storage\File;
use ST_system\Tests\Support\StubImageAdapter;
use ST_system\Tests\TestCase;

/**
 * Пазл сверх общего набора игр (GameCaptchaTest): картинки, сетка, выдача и то, что перестановка
 * не уходит в браузер. Режет заглушка — в php:*-cli нет GD.
 */
final class PuzzleCaptchaTest extends TestCase {

    private string $images;

    protected function setUp(): void {
        parent::setUp();

        $this->images = $this->tmpDir('images');
        $this->writeFile($this->images.'/a.jpg', 'stub');
        $this->writeFile($this->images.'/nested/b.png', 'stub');
        $this->writeFile($this->images.'/notes.txt', 'not an image');
    }

    private function captcha(array $config = []): CaptchaManager {
        return CaptchaManager::make('puzzle-form', $config + [
            'driver'    => 'puzzle',
            'min_score' => 0,
            'image'     => StubImageAdapter::class,
            'images'    => $this->images,
            'cache'     => ['driver' => 'filesystem', 'dir' => ST_TESTS_ROOT.'/captcha'],
        ]);
    }

    private static function publicConfig(string $html): array {
        preg_match('/push\(\["stc_[0-9a-f]{32}",(\{.*?\})\]\)/', $html, $m);

        return json_decode($m[1], true);
    }

    public function gridProvider(): array {
        return [[4, [2, 2]], [6, [2, 3]], [9, [3, 3]], [12, [3, 4]], [16, [4, 4]], [2, [2, 2]], [100, [6, 6]]];
    }

    /** @dataProvider gridProvider */
    public function testGrid(int $pieces, array $grid): void {
        $this->assertSame($grid, PuzzleCaptchaDriver::grid($pieces));
    }

    public function testImagesFromDirectoryFileAndUrl(): void {
        $this->assertCount(2, $this->captcha()->images, 'jpg и png из каталога и подкаталога, txt — нет');

        $captcha = $this->captcha(['images' => [File::make($this->images.'/a.jpg'), 'https://example.com/pic.jpg']]);
        $this->assertCount(2, $captcha->images);
        $this->assertTrue($captcha->images[1]->is_uri, 'URL не скачивается, пока картинка не нужна');
    }

    public function testWithoutImagesIsUnavailable(): void {
        $this->expectException(\RuntimeException::class);
        $this->captcha(['images' => $this->tmpDir('empty')]);
    }

    public function testIssueKeepsOrderOnServer(): void {
        $html   = $this->captcha(['pieces' => 12])->putCaptcha();
        $order  = StubImageAdapter::$puzzle['order'];
        $public = self::publicConfig($html);

        $sorted = $order;
        sort($sorted);
        $this->assertSame(range(0, 11), $sorted, 'перестановка');

        $this->assertSame(['rows' => 3, 'cols' => 4, 'per_life' => true], $public['params'], 'клиенту — только сетка');
        $this->assertArrayNotHasKey('order', $public);
        $this->assertStringNotContainsString('"order"', $html);
        $this->assertArrayNotHasKey('goal', $public, 'цель (все кусочки) — только на сервере');
        $this->assertNull($public['collect'], 'запасного пути нет — галочка, когда заняты все ячейки');
        $this->assertSame(600, $public['max_ticks'], 'на сборку 60 секунд');

        $this->assertStringStartsWith('data:image/jpeg;base64,', $public['sprite']);
        $this->assertSame([124, 126], $public['board'], 'холст 320 × 200: поле кратно сетке, 4 × 31 на 3 × 42');
        $this->assertSame('right', $public['tray'], 'на горизонтальном холсте лоток справа');
        $this->assertSame(2, $public['density'], 'спрайт вдвое плотнее холста');
        $this->assertSame(23, $public['bleed'], 'поле «на вылет» под язычки в пикселях спрайта: ⌈62 × 0.36⌉');
        $this->assertArrayNotHasKey('tabs', $public, 'форма уходит в браузер пикселями, не данными');
        $this->assertCount(12, StubImageAdapter::$puzzle['outlines'], 'контур на каждый кусочек');

        $this->assertSame([248, 252, 3, 4], [
            StubImageAdapter::$puzzle['width'], StubImageAdapter::$puzzle['height'],
            StubImageAdapter::$puzzle['rows'], StubImageAdapter::$puzzle['cols'],
        ]);
    }

    public function testTableFollowsCanvas(): void {
        $this->assertSame([40, 40, 29, 'right'], PuzzleCaptchaDriver::table(320, 200, 3, 3), 'по умолчанию: поле слева, лоток справа');
        $this->assertSame([67, 67, 49, 'below'], PuzzleCaptchaDriver::table(300, 540, 3, 3), 'вертикальный холст: лоток снизу');
        $this->assertSame([62, 62, 45, 'right'], PuzzleCaptchaDriver::table(480, 300, 3, 3), 'холст крупнее — кусочки крупнее');

        $public = self::publicConfig($this->captcha(['size' => [300, 540]])->putCaptcha());
        $this->assertSame([201, 201], $public['board']);
        $this->assertSame('below', $public['tray']);
        $this->assertSame([300, 540], $public['size']);
    }

    public function testFixedOrderAndValidation(): void {
        $this->captcha()->putCaptcha(['order' => [8, 7, 6, 5, 4, 3, 2, 1, 0]]);
        $this->assertSame([8, 7, 6, 5, 4, 3, 2, 1, 0], StubImageAdapter::$puzzle['order']);

        $this->expectException(\InvalidArgumentException::class);
        $this->captcha()->putCaptcha(['order' => [0, 0, 1, 2, 3, 4, 5, 6, 7]]);
    }

    public function testPartialFallbackGetsBaseThresholds(): void {
        $captcha = $this->captcha(['fallback' => ['time' => 40]]);
        $public  = self::publicConfig($captcha->putCaptcha());

        $this->assertSame(['time' => 40, 'actions' => 10, 'ratio' => 60, 'score' => 0.7], $captcha->fallback);
        $this->assertSame(['time' => 40, 'actions' => 10], $public['collect']);
    }
}
