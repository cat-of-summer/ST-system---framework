<?php

namespace ST_system\API\Drivers;

use ST_system\API\IntegrationDriver;
use ST_system\Rule;

final class IpInfo extends IntegrationDriver {

    protected static function getDefaultConfig(): array {
        return [
            'endpoint' => 'https://api.ipinfo.io/',
            'cache' => [
                'use' => true
            ]
        ];
    }

    private string $token;
    private string $service = 'lite';

    protected function __init(): void {

        $this->on('__construct', function(string $token, string $service = 'lite') {
            Rule::create('string|in:lite|default:lite')->throwable()->check($service);

            $this->token = $token;
            $this->service = $service;
        });

        $this->on('build_url', function(&$request_url, $endpoint, $method, &$params) {
            $params['token'] = $this->token;
            $request_url = rtrim($this->getEndpoint(), '/') . '/' . $this->service . '/' . $params['ip'];
            unset($params['ip']);
        });

        $this->registerMethodsMap([
            'getDetails' => [
                'params' => [
                    'ip' => 'string|required|default:me'
                ],
                'cache_ttl' => -1
            ],
        ]);

    }

    public function getDetails(string $ip = 'me'): array {
        return $this->call('getDetails', ['ip' => $ip]);
    }

}