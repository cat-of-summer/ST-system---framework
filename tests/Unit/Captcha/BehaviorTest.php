<?php

namespace ST_system\Tests\Unit\Captcha;

use ST_system\Captcha\Behavior;
use ST_system\Tests\TestCase;

final class BehaviorTest extends TestCase {

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';

    private array $server;

    protected function setUp(): void {
        $this->server = $_SERVER;
        $_SERVER['HTTP_USER_AGENT'] = self::UA;
    }

    protected function tearDown(): void {
        $_SERVER = $this->server;

        parent::tearDown();
    }

    /** Данные, которые собрал бы живой человек в обычном браузере. */
    private static function human(): array {
        $points = [];
        foreach ([[0, 0, 0], [16, 4, 3], [40, 15, 5], [55, 18, 12], [90, 30, 14], [120, 28, 25], [150, 45, 22], [200, 60, 40], [230, 58, 45], [290, 80, 60]] as $p)
            $points[] = $p;

        return [
            'basic' => ['solve' => 2500, 'first' => 400, 'ev' => ['move' => 30], 'pts' => $points],
            'env'   => [
                'ua' => self::UA, 'platform' => 'Win32', 'languages' => 2, 'plugins' => 3,
                'screen' => [1920, 1080], 'inner' => [1280, 900], 'hc' => 8, 'tz' => 'Europe/Moscow',
            ],
            'fp'    => ['renderer' => 'ANGLE (NVIDIA)', 'canvas' => 'abc', 'audio' => '124.04', 'fonts' => 30],
        ];
    }

    private static function score(array $data, array $signals = Behavior::GROUPS, array $state = [], array $config = []): array {
        return Behavior::score(json_encode($data), $state + ['signals' => $signals], $config);
    }

    public function testNoSignalsMeansFullTrust(): void {
        $this->assertSame(['score' => 1.0, 'report' => [], 'reasons' => []], Behavior::score(null, [], []));
        $this->assertSame(1.0, Behavior::score(null, ['signals' => ['unknown']], [])['score']);
    }

    public function testMissingDataScoresZero(): void {
        $result = Behavior::score('', ['signals' => ['basic', 'env']], []);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(['basic' => 0.0, 'env' => 0.0], $result['report']);
        $this->assertSame(['no_behavior_data'], $result['reasons']);
    }

    public function testHumanSessionScoresHigh(): void {
        $result = self::score(self::human(), ['basic', 'env', 'fingerprint']);

        $this->assertSame([], $result['reasons']);
        $this->assertSame(1.0, $result['score']);
    }

    /** @dataProvider botCases */
    public function testBotSignals(callable $mutate, string $reason, float $groupScore, string $group): void {
        $data = self::human();
        $mutate($data);

        $result = self::score($data, ['basic', 'env', 'fingerprint']);

        $this->assertContains($reason, $result['reasons']);
        $this->assertSame($groupScore, $result['report'][$group]);
        $this->assertLessThan(1.0, $result['score']);
    }

    public function botCases(): array {
        return [
            'honeypot'     => [function (&$d) { $d['basic']['hp'] = 1; }, 'honeypot', 0.0, 'basic'],
            'untrusted'    => [function (&$d) { $d['basic']['trusted'] = false; }, 'untrusted_events', 0.0, 'basic'],
            'no interaction' => [function (&$d) { $d['basic']['solve'] = 0; }, 'no_interaction', 0.0, 'basic'],
            'too fast'     => [function (&$d) { $d['basic']['solve'] = 300; }, 'too_fast', 0.55, 'basic'],
            'linear move'  => [function (&$d) {
                $d['basic']['pts'] = array_map(fn($i) => [$i * 10, $i * 5, $i * 5], range(0, 9));
            }, 'linear_pointer', 0.2, 'basic'],
            'webdriver'    => [function (&$d) { $d['env']['webdriver'] = true; }, 'webdriver', 0.0, 'env'],
            'automation'   => [function (&$d) { $d['env']['automation'] = ['__playwright', 'other']; }, 'automation:__playwright', 0.0, 'env'],
            'headless'     => [function (&$d) { $d['env']['headless'] = true; }, 'headless_ua', 0.4, 'env'],
            'ua mismatch'  => [function (&$d) { $d['env']['ua'] = 'curl/8'; }, 'ua_mismatch', 0.6, 'env'],
            'platform'     => [function (&$d) { $d['env']['platform'] = 'MacIntel'; }, 'platform_mismatch', 0.7, 'env'],
            'software gpu' => [function (&$d) { $d['fp']['renderer'] = 'Google SwiftShader'; }, 'software_renderer', 0.3, 'fingerprint'],
        ];
    }

    public function testWeightsAndDisabledGroups(): void {
        $data = self::human();
        $data['env']['webdriver'] = true;

        $weighted = self::score($data, ['basic', 'env'], [], ['weights' => ['basic' => 3, 'env' => 1]]);
        $this->assertSame(0.75, $weighted['score']);

        $ignored = self::score($data, ['basic', 'env'], [], ['weights' => ['env' => 0]]);
        $this->assertSame(1.0, $ignored['score']);
    }

    public function testProofOfWork(): void {
        $state = ['pow' => ['challenge' => 'abc', 'difficulty' => 8]];

        $nonce = 0;
        while (ord(hash('sha256', 'abc'.$nonce, true)[0]) !== 0) $nonce++;

        $this->assertSame(1.0, self::score(['pow' => ['nonce' => (string)$nonce]], ['pow'], $state)['score']);

        $bad = self::score(['pow' => ['nonce' => 'wrong']], ['pow'], $state);
        $this->assertSame(0.0, $bad['score']);
        $this->assertSame(['pow_invalid'], $bad['reasons']);

        $this->assertSame(['pow_missing'], self::score(['pow' => []], ['pow'], $state)['reasons']);
        $this->assertSame(1.0, self::score(['pow' => []], ['pow'], [])['score']);
    }
}
