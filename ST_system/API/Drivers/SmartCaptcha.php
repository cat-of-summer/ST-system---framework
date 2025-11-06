<?php

namespace ST_system\API\Drivers;

use \ST_system\API\Integration_driver;

final class SmartCaptcha extends Integration_driver {

    protected const DEFAULT_POINT = 'https://smartcaptcha.yandexcloud.net/';

    private $secret;

    protected function __init() {
        $this->on('__construct', function(string $secret) {
            if (empty($secret))
                new \Exception("Передан некорректный api_id");

            $this->secret = $secret;
        });

        $this->on('call', function($m, &$params) {
            $params['secret'] = $this->secret;
        });

        $this->register_methods_map([
            'validate' => [
                'method' => 'POST',
                'params' => [
                    'token' => '*string',
                    'ip' => 'string'
                ]
            ]
        ]);
    }

    public function validate($params) {
        if (!is_array($params))
            $params = [
                'token' => (string)$params
            ];
            
        return $this->call('validate', $params);
    }

}