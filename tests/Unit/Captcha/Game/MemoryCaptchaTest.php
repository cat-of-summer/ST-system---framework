<?php

namespace ST_system\Tests\Unit\Captcha\Game;

use ST_system\Captcha\CaptchaManager;
use ST_system\Captcha\Drivers\Game\Memory\MemoryCaptchaDriver;
use ST_system\Tests\Support\StubImageAdapter;
use ST_system\Tests\TestCase;

/**
 * Memory сверх общего набора игр (GameCaptchaTest): уровни, лица карточек из эмодзи и из картинок.
 * Картинки кадрирует заглушка — в php:*-cli нет GD.
 */
final class MemoryCaptchaTest extends TestCase {

    private string $images;

    protected function setUp(): void {
        parent::setUp();

        $this->images = $this->tmpDir('images');
        for ($i = 0; $i < 12; $i++) $this->writeFile($this->images."/p{$i}.jpg", 'stub');
    }

    private function captcha(array $config = []): CaptchaManager {
        return CaptchaManager::make('memory-form', $config + [
            'driver'    => 'memory',
            'min_score' => 0,
            'cache'     => ['driver' => 'filesystem', 'dir' => ST_TESTS_ROOT.'/captcha'],
        ]);
    }

    /** Доступен ли драйвер: недоступный менеджер не отдаёт, а бросает RuntimeException. */
    private function available(array $config): bool {
        try {
            return $this->captcha($config)->driver->isAvailable();
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    private static function publicConfig(string $html): array {
        preg_match('/push\(\["stc_[0-9a-f]{32}",(\{.*?\})\]\)/', $html, $m);

        return json_decode($m[1], true);
    }

    public function levelProvider(): array {
        $out = [];
        foreach (MemoryCaptchaDriver::LEVELS as $level => $row) $out[$level] = [$level, $row];

        return $out;
    }

    /** @dataProvider levelProvider */
    public function testLevelSetsPairsPreviewAndLives(string $level, array $row): void {
        [$pairs, $seconds, $lives] = $row;
        $public = self::publicConfig($this->captcha(['level' => $level])->putCaptcha());

        $this->assertSame($pairs, $public['params']['pairs']);
        $this->assertSame($lives, $public['params']['lives']);
        $this->assertSame($seconds * 10, $public['params']['show'], 'показ в тиках');
        $this->assertSame('emoji', $public['faces']);
        $this->assertCount($pairs, $public['cards']);
        $this->assertCount($pairs, array_unique($public['cards']), 'лица разные');
    }

    public function testLevelsGrowHarder(): void {
        [$easy, $normal, $hard] = array_values(MemoryCaptchaDriver::LEVELS);

        $this->assertTrue($easy[0] < $normal[0] && $normal[0] < $hard[0], 'пар больше');
        $this->assertTrue($easy[1] > $normal[1] && $normal[1] > $hard[1], 'показ короче');
        $this->assertTrue($easy[2] > $normal[2] && $normal[2] > $hard[2], 'жизней меньше');
    }

    public function testUnknownLevelThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->captcha(['level' => 'insane'])->putCaptcha();
    }

    public function testUnknownFacesThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->captcha(['faces' => 'sounds'])->putCaptcha();
    }

    public function testOwnEmojiList(): void {
        $emoji  = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $public = self::publicConfig($this->captcha(['emoji' => $emoji])->putCaptcha());

        $cards = $public['cards'];
        sort($cards);
        $this->assertSame($emoji, $cards, 'восемь пар — все восемь из списка');

        $this->assertFalse($this->available(['emoji' => ['A', 'B', 'A']]), 'лиц меньше, чем пар');
    }

    public function testImageFacesAreCroppedPerCard(): void {
        $captcha = $this->captcha(['faces' => 'images', 'images' => $this->images, 'image' => StubImageAdapter::class, 'level' => 'easy']);
        $this->assertTrue($captcha->driver->isAvailable());

        $public = self::publicConfig($captcha->putCaptcha());

        $this->assertSame('images', $public['faces']);
        $this->assertCount(6, $public['cards']);
        foreach ($public['cards'] as $card) $this->assertStringStartsWith('data:image/jpeg;base64,', $card);

        $this->assertSame(MemoryCaptchaDriver::CARD, [StubImageAdapter::$puzzle['width'], StubImageAdapter::$puzzle['height']]);
        $this->assertSame([1, 1, 0, [0]], [
            StubImageAdapter::$puzzle['rows'], StubImageAdapter::$puzzle['cols'], StubImageAdapter::$puzzle['bleed'], StubImageAdapter::$puzzle['order'],
        ], 'целая картинка, без нарезки и полей');
    }

    public function testImageFacesNeedEnoughImages(): void {
        $config = ['faces' => 'images', 'images' => $this->images, 'image' => StubImageAdapter::class];

        $this->assertTrue($this->available($config + ['level' => 'hard']), '10 пар из 12 картинок');
        $this->assertFalse($this->available($config + ['level' => 'easy', 'max_images' => 5]), '6 пар из 5 картинок');
    }
}
