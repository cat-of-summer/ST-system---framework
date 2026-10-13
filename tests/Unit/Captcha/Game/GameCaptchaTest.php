<?php

namespace ST_system\Tests\Unit\Captcha\Game;

use ST_system\Captcha\CaptchaManager;
use ST_system\Captcha\Drivers\Game\GameCaptchaDriver;
use ST_system\Tests\Support\StubImageAdapter;
use ST_system\Tests\TestCase;

/**
 * Игровые капчи. Фикстуры собирает `npm test` в src/Captcha/Drivers/Game: бот играет
 * JS-симуляцией, лог и digest итогового состояния пишутся в tests/Fixtures/Captcha/Game.
 * Здесь тот же лог гоняет PHP-копия игры — расхождение означает, что порт отличается от sim.js.
 */
final class GameCaptchaTest extends TestCase {

    private const GAMES = ['snake', 'flappy', 'arkanoid', 'tetris', 'pacman', 'battlecity', 'puzzle'];

    private string $images = '';

    protected function setUp(): void {
        parent::setUp();

        // Пазлу нужны картинки и адаптер; в php:*-cli нет GD — режет заглушка.
        $this->images = $this->tmpDir('images');
        $this->writeFile($this->images.'/a.jpg', 'stub');
    }

    private static function fixture(string $name): array {
        $file = __DIR__.'/../../../Fixtures/Captcha/Game/'.$name.'.json';

        return json_decode((string)file_get_contents($file), true);
    }

    public function gameProvider(): array {
        $out = [];
        foreach (self::GAMES as $name) $out[$name] = [$name];

        return $out;
    }

    /** Игры с запасным путём: у пазла его нет. */
    public function fallbackGameProvider(): array {
        return array_filter($this->gameProvider(), static fn(array $row): bool => isset(self::fixture($row[0])['fallback_pass']));
    }

    public function caseProvider(): array {
        $out = [];

        foreach (self::GAMES as $name)
            foreach (self::fixture($name)['cases'] as $case)
                $out[$name.' seed '.$case['seed']] = [$name, $case];

        return $out;
    }

    private function captcha(string $name, array $config = []): CaptchaManager {
        if ($name === 'puzzle') $config += ['image' => StubImageAdapter::class, 'images' => $this->images];

        return CaptchaManager::make('game-form', $config + [
            'driver'      => $name,
            'min_score'   => 0,
            'clock_slack' => 100000,
            'cache'       => ['driver' => 'filesystem', 'dir' => ST_TESTS_ROOT.'/captcha'],
        ]);
    }

    private static function simulation(string $name): string {
        $class  = (string)CaptchaManager::config('drivers.available.'.$name);
        $method = new \ReflectionMethod($class, 'simulation');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    /** Выдать капчу с seed (и у пазла — перестановкой) из фикстуры; вернуть id. */
    private function issue(string $name, array $config = []): string {
        $pass   = self::fixture($name)['pass'];
        $params = ['seed' => $pass['seed']];

        if (isset($pass['params']['order'])) $params['order'] = $pass['params']['order'];

        $html = $this->captcha($name, $config)->putCaptcha($params);
        preg_match('/name="st-captcha-id" value="([0-9a-f]{32})"/', $html, $id);

        return $id[1];
    }

    private static function payload(string $id, $answer): array {
        return [
            'st-captcha-id'       => $id,
            'st-captcha-answer'   => is_string($answer) ? $answer : json_encode($answer),
            'st-captcha-behavior' => '',
        ];
    }

    /** @dataProvider caseProvider */
    public function testPhpSimulationMatchesJs(string $name, array $case): void {
        $class = self::simulation($name);
        $sim   = $class::replay($case['seed'], $case['params'], $case['log'], $case['ticks'], 0);

        $this->assertSame($case['ticks'], $sim->tick);
        $this->assertSame($case['peak'], $sim->peak, 'peak');
        $this->assertSame([$case['acts'], $case['good']], [$sim->acts, $sim->good], 'осмысленные действия');
        $this->assertSame($case['digest'], $sim->digest(), 'digest итогового состояния');
    }

    private static function publicConfig(string $html): array {
        preg_match('/push\(\["stc_[0-9a-f]{32}",(\{.*?\})\]\)/', $html, $m);

        return json_decode($m[1], true);
    }

    /** @dataProvider gameProvider */
    public function testDefaultsMatchJs(string $name): void {
        $fixture = self::fixture($name);
        $captcha = $this->captcha($name);
        $public  = self::publicConfig($captcha->putCaptcha());

        $params   = $public['params'];
        $expected = $fixture['public_params'];
        ksort($params);
        ksort($expected);

        $this->assertSame($fixture['tps'], $public['tps']);
        $this->assertSame($fixture['tps'] * $fixture['max_time'], $public['max_ticks'], 'время партии');
        $this->assertSame($fixture['collect'], $public['collect'], 'порог сбора данных, как в assets/test/games.js');
        $this->assertSame($expected, $params, 'параметры игры в браузере');

        // Цель и пороги качества остаются на сервере — те же, что в games.js.
        $this->assertSame($fixture['goal'], $captcha->goal);
        $this->assertSame($fixture['goal_ratio'], $captcha->goal_ratio);
        $this->assertSame($fixture['fallback'], $captcha->fallback ? array_diff_key($captcha->fallback, ['score' => 0]) : null);

        foreach (['goal', 'goal_ratio', 'fallback'] as $key)
            $this->assertArrayNotHasKey($key, $public, $key.' в браузер не уходит');
        $this->assertArrayNotHasKey('order', $public['params'], 'секретные параметры в браузер не уходят');
    }

    /** @dataProvider gameProvider */
    public function testHintGoesToBrowserAndCanBeOverridden(string $name): void {
        $public = self::publicConfig($this->captcha($name)->putCaptcha());
        $this->assertNotSame('', $public['hint']['keys'], 'подсказка с клавиатуры');
        $this->assertNotSame('', $public['hint']['touch'], 'подсказка на телефоне');

        $public = self::publicConfig($this->captcha($name, ['hint' => ['keys' => 'Arrows to move']])->putCaptcha());
        $this->assertSame('Arrows to move', $public['hint']['keys']);
        $this->assertNotSame('', $public['hint']['touch'], 'частичный hint дополняется подсказкой игры');
    }

    public function testBigButtonSide(): void {
        $this->assertSame('left', self::publicConfig($this->captcha('battlecity')->putCaptcha())['big_button'], 'по умолчанию слева');
        $this->assertSame('right', self::publicConfig($this->captcha('battlecity', ['big_button' => 'right'])->putCaptcha())['big_button']);
        $this->assertSame('left', self::publicConfig($this->captcha('battlecity', ['big_button' => 'top'])->putCaptcha())['big_button'], 'неизвестная сторона — слева');
    }

    /** Лог дольше max_time не принимается: партия не может идти дольше, чем её показывает браузер. */
    public function testLogLongerThanMaxTimeFails(): void {
        $id = $this->issue('snake');

        $captcha = $this->captcha('snake');
        $this->assertFalse($captcha->check(self::payload($id, ['t' => 24 * 20 + 1, 'log' => []])));
        $this->assertSame('answer', $captcha->error);
    }

    public function testSizeShapesField(): void {
        $layout = function (string $name, array $size): array {
            $public = self::publicConfig($this->captcha($name, ['size' => $size])->putCaptcha());
            unset($public['params']['per_life']);

            return [$public['size'], $public['params']];
        };

        $this->assertSame([[480, 340], ['cols' => 24, 'rows' => 16]], $layout('snake', [480, 340]));
        $this->assertSame([[240, 320], ['w' => 240]], $layout('flappy', [240, 320]), 'вертикальный холст — мир уже');
        $this->assertSame([[400, 260], ['cols' => 25, 'rows' => 15]], $layout('pacman', [400, 260]), 'стороны лабиринта нечётные');
        $this->assertSame([[400, 260], ['cols' => 25, 'rows' => 15]], $layout('battlecity', [400, 260]));
        $this->assertSame([[200, 960], []], $layout('tetris', [100, 2000]), 'размер зажат в 200..960 × 140..960');
    }

    /** @dataProvider gameProvider */
    public function testWinningLogPasses(string $name): void {
        $pass = self::fixture($name)['pass'];
        $id   = $this->issue($name, ['goal' => $pass['goal']]);

        $captcha = $this->captcha($name);
        $this->assertTrue($captcha->check(self::payload($id, ['t' => $pass['ticks'], 'log' => $pass['log']])), (string)$captcha->error);
        $this->assertSame('goal', $captcha->via);

        $replay = $this->captcha($name);
        $this->assertFalse($replay->check(self::payload($id, ['t' => $pass['ticks'], 'log' => $pass['log']])));
        $this->assertSame('replayed', $replay->error);
    }

    /** @dataProvider gameProvider */
    public function testUnfinishedGameFails(string $name): void {
        $pass = self::fixture($name)['pass'];
        $id   = $this->issue($name, ['goal' => $pass['goal']]);

        $captcha = $this->captcha($name);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => 0, 'log' => $pass['log']])));
        $this->assertSame('answer', $captcha->error);

        $captcha = $this->captcha($name);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => $pass['ticks'], 'log' => []])));
        $this->assertSame('answer', $captcha->error);
    }

    /** @dataProvider gameProvider */
    public function testLogFasterThanRealTimeFails(string $name): void {
        $pass = self::fixture($name)['pass'];
        $id   = $this->issue($name, ['goal' => $pass['goal'], 'clock_slack' => 0]);

        $captcha = $this->captcha($name, ['clock_slack' => 0]);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => $pass['ticks'], 'log' => $pass['log']])));
        $this->assertSame('answer', $captcha->error);
    }

    /**
     * Слабый игрок: цель недостижима, но играл достаточно и осмысленно. Порог скоринга обнулён —
     * поведенческий слой здесь не проверяется.
     *
     * @dataProvider fallbackGameProvider
     */
    public function testFallbackPassesWithoutGoal(string $name): void {
        $fb     = self::fixture($name)['fallback_pass'];
        $config = ['goal' => 50, 'fallback' => ['score' => 0]];
        $id     = $this->issue($name, $config);

        $captcha = $this->captcha($name, $config);
        $this->assertTrue($captcha->check(self::payload($id, ['t' => $fb['ticks'], 'log' => $fb['log']])), (string)$captcha->error);
        $this->assertSame('fallback', $captcha->via);
    }

    /** @dataProvider fallbackGameProvider */
    public function testFallbackNeedsHigherBehaviorScore(string $name): void {
        $fb = self::fixture($name)['fallback_pass'];
        $id = $this->issue($name, ['goal' => 50]);

        // Поведения нет (score 0): по цели при min_score 0 это прошло бы, по запасному пути — нет.
        $captcha = $this->captcha($name, ['goal' => 50]);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => $fb['ticks'], 'log' => $fb['log']])));
        $this->assertSame('behavior', $captcha->error);
        $this->assertSame('fallback', $captcha->via);
    }

    /** @dataProvider fallbackGameProvider */
    public function testFallbackCanBeDisabled(string $name): void {
        $fb     = self::fixture($name)['fallback_pass'];
        $config = ['goal' => 50, 'fallback' => false];
        $id     = $this->issue($name, $config);

        $captcha = $this->captcha($name, $config);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => $fb['ticks'], 'log' => $fb['log']])));
        $this->assertSame('answer', $captcha->error);
    }

    /** @dataProvider fallbackGameProvider */
    public function testFallbackTooShortFails(string $name): void {
        $fb     = self::fixture($name)['fallback_pass'];
        $tps    = self::fixture($name)['tps'];
        $config = ['goal' => 50, 'fallback' => ['score' => 0, 'time' => (int)ceil($fb['ticks'] / $tps) + 5]];
        $id     = $this->issue($name, $config);

        $captcha = $this->captcha($name, $config);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => $fb['ticks'], 'log' => $fb['log']])));
        $this->assertSame('answer', $captcha->error);
    }

    /**
     * Случайные нажатия, как их отдал бы браузер (до сбора данных или конца партии): ни цели
     * (вслепую), ни запасного зачёта.
     *
     * @dataProvider gameProvider
     */
    public function testRandomInputFails(string $name): void {
        $rnd    = self::fixture($name)['random'];
        $config = self::fixture($name)['fallback'] ? ['fallback' => ['score' => 0]] : [];
        $id     = $this->issue($name, $config);

        $captcha = $this->captcha($name, $config);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => $rnd['ticks'], 'log' => $rnd['log']])));
        $this->assertSame('answer', $captcha->error);
    }

    public function garbageProvider(): array {
        return [
            'not json'        => ['nope'],
            'no log'          => [['t' => 10]],
            'string ticks'    => [['t' => '10', 'log' => []]],
            'float tick'      => [['t' => 10, 'log' => [[1.5, 0]]]],
            'short event'     => [['t' => 10, 'log' => [[1]]]],
            'long event'      => [['t' => 10, 'log' => [[1, 0, 0, 0]]]],
            'ticks backwards' => [['t' => 10, 'log' => [[5, 0], [4, 0]]]],
            'tick beyond t'   => [['t' => 10, 'log' => [[11, 0]]]],
            'code too big'    => [['t' => 10, 'log' => [[1, 99]]]],
            'value too big'   => [['t' => 10, 'log' => [[1, 0, 10000000]]]],
            'flood one tick'  => [['t' => 10, 'log' => array_fill(0, 9, [3, 0])]],
            'negative t'      => [['t' => -1, 'log' => []]],
            'beyond max time' => [['t' => 24 * 21, 'log' => []]],
        ];
    }

    /** @dataProvider garbageProvider */
    public function testMalformedAnswerIsRejected($answer): void {
        $id = $this->issue('snake');

        $captcha = $this->captcha('snake');
        $this->assertFalse($captcha->check(self::payload($id, $answer)));
        $this->assertSame('answer', $captcha->error);
    }

    public function testTooManyEventsRejected(): void {
        $id  = $this->issue('snake', ['max_events' => 50]);
        $log = [];
        for ($i = 0; $i < 51; $i++) $log[] = [$i, 0];

        $captcha = $this->captcha('snake', ['max_events' => 50]);
        $this->assertFalse($captcha->check(self::payload($id, ['t' => 60, 'log' => $log])));
        $this->assertSame('answer', $captcha->error);
    }

    /** @dataProvider gameProvider */
    public function testWidgetAndBundle(string $name): void {
        $captcha = $this->captcha($name, ['size' => [280, 180]]);
        $driver  = $captcha->driver;

        $this->assertInstanceOf(GameCaptchaDriver::class, $driver);
        $this->assertTrue($driver->isAvailable(), 'dist/game.min.js собран');

        $html = $captcha->putCaptcha();
        $this->assertStringContainsString('data-st-captcha="'.$name.'"', $html);
        $this->assertMatchesRegularExpression('/class="st-captcha-game" style="width:280px;max-width:100%;aspect-ratio:280\/180"/', $html);
        $this->assertStringContainsString('"size":[280,180]', $html);
        $this->assertStringContainsString('"seed":', $html);

        $js = $captcha->includeJs();
        $this->assertStringContainsString('window.STCaptcha', $js);
        $this->assertStringContainsString('"'.$name.'"', $js);
        $this->assertStringNotContainsString('</script', substr($js, strrpos($js, '<script') + 8, -9));
    }
}
