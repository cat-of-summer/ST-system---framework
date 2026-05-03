<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;

final class Sdek extends IntegrationDriver {

    protected static function getDefaultConfig(): array { return ['endpoint' => 'https://api.cdek.ru/v2/', 'cache' => ['dir' => '~/cache/', 'ttl' => 86400]]; }

    private array $SETTINGS = [];

    protected function __init(): void {

        // -- Local Rule helpers ----------------------------------------

        $r_location = Rule::create(function(&$v) {
            if (!is_array($v)) return false;
            $errors = Rule::object([
                'code'             => 'nullable|int',
                'postal_code'      => 'nullable|string',
                'country_code'     => 'nullable|string',
                'city'             => 'nullable|string',
                'address'          => 'nullable|string',
                'contragent_type'  => 'default:INDIVIDUAL|in:LEGAL_ENTITY,INDIVIDUAL',
                'longitude'        => 'nullable|string',
                'latitude'         => 'nullable|string',
            ])->apply($v);
            if (!empty($errors)) throw new \Exception($errors[0]);
            return true;
        })->handleError(fn($v) => '������������ CalculatorLocationDto')->skip(true);

        $r_packages = Rule::create(function(&$v) {
            if (!is_array($v)) return false;
            foreach ($v as &$item) {
                $errors = Rule::object([
                    'weight' => 'required|int',
                    'length' => 'nullable|int',
                    'width'  => 'nullable|int',
                    'height' => 'nullable|int',
                ])->apply($item);
                if (!empty($errors)) throw new \Exception($errors[0]);
            }
            unset($item);
            return true;
        })->handleError(fn($v) => '������������ array_of_CalcPackageRequestDto')->skip(true);

        // -- Common param groups ---------------------------------------

        $default_params = [
            'lang'         => 'nullable|string',
            'country_code' => 'nullable|string',
            'region_code'  => 'nullable|int',
            'city_code'    => 'nullable|int',
        ];

        $pagination_params = [
            'page' => 'nullable|int',
            'size' => 'nullable|int',
        ];

        $pagination_on_prepare = function(&$params) {
            if (isset($params['page']) && !isset($params['size']))
                $params['size'] = 1000;
        };

        // -- Constructor / events --------------------------------------

        $this->on('__construct', function(array $PARAMS) {
            $this->SETTINGS = array_intersect_key(
                $this->call('oauth/token', $PARAMS),
                array_flip(['access_token', 'token_type', 'jti'])
            );
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
            if ($cache_ttl = $response['expires_in'] ?? null)
                $meta['ttl'] = (int)$cache_ttl;
        });

        // -- Methods ---------------------------------------------------

        $this->registerMethodsMap([
            'oauth/token' => [
                'method'    => 'POST',
                'params'    => [
                    'grant_type'    => 'default:client_credentials|in:client_credentials',
                    'client_id'     => 'required|string',
                    'client_secret' => 'required|string',
                ],
                'cache_ttl' => 3600,
            ],
            'location/suggest/cities' => [
                'params'    => [
                    'name'         => 'required|string',
                    'country_code' => 'nullable|string',
                ],
                'cache_ttl' => 3600,
            ],
            'location/cities' => [
                'params'    => array_merge(
                    array_diff_key($default_params, ['city_code' => null]),
                    $pagination_params
                ),
                'on_prepare' => $pagination_on_prepare,
                'cache_ttl' => 3600,
            ],
            'deliverypoints' => [
                'params'    => array_merge(
                    ['type' => 'default:ALL|in:PVZ,ALL,POSTAMAT'],
                    $default_params,
                    $pagination_params
                ),
                'on_prepare' => $pagination_on_prepare,
                'cache_ttl' => 3600,
            ],
            'calculator/tariff' => [
                'method'       => 'POST',
                'content_type' => 'application/json',
                'params'       => [
                    'tariff_code'   => 'required|int',
                    'type'          => 'default:1|in:1,2',
                    'from_location' => $r_location,
                    'to_location'   => $r_location,
                    'packages'      => $r_packages,
                ],
            ],
            'orders' => [
                'params' => [
                    'cdek_number' => 'required|string',
                ],
            ],
            'calculator/alltariffs' => [
                'cache_ttl' => 3600,
            ],
        ]);

    }

}
