<?php

namespace ST_system\API\Drivers;

use \ST_system\API\Integration_driver;

final class RentalCRM extends Integration_driver {
    private $SETTINGS = [];

    protected function __init() {

        $this->register_rules_map([
            'string' => [null, fn($v) => is_string($v) && !empty($v)],
            'bool' => [null, fn($v) => is_bool($v) || in_array($v, ['Y', 'N']), fn($v) => is_bool($v) ? ($v ? 'Y' : 'N') : $v],
        ]);

        $this->on('__construct', function(array $PARAMS = []) {
            $this->SETTINGS = static::prepare_params([
                'subdomain' => [new \Exception("Задан некорректный субдомен"), 'string'],
                'api_key' => [null, 'string'],
            ], $PARAMS, function (&$params) {
                $params['point'] = "https://{$params['subdomain']}.retailcrm.ru/api/v5";

                if (!filter_var($params['point'], FILTER_VALIDATE_URL))
                    throw new \Exception("Задана некорректная точка API");
                
                unset($params['subdomain']);
            });
        });

        $this->on('build_url', function(string &$request_url) {
            $request_url = $this->SETTINGS['point'].$request_url;
        });

        $this->on('before_curl_init', function(string $request_url, string $request_method, array &$params) {
            $params['apiKey'] = $this->SETTINGS['api_key'];
        });

        $this->register_methods_map([
            'orders' => [
                'method' => 'GET',
                'params' => [
                    'filter' => [null, fn($v) => is_array($v), fn($v) => static::prepare_params([
                        'ids' => [null, fn($v) => is_array($v) && count($v) == count(array_filter($v, 'is_int'))],
                    ], $v)],
                ]
            ],
            'orders/create' => [
                'method' => 'POST',
                'params' => [
                    'site' => 'string',
                    'order' => [new \Exception("Не передан order"), fn($v) => is_array($v), fn($v) => static::prepare_params([
                        'customer' => [null, fn($v) => is_array($v) && array_intersect(['externalId','id','browserId'], array_keys($v))],
                        'customerComment' => 'string'
                    ], $v)]
                ],
                'on_prepare' => function(&$params) {
                    $params['order'] = json_encode($params['order']);
                }
            ],
            'customers' => [
                'method' => 'GET',
                'params' => [
                    'filter' => [null, fn($v) => is_array($v), fn($v) => static::prepare_params([
                        'name' => 'string',
                    ], $v)],
                ],
            ],
            'customers/create' => [
                'method' => 'POST',
                'params' => [
                    'site' => 'string',
                    'customer' => [new \Exception("Не передан customer"), fn($v) => is_array($v), fn($v) => static::prepare_params([
                        'firstName' => 'string',
                        'lastName' => 'string',
                        'patronymic' => 'string',
                        'email' => [null, fn($v) => filter_var($v, FILTER_VALIDATE_EMAIL)],
                        'phones' => [null, fn($v) => is_array($v), fn($v) => array_map(fn($phone) => static::prepare_params([
                            'number' => [new \Exception("Не передан номер телефона"), fn($v) => is_string($v)],
                        ], $phone), $v)],
                        'tags' => [null, fn($v) => is_array($v) && count($v) == count(array_filter($v, 'is_string'))],
                    ], $v)]
                ],
                'on_prepare' => function(&$params) {
                    $params['customer'] = json_encode($params['customer']);
                }
            ],
            'files/upload' => [
                'method' => 'POST',
                'params' => [
                    'file' => [new \Exception("Не передан file"), fn($v) => is_string($v) && file_exists($v) && is_readable($v), 'after' => fn($v) => new \CURLFile($v)],
                ],
            ],
            'tasks' => [
                'params' => []
            ],
            'tasks/create' => [
                'method' => 'POST',
                'params' => [
                    'site' => 'string',
                    'task' => [new \Exception("Не передан task"), fn($v) => is_array($v), fn($v) => static::prepare_params([
                        'customer' => [null, fn($v) => is_array($v) && array_intersect(['externalId','id'], array_keys($v))],
                        'order' => [null, fn($v) => is_array($v) && array_intersect(['externalId','id','number'], array_keys($v))],
                        'performerId' => [new \Exception("Не передан performerId"), fn($v) => is_int($v)],
                        'text' => 'string',
                        'commentary' => 'string',
                    ], $v)]
                ],
                'on_prepare' => function(&$params) {
                    $params['task'] = json_encode($params['task']);
                }
            ]
        ]);

    }
}