<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class CloudPayments extends IntegrationDriver {

    protected static array $CONFIG = ['endpoint' => 'https://api.cloudpayments.ru/', '];

    private array $SETTINGS = [];

    protected function __init(): void {

        $this->on('__construct', function(array $PARAMS) {
            $errors = Rule::object([
                'public_id'  => 'required|string',
                'api_secret' => 'required|string',
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $this->SETTINGS = $PARAMS;
        });

        $this->on('before_curl_init', function($r, $m, $p, &$config) {
            $config['method'] = 'POST';
        });

        $this->on('curl_init', function($curl) {
            curl_setopt_array($curl, [
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD  => "{$this->SETTINGS['public_id']}:{$this->SETTINGS['api_secret']}",
            ]);
        });

        $this->on('prepare_response', function($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299)
                $raw_data['error'] = $raw_data['response'];
        });

        $this->registerMethodsMap([
            'test' => [],
            'payments/find' => [
                'params' => [
                    'InvoiceId' => 'required|string',
                ],
            ],
            'orders/create' => [
                'params' => [
                    'Amount'             => Rule::create(fn(&$v) => is_numeric($v) && $v > 0)
                        ->handleError(fn($v) => 'Amount ������ ���� ������������� ������')
                        ->after(fn(&$v) => $v = number_format((float)$v, 2, '.', ''))
                        ->skip(true),
                    'Currency'           => 'default:RUB|in:RUB,EUR,USD',
                    'Description'        => 'required|string',
                    'InvoiceId'          => 'nullable|string',
                    'AccountId'          => 'nullable|string',
                    'SuccessRedirectUrl' => 'nullable|url',
                ],
            ],
            'orders/cancel' => [
                'params' => [
                    'Id' => 'required|string',
                ],
            ],
            'site/notifications/{Type}/update' => [
                'content_type' => 'application/json',
                'params' => [
                    'Type'       => Rule::create(fn(&$v) => in_array($v, ['Pay','Fail','Confirm','Refund','Recurrent','Cancel'], true))
                        ->handleError(fn($v) => "������������ ��� �����������")->skip(true),
                    'Address'    => 'nullable|url',
                    'IsEnabled'  => 'nullable|bool',
                    'HttpMethod' => 'default:GET|in:GET,POST',
                    'Encoding'   => 'default:UTF8|in:UTF8,Windows1251',
                    'Format'     => Rule::create(fn(&$v) => $v === null || in_array($v, ['CloudPayments','QIWI','RT'], true))
                        ->handleError(fn($v) => '������������ Format'),
                ],
                'on_prepare' => function($params) {
                    if ($params['IsEnabled'] == true && empty($params['Address']))
                        throw new \Exception("�� ������� ������������ �������� Address!");
                },
            ],
        ]);

    }

}
