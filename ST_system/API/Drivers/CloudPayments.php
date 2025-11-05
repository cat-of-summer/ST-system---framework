<?php

namespace ST_system\API\Drivers;

use \ST_system\API\Integration_driver;

final class CloudPayments extends Integration_driver {

    protected const DEFAULT_POINT = 'https://api.cloudpayments.ru/';
    
    private $SETTINGS = [];
    
    protected function __init() {
        $this->on('__construct', function(array $PARAMS) {
            $this->SETTINGS = static::prepare_params([
                'public_id' => '*string',
                'api_secret' => '*string'
            ], $PARAMS);
        });

        $this->on('before_curl_init', function($r, $m, $p, &$config) {
            $config['method'] = 'POST';
        });

        $this->on('curl_init', function($curl) {
            curl_setopt_array($curl, [
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => "{$this->SETTINGS['public_id']}:{$this->SETTINGS['api_secret']}",
            ]);
        });

        $this->on('prepare_response', function($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) 
                $raw_data['error'] = $raw_data['response'];
        });

        $this->register_methods_map([
            'test' => [],
            'payments/find' => [
                'params' => [
                    'InvoiceId' => '*string'
                ]
            ],
            'orders/create' => [
                'params' => [
                    'Amount' => $this->extend_rule('*float', ['after' => fn($v) => number_format($v, 2, '.', '')]),
                    'Currency' => ['RUB', fn($v) => in_array($v, ['RUB', 'EUR', 'USD'])],
                    'Description' => '*string',
                    'InvoiceId' => 'string',
                    'AccountId' => 'string',
                    'SuccessRedirectUrl' => 'url'
                ],
            ],
            'orders/cancel' => [
                'params' => [
                    'Id' => '*string'
                ]
            ],
            'site/notifications/{Type}/update' => [
                'content_type' => 'application/json',
                'params' => [
                    'Type' => [new \Exception("Не передан обязательный параметр 'Type'"), fn($v) => in_array($v, ['Pay', 'Fail', 'Confirm', 'Refund', 'Recurrent', 'Cancel'])],
                    'Address' => 'url',
                    'IsEnabled' => 'bool',
                    'HttpMethod' => ['GET', fn($v) => in_array($v, ['GET', 'POST'])],
                    'Encoding' => ['UTF8', fn($v) => in_array($v, ['UTF8', 'Windows1251'])],
                    'Format' => ['CloudPayments', fn($v) => in_array($v, ['CloudPayments', 'QIWI', 'RT'])],
                ],
                'on_prepare' => function($params) {
                    if ($params['IsEnabled'] == true && empty($params['Address']))
                        throw new \Exception("Не передан обязательный параметр Address!");
                }
            ],
        ]);
        
    }

}