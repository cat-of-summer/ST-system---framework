<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;

final class SmartCaptcha extends IntegrationDriver {

    protected static array $CONFIG = ['endpoint' => 'https://smartcaptcha.yandexcloud.net/'];

    private string $secret;

    protected function __init(): void {
        $this->on('__construct', function(string $secret) {
            if (empty($secret))
                throw new \InvalidArgumentException('Передан некорректный secret');

            $this->secret = $secret;
        });

        $this->on('call', function($m, &$params) {
            $params['secret'] = $this->secret;
        });

        $this->registerMethodsMap([
            'validate' => [
                'method' => 'POST',
                'params' => [
                    'token' => 'required|string',
                    'ip'    => 'nullable|string',
                ],
            ],
        ]);
    }

    public function validate($params): mixed {
        if (!is_array($params))
            $params = ['token' => (string)$params];

        return $this->call('validate', $params);
    }

}