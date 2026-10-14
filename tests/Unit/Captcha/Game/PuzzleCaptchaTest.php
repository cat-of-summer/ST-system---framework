<?php

namespace ST_system\Tests\Unit\Captcha\Game;

use ST_system\Captcha\CaptchaManager;
use ST_system\Captcha\Drivers\Game\Puzzle\PuzzleCaptchaDriver;
use ST_system\Captcha\Drivers\Game\Puzzle\PuzzleSimulation;
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
        return [[4, [2, 2]], [6, [2, 3]], [9, [3, 3]], [12, [3, 4]], [16, [4, 4]], [2, [2, 2]], [100, [10, 10]], [150, [10, 10]]];
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

    public function testPuzzleMustBeSolvedAndHasNoCollectThreshold(): void {
        $this->assertTrue(PuzzleCaptchaDriver::required(), 'пазл обязательно собрать');
        $this->assertNull(self::publicConfig($this->captcha()->putCaptcha())['collect']);

        $captcha = $this->captcha(['collect' => ['time' => 40]]);
        $public  = self::publicConfig($captcha->putCaptcha());
        $this->assertSame(['time' => 40, 'actions' => 10], $captcha->collect, 'частичный порог дополняется базовым');
        $this->assertSame(['time' => 40, 'actions' => 10], $public['collect']);
    }

    public function testScaleShrinksLogicalCanvas(): void {
        $public = self::publicConfig($this->captcha(['size' => [640, 400], 'scale' => 2])->putCaptcha());

        $this->assertSame([320, 200], $public['size'], 'логический холст — size / scale');
        $this->assertSame([640, 400], $public['view'], 'на экране — size');
        $this->assertSame([120, 120], $public['board'], 'поле то же, что у 320 × 200');
    }

    public function testEasyLevelLeavesQuarterOfPieces(): void {
        $this->assertSame('hard', $this->captcha()->level, 'по умолчанию — вставить все');
        $this->assertSame(3, PuzzleCaptchaDriver::loose(9, 'easy'));
        $this->assertSame(25, PuzzleCaptchaDriver::loose(100, 'easy'));
        $this->assertSame(2, PuzzleCaptchaDriver::loose(4, 'easy'), 'не меньше двух');
        $this->assertSame(9, PuzzleCaptchaDriver::loose(9, 'hard'));

        $public = self::publicConfig($this->captcha(['level' => 'easy'])->putCaptcha());
        $order  = StubImageAdapter::$puzzle['order'];
        $fixed  = $public['params']['fixed'];

        $this->assertCount(6, $fixed, 'из 9 кусочков закреплены 6');
        foreach ($fixed as [$k, $s]) $this->assertSame($s, $order[$k], 'закреплённый стоит на своём месте');
        $this->assertCount(6, array_unique(array_column($fixed, 1)), 'ячейки разные');
        $this->assertArrayNotHasKey('order', $public['params']);

        $hard = self::publicConfig($this->captcha()->putCaptcha());
        $this->assertArrayNotHasKey('fixed', $hard['params']);
        $this->assertGreaterThan($hard['board'][0], $public['board'][0], 'в лотке меньше кусочков — поле крупнее');

        $this->expectException(\InvalidArgumentException::class);
        $this->captcha(['level' => 'medium']);
    }

    public function testFixedPiecesLockedInPhpSimulation(): void {
        $order = [2, 0, 1, 4, 3, 5, 8, 6, 7];
        $fixed = [];
        foreach ([0, 1, 2, 3, 4, 5] as $s) $fixed[] = [array_search($s, $order, true), $s];

        $log = [[0, 1, $fixed[0][0]], [0, 0, $fixed[0][0] * 128 + 7], [0, 0, 6 * 128 + 0]];
        foreach ([6, 7, 8] as $s) $log[] = [1, 0, array_search($s, $order, true) * 128 + $s];

        $sim = PuzzleSimulation::replay(1, ['rows' => 3, 'cols' => 3, 'order' => $order, 'fixed' => $fixed], $log, 2);
        $this->assertSame(9, $sim->peak, 'собран: шесть закреплённых и три вставленных');
        $this->assertSame([3, 3], [$sim->acts, $sim->good], 'запрещённые ходы — не действия');
    }

    public function testHundredPieces(): void {
        $this->assertSame([10, 10], PuzzleCaptchaDriver::grid(100));

        $public = self::publicConfig($this->captcha(['pieces' => 100, 'size' => [960, 600]])->putCaptcha());
        $this->assertSame(['rows' => 10, 'cols' => 10, 'per_life' => true], $public['params']);
        $this->assertCount(100, StubImageAdapter::$puzzle['outlines']);
    }
}
