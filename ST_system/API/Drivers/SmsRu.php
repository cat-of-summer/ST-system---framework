<?php

namespace ST_system\API\Drivers;

use \ST_system\API\Integration_driver;

final class SmsRu extends Integration_driver {

    protected const DEFAULT_POINT = 'https://sms.ru/sms/';

    private $api_id;

    protected function __init() {
        $this->on('__construct', function(string $api_id) {
            if (empty($api_id))
                new \Exception("Передан некорректный api_id");

            $this->api_id = $api_id;
        });

        $this->on('call', function($m, &$params) {
            $params['api_id'] = $this->api_id;
        });

        $this->register_methods_map([
            'send' => [
                'params' => [
                    'to' => [new \Exception('Не передан список получателей'), fn($v) => is_array($v), fn($v) => array_filter($v, fn($v) => is_string($v))],
                    'msg' => '*string',
                    'json' => 'bool'
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['to']) && is_array($params['to']))
                        $params['to'] = implode(',', $params['to']);
                }
            ]
        ]);
    }

}