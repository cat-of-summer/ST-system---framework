<?php

namespace ST_system\Captcha\Drivers;

use ST_system\Captcha\CaptchaDriver;

class CheckboxCaptchaDriver extends CaptchaDriver {

    protected function __init(array $config): void {}

    public function isAvailable(): bool {
        return true;
    }

    protected function forcedSignals(): array {
        return ['basic', 'env'];
    }

    protected function issue(array $params): array {
        $nonce = bin2hex(random_bytes(8));

        return [['nonce' => $nonce], ['nonce' => $nonce]];
    }

    protected function render(array $public, string $id): string {
        return '<label class="st-captcha-box"><input type="checkbox" class="st-captcha-checkbox"></label>';
    }

    protected function verify(array $payload, array $state): bool {
        $expected = (string)($state['secret']['nonce'] ?? '');

        return $expected !== '' && hash_equals($expected, $this->answer($payload));
    }

    protected function driverCss(): string {
        return '.st-captcha-box{display:inline-flex;align-items:center;gap:8px;padding:8px;border:1px solid;cursor:pointer}'
             . '.st-captcha-checkbox{width:20px;height:20px;margin:0;cursor:pointer}';
    }

    protected function driverJs(): string {
        return <<<'JS'
window.STCaptcha && STCaptcha.register('checkbox', class extends STCaptcha.Driver {

    onMount() {
        var self = this;
        var box  = this.root.querySelector('.st-captcha-checkbox');

        if (!box) return;

        box.addEventListener('change', function (e) {
            if (e.isTrusted === false) {
                box.checked = false;
                self.fail('untrusted');
                return;
            }

            if (box.checked) self.solve(self.cfg.nonce);
            else             self.fail('unchecked');
        });
    }
});
JS;
    }
}
