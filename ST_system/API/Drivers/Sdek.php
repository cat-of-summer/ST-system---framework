<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;

final class Sdek extends IntegrationDriver {

    protected const DEFAULT_POINT = 'https://api.cdek.ru/v2/';
    protected const CACHE_DIRECTORY = '~/bitrix/cache/';

    private $SETTINGS = [];
    
    protected function __init() {
        static::register_rules_map([
            'CalculatorLocationDto' => [fn($k) => new \Exception("Не передан параметр {$k}"), fn($v) => is_array($v), fn($v) => static::prepare_params([
                'code' => '*int',
                'postal_code' => '*string',
                'country_code' => '*string',
                'city' => '*string',
                'address' => '*string',
                'contragent_type' => ['INDIVIDUAL', fn($v) => is_string($v) && in_array($v, ['LEGAL_ENTITY', 'INDIVIDUAL'])],
                'longitude' => '*string',
                'latitude' => '*string',
            ], $v)],
            'array_of_CalcPackageRequestDto' => [fn($k) => new \Exception("Не передан параметр {$k}"), fn($v) => is_array($v), fn($v) => array_map(fn($i) => static::prepare_params([
                'weight' => '*int',
                'length' => 'int',
                'width' => 'int',
                'height' => 'int',
            ], $i), $v)],
        ]);
        
        $params = [
            'pagination' => [
                'page' => 'int',
                'size' => [null, fn($v) => is_int($v), fn($v, $k, $p) => (isset($p['page']) && empty($v)) ? 1000 : $v],
            ],
            'default' => [
                'lang' => 'string',
                'country_code' => 'string',
                'region_code' => 'int',
                'city_code' => 'int',
            ],
        ];

        $this->on('__construct', function(array $PARAMS) {
            $this->SETTINGS = array_intersect_key(
                $this->call('oauth/token', $PARAMS), 
                array_flip([
                    'access_token',
                    'token_type',
                    'jti'
                ]));
        });

        $this->on('prepare_response', function($method, $params, &$raw_data) {
            if ($raw_data['http_code'] < 200 || $raw_data['http_code'] > 299) 
                $raw_data['error'] = $raw_data['response'];
        });

        $this->on('before_curl_init', function($r, $m, $p, &$config) {
            if ($this->SETTINGS['access_token'] ?? false)
                $config['headers']['Authorization'] = "Bearer {$this->SETTINGS['access_token']}";
        });

        $this->on('save_cache', function($m, $p, $response, &$meta) {
            if ($cache_ttl = $response['expires_in'])
                $meta['cache_expires_in'] = time() + (int)$cache_ttl;
        });

        $this->register_methods_map([
            'oauth/token' => [
                'method' => 'POST',
                'params' => [
                    'grant_type' => ['client_credentials', fn($v) => is_string($v) && in_array($v, ['client_credentials'])],
                    'client_id' => '*string',
                    'client_secret' => '*string',
                ],
                'cache_ttl' => 3600
            ],
            'location/suggest/cities' => [
                'params' => [
                    'name' => '*string',
                    'country_code' => 'string',
                ],
                'cache_ttl' => 3600
            ],
            'location/cities' => [
                'params' => array_merge(
                    array_diff_key($params['default'], array_flip(['city_code'])),
                    $params['pagination'],
                ),
                'cache_ttl' => 3600
            ],
            'deliverypoints' => [
                'params' => array_merge(
                    ['type' => ['ALL', fn($v) => is_string($v) && in_array($v, ['PVZ', 'ALL', 'POSTAMAT'])]],
                    $params['default'],
                    $params['pagination'],
                ),
                'cache_ttl' => 3600
            ],
            'calculator/tariff' => [
                'method' => 'POST',
                'content_type' => 'application/json',
                'params' => [
                    'tariff_code'=> '*int',
                    'type' => [1, fn($v) => is_int($v) && in_array($v, [1, 2])],
                    'from_location'=> 'CalculatorLocationDto',
                    'to_location'=> 'CalculatorLocationDto',
                    'packages'=> 'array_of_CalcPackageRequestDto',
                ],
            ],
            'orders'=>[
                'params'=>[
                    'cdek_number' => '*string',
                ],
            ],
            'calculator/alltariffs' => [
                'cache_ttl' => 3600
            ]
        ]);
        
    }

}