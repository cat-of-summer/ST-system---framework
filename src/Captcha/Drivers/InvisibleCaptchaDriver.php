<?php

namespace ST_system\Captcha\Drivers;

use ST_system\Captcha\CaptchaDriver;
use ST_system\Captcha\Behavior;

class InvisibleCaptchaDriver extends CaptchaDriver {

    protected static function getDefaultConfig(): array {
        return array_merge(static::baseConfig(), ['min_score' => 0.65]);
    }

    protected function __init(array $config): void {}

    public function isAvailable(): bool {
        return true;
    }

    protected function forcedSignals(): array {
        return Behavior::GROUPS;
    }

    protected function issue(array $params): array {
        $nonce = bin2hex(random_bytes(8));

        return [['nonce' => $nonce], ['nonce' => $nonce]];
    }

    protected function render(array $public, string $id): string {
        return '';
    }

    protected function verify(array $payload, array $state): bool {
        $expected = (string)($state['secret']['nonce'] ?? '');

        return $expected !== '' && hash_equals($expected, $this->answer($payload));
    }

    protected function driverJs(): string {
        return <<<'JS'
window.STCaptcha && STCaptcha.register('invisible', class extends STCaptcha.Driver {

    onMount() {
        this.solve(this.cfg.nonce);
    }
});
JS;
    }
}
