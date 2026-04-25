<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class SmsRu extends IntegrationDriver {

    protected static array $CONFIG = ['endpoint' => 'https://sms.ru/sms/'];

    private string $api_id;

    protected function __init(): void {
        $this->on('__construct', function(string $api_id) {
            if (empty($api_id))
                throw new \InvalidArgumentException('Передан некорректный api_id');

            $this->api_id = $api_id;
        });

        $this->on('call', function($m, &$params) {
            $params['api_id'] = $this->api_id;
        });

        $this->registerMethodsMap([
            'send' => [
                'params' => [
                    'to'   => Rule::create(fn(&$v) => is_array($v) && count($v) > 0)
                        ->handleError(fn($v) => 'Не передан список получателей')
                        ->after(fn(&$v) => $v = array_values(array_filter($v, 'is_string')))
                        ->skip(true),
                    'msg'  => 'required|string',
                    'json' => 'nullable|bool',
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['to']) && is_array($params['to']))
                        $params['to'] = implode(',', $params['to']);
                },
            ],
        ]);
    }

}