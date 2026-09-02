<?php

namespace ST_system\Captcha\Drivers\Service;

use ST_system\Captcha\CaptchaDriver;
use ST_system\Captcha\CaptchaManager;
use ST_system\HTTP\WebClient;
use ST_system\Main;

abstract class ServiceCaptchaDriver extends CaptchaDriver {

    protected const KEY_REGEX = '/^[a-zA-Z0-9_-]{20,100}$/';

    protected string $endpoint  = '';
    protected string $clientKey = '';
    protected string $secret    = '';
    protected array  $widget    = [];

    protected static function serviceConfig(array $extra = []): array {
        return array_merge(static::baseConfig(), [
            'endpoint'   => '',
            'client_key' => '',
            'secret'     => '',
            'class'      => '',
            'style'      => '',
            'behavior'   => array_merge(
                (array)CaptchaManager::config('default.behavior'),
                ['signals' => []]
            ),
        ], $extra);
    }

    abstract protected function initWidget(array $config): array;

    protected function rebindWidget(array $override): void {}

    final protected function __init(array $config): void {
        $this->endpoint  = (string)($config['endpoint'] ?? '');
        $this->clientKey = (string)($config['client_key'] ?? '');
        $this->secret    = (string)($config['secret'] ?? '');

        static::assertKeyFormat('client_key', $this->clientKey);
        static::assertKeyFormat('secret', $this->secret);

        $this->widget = array_merge($this->initWidget($config), [
            'class' => (string)($config['class'] ?? ''),
            'style' => (string)($config['style'] ?? ''),
        ]);
    }

    final protected function __rebind(array $override): void {
        foreach (['endpoint', 'client_key', 'secret'] as $key) {
            if (!isset($override[$key])) continue;

            $value = (string)$override[$key];

            if ($key !== 'endpoint') static::assertKeyFormat($key, $value);

            if ($key === 'endpoint')   $this->endpoint  = $value;
            if ($key === 'client_key') $this->clientKey = $value;
            if ($key === 'secret')     $this->secret    = $value;
        }

        foreach (array_keys($this->widget) as $key)
            if (isset($override[$key]))
                $this->widget[$key] = is_bool($this->widget[$key]) ? (bool)$override[$key] : (string)$override[$key];

        $this->rebindWidget($override);
    }

    public function isAvailable(): bool {
        return $this->clientKey !== '' && $this->secret !== '' && $this->endpoint !== '';
    }

    protected function forcedSignals(): array {
        return [];
    }

    final protected function url(string $path = ''): string {
        return Main::glue([$this->endpoint, $path], '/');
    }

    final protected function ask(string $url, array $params): array {
        $results = WebClient::create($url, [
            'method'        => 'POST',
            'verify'        => true,
            'response_type' => 'json',
            'headers'       => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ])->fill($params)->send();

        $body = $results[0]['body'] ?? null;

        if (is_string($body)) $body = @json_decode($body, true);

        return is_array($body) ? $body : [];
    }

    final protected function hostClass(string $base): string {
        return ' class="'.self::esc(Main::glue([$base, (string)($this->widget['class'] ?? '')], ' ')).'"';
    }

    final protected function hostStyle(): string {
        $style = (string)($this->widget['style'] ?? '');

        return $style !== '' ? ' style="'.self::esc($style).'"' : '';
    }

    private static function assertKeyFormat(string $name, string $value): void {
        if ($value !== '' && !preg_match(static::KEY_REGEX, $value))
            throw new \InvalidArgumentException(Main::basename(static::class).": invalid {$name} format");
    }
}
