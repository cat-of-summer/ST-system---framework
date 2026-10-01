<?php

namespace ST_system\Tests\Unit\Captcha;

use ST_system\Captcha\CaptchaManager;
use ST_system\Captcha\Drivers\InvisibleCaptchaDriver;
use ST_system\Tests\TestCase;

/** Полный цикл на невидимой капче: она не требует ни GD, ни внешних сервисов. */
final class CaptchaManagerTest extends TestCase {

    private function captcha(string $form = 'login', array $config = []): CaptchaManager {
        // min_score = 0: поведенческий скоринг проверяется в BehaviorTest, здесь — жизненный цикл челленджа.
        return CaptchaManager::make($form, $config + [
            'driver'    => 'invisible',
            'min_score' => 0,
            'cache'     => ['driver' => 'filesystem', 'dir' => ST_TESTS_ROOT.'/captcha'],
        ]);
    }

    /** @return array{0:string,1:string,2:string} [id, nonce, honeypot-поле] */
    private static function issued(string $html): array {
        preg_match('/name="st-captcha-id" value="([0-9a-f]{32})"/', $html, $id);
        preg_match('/"nonce":"([0-9a-f]+)"/', $html, $nonce);
        preg_match('/class="st-captcha-hp" name="(c_[0-9a-f]+)"/', $html, $hp);

        return [$id[1], $nonce[1], $hp[1]];
    }

    private static function payload(string $id, string $answer, array $extra = []): array {
        return $extra + ['st-captcha-id' => $id, 'st-captcha-answer' => $answer, 'st-captcha-behavior' => ''];
    }

    public function testDefaultDriverIsInvisible(): void {
        $this->assertInstanceOf(InvisibleCaptchaDriver::class, $this->captcha()->driver);
        $this->assertSame('invisible', InvisibleCaptchaDriver::name());
    }

    public function testPutCaptchaRendersFieldsAndScript(): void {
        $html = $this->captcha()->putCaptcha();

        $this->assertStringContainsString('data-st-captcha="invisible"', $html);
        $this->assertStringContainsString('<input type="hidden" name="st-captcha-behavior" value="">', $html);
        $this->assertStringContainsString('window.STCaptchaQueue', $html);
        $this->assertCount(3, array_filter(self::issued($html)));
    }

    public function testSolvedChallengePassesOnce(): void {
        $captcha = $this->captcha();
        [$id, $nonce] = self::issued($captcha->putCaptcha());

        $this->assertTrue($this->captcha()->check(self::payload($id, $nonce)));

        $replay = $this->captcha();
        $this->assertFalse($replay->check(self::payload($id, $nonce)));
        $this->assertSame('replayed', $replay->error);
    }

    public function testWrongAnswerCountsAttempts(): void {
        [$id, $nonce] = self::issued($this->captcha()->putCaptcha());

        for ($i = 0; $i < 3; $i++) {
            $captcha = $this->captcha();
            $this->assertFalse($captcha->check(self::payload($id, 'wrong')));
            $this->assertSame('answer', $captcha->error);
        }

        $captcha = $this->captcha();
        $this->assertFalse($captcha->check(self::payload($id, $nonce)));
        $this->assertSame('attempts', $captcha->error);
    }

    public function testChallengeIsBoundToForm(): void {
        [$id, $nonce] = self::issued($this->captcha('contacts')->putCaptcha());

        $captcha = $this->captcha('login');
        $this->assertFalse($captcha->check(self::payload($id, $nonce)));
        $this->assertSame('mismatch', $captcha->error);
    }

    public function testHoneypotRejects(): void {
        [$id, $nonce, $hp] = self::issued($this->captcha()->putCaptcha());

        $captcha = $this->captcha();
        $this->assertFalse($captcha->check(self::payload($id, $nonce, [$hp => 'bot'])));
        $this->assertSame('honeypot', $captcha->error);
    }

    public function testUnknownOrMalformedIdIsExpired(): void {
        $captcha = $this->captcha();

        $this->assertFalse($captcha->check(self::payload(str_repeat('a', 32), 'x')));
        $this->assertSame('expired', $captcha->error);

        $this->assertFalse($captcha->check(self::payload('../etc', 'x')));
        $this->assertSame('expired', $captcha->error);
    }

    public function testLowBehaviorScoreRejectsCorrectAnswer(): void {
        $captcha = $this->captcha('login', ['min_score' => 0.5]);
        [$id, $nonce] = self::issued($captcha->putCaptcha());

        $check = $this->captcha('login', ['min_score' => 0.5]);
        $this->assertFalse($check->check(self::payload($id, $nonce)));
        $this->assertSame('behavior', $check->error);
        $this->assertSame(0.0, $check->score);
        $this->assertSame(['no_behavior_data'], $check->reasons);
    }

    public function testEmptyKeyIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);

        CaptchaManager::make('');
    }

    public function testUnknownDriverSugarThrows(): void {
        $this->expectException(\BadMethodCallException::class);

        CaptchaManager::nothing('form');
    }
}
