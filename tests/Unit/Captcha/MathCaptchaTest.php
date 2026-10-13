<?php

namespace ST_system\Tests\Unit\Captcha;

use ST_system\Captcha\CaptchaManager;
use ST_system\Captcha\Drivers\MathCaptchaDriver;
use ST_system\Tests\Support\StubImageAdapter;
use ST_system\Tests\TestCase;

/** Капча-пример и текстовая капча на заглушке адаптера: задание берётся из того, что «нарисовано». */
final class MathCaptchaTest extends TestCase {

    private string $fonts;

    protected function setUp(): void {
        parent::setUp();

        // Шрифт определяется по расширению, содержимое заглушке не нужно.
        $this->fonts = $this->tmpDir('fonts');
        $this->writeFile($this->fonts.'/stub.ttf', 'stub');
    }

    private function captcha(string $driver = 'math', array $config = []): CaptchaManager {
        return CaptchaManager::make('math-form', $config + [
            'driver'    => $driver,
            'min_score' => 0,
            'image'     => StubImageAdapter::class,
            'fonts'     => $this->fonts,
            'cache'     => ['driver' => 'filesystem', 'dir' => ST_TESTS_ROOT.'/captcha'],
        ]);
    }

    private static function id(string $html): string {
        preg_match('/name="st-captcha-id" value="([0-9a-f]{32})"/', $html, $m);

        return $m[1];
    }

    private static function payload(string $id, string $answer): array {
        return ['st-captcha-id' => $id, 'st-captcha-answer' => $answer, 'st-captcha-behavior' => ''];
    }

    /** Решение задания по тексту с картинки — независимо от драйвера. */
    private static function solve(string $text): int {
        if (preg_match('/^(\d+)([+\-×x])(\d+)=\?$/u', $text, $m)) {
            [, $a, $op, $b] = $m;
            return $op === '+' ? $a + $b : ($op === '-' ? $a - $b : $a * $b);
        }

        if (preg_match('/^(\d*)x([+\-]\d+)?=(\d+)$/', $text, $m)) {
            $k = $m[1] === '' ? 1 : (int)$m[1];
            $b = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            return intdiv((int)$m[3] - $b, $k);
        }

        throw new \UnexpectedValueException("Не разобрать задание: {$text}");
    }

    public function levelProvider(): array {
        return ['arithmetic' => ['arithmetic'], 'equation' => ['equation'], 'mixed' => ['mixed']];
    }

    /** @dataProvider levelProvider */
    public function testChallengeAnswerMatchesExpression(string $level): void {
        $driver    = $this->captcha('math', ['level' => $level])->driver;
        $challenge = new \ReflectionMethod(MathCaptchaDriver::class, 'challenge');
        $challenge->setAccessible(true);

        $kinds = [];

        for ($i = 0; $i < 300; $i++) {
            [$text, $answer] = $challenge->invoke($driver);

            $this->assertStringNotContainsString(' ', $text);
            $this->assertSame((string)self::solve($text), $answer, $text);
            $this->assertGreaterThanOrEqual(0, (int)$answer, 'ответ не отрицательный: '.$text);

            if (strpos($text, '?') !== false) {
                $kinds['arithmetic'] = true;
            } else {
                $kinds['equation'] = true;
                $this->assertMatchesRegularExpression('/^\d*x[+\-]?\d*=\d+$/', $text);
                $this->assertLessThanOrEqual(12, (int)$answer);
                $this->assertGreaterThanOrEqual(1, (int)$answer);
            }
        }

        $expected = $level === 'mixed' ? ['arithmetic', 'equation'] : [$level];
        $this->assertSame($expected, array_values(array_intersect(['arithmetic', 'equation'], array_keys($kinds))));
    }

    public function testSolvedChallengePasses(): void {
        $html = $this->captcha('math', ['level' => 'equation'])->putCaptcha();
        $text = StubImageAdapter::$text;

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('placeholder="x ="', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringNotContainsString(htmlspecialchars($text), $html, 'текст задания не уходит в HTML');
        $this->assertStringContainsString('"length":1', $html, 'длина ответа не раскрывается');

        $captcha = $this->captcha();
        $this->assertTrue($captcha->check(self::payload(self::id($html), ' '.self::solve($text).' ')), (string)$captcha->error);
    }

    public function testWrongAnswerFails(): void {
        $html   = $this->captcha()->putCaptcha();
        $answer = self::solve(StubImageAdapter::$text);

        $captcha = $this->captcha();
        $this->assertFalse($captcha->check(self::payload(self::id($html), (string)($answer + 1))));
        $this->assertSame('answer', $captcha->error);

        $captcha = $this->captcha();
        $this->assertFalse($captcha->check(self::payload(self::id($html), 'abc')));
        $this->assertSame('answer', $captcha->error);
    }

    public function answerFormatProvider(): array {
        return [
            'spaces'       => ['1 2', '12'],
            'leading plus' => ['+7', '7'],
            'zeros'        => ['007', '7'],
            'minus'        => ['-5', '-5'],
            'unicode'      => ['−5', '-5'],
            'minus zero'   => ['-0', '0'],
            'letters'      => ['7a', ''],
            'empty'        => ['  ', ''],
            'too long'     => ['1234567', ''],
        ];
    }

    /** @dataProvider answerFormatProvider */
    public function testAnswerNormalization(string $given, string $expected): void {
        $normalize = new \ReflectionMethod(MathCaptchaDriver::class, 'normalize');
        $normalize->setAccessible(true);

        $this->assertSame($expected, $normalize->invoke($this->captcha()->driver, $given));
    }

    public function testUnknownLevelThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->captcha('math', ['level' => 'calculus']);
    }

    public function testTimesSignIsConfigurable(): void {
        $driver    = $this->captcha('math', ['times' => 'x'])->driver;
        $challenge = new \ReflectionMethod(MathCaptchaDriver::class, 'challenge');
        $challenge->setAccessible(true);

        for ($i = 0; $i < 100; $i++) {
            [$text] = $challenge->invoke($driver);
            $this->assertStringNotContainsString('×', $text);
        }
    }

    public function testTextCaptchaStillWorks(): void {
        $html = $this->captcha('text')->putCaptcha();
        $text = StubImageAdapter::$text;

        $this->assertSame(4, strlen($text));
        $this->assertStringContainsString('maxlength="4"', $html);
        $this->assertStringContainsString("register(\"text\"", $this->captcha('text')->includeJs());

        $captcha = $this->captcha('text');
        $this->assertTrue($captcha->check(self::payload(self::id($html), strtolower($text))), (string)$captcha->error);
    }

    public function testTextCaseInsensitiveShowsCapitals(): void {
        $html = $this->captcha('text', ['char_pool' => 'abcdefgh'])->putCaptcha();
        $text = StubImageAdapter::$text;

        $this->assertMatchesRegularExpression('/^[A-H]{4}$/', $text, 'регистр не важен — на картинке заглавные');

        $captcha = $this->captcha('text', ['char_pool' => 'abcdefgh']);
        $this->assertTrue($captcha->check(self::payload(self::id($html), strtolower($text))), 'вводить можно любыми');
    }

    public function testTextCaseSensitiveKeepsCase(): void {
        $html = $this->captcha('text', ['char_pool' => 'abcdefgh', 'case_sensitive' => true])->putCaptcha();
        $text = StubImageAdapter::$text;

        $this->assertMatchesRegularExpression('/^[a-h]{4}$/', $text);

        $captcha = $this->captcha('text', ['char_pool' => 'abcdefgh', 'case_sensitive' => true]);
        $this->assertFalse($captcha->check(self::payload(self::id($html), strtoupper($text))), 'регистр важен');
    }

    /** Обязательность — свойство типа капчи: задачу с ответом надо решить, игру на реакцию — нет. */
    public function testRequiredIsPerDriver(): void {
        foreach (['text', 'math', 'checkbox', 'swipe', 'puzzle'] as $name)
            $this->assertTrue(CaptchaManager::config('drivers.available.'.$name)::required(), $name);

        foreach (['snake', 'flappy', 'arkanoid', 'tetris', 'pacman', 'battlecity'] as $name)
            $this->assertFalse(CaptchaManager::config('drivers.available.'.$name)::required(), $name);
    }

    public function testMathRegistersItsOwnName(): void {
        \ST_system\Captcha\CaptchaDriver::resetEmitted();

        $this->assertStringContainsString('register("math"', $this->captcha()->includeJs());
    }
}
