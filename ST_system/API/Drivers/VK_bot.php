<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;

final class VK_bot extends IntegrationDriver {

    protected const DEFAULT_POINT = 'https://api.vk.com/method';
    protected const OAUTH_POINT = 'https://oauth.vk.com';

    protected const API_VERSION = '5.258';

    private $client_id;
    private $client_secret;

    private $auth_method;
    private $redirect_uri;
    private $scope;
    private $user_id;
    private $code;
    private $access_token;

    protected function __init() {

        $this->on('__construct', function($params) {

            self::prepare_params([
                'client_id' => [new \Exception("Передан некорректный client_id"), fn($value) => is_int($value)],
                'client_secret' => [new \Exception("Передан некорректный client_secret"), fn($value) => is_string($value)],
            ], $params);

            $this->client_id = $params['client_id'];
            $this->client_secret = $params['client_secret'];
        });

        $this->on('before_curl_init', function($request_url, $request_method, &$params) {
            $params['v'] = static::API_VERSION;
        });

        $this->on('call', function($method, &$params) {
            if (!$this->access_token)
                throw new \Exception("Для доступа методу '{$method}' необходим авторизационный токен!");
            
            $params['access_token'] = $this->access_token;
        });

        $this->on('call', function($method) {
            $meta = $this->method_config($method)['meta'] ?? [];
            $array_diff = array_diff($meta['scope'], $this->scope);

            if (!empty($array_diff))
                throw new \Exception("Недостаточно прав для получения метода {$method}. Необходим доступ к ".implode(', ', $array_diff).".");
        });

        $this->register_method('authorize', function ($params) {

            $request_url = $this->build_url('authorize', static::OAUTH_POINT)[0];
            
            self::prepare_params([
                'redirect_uri' => [null, fn($value) => is_string($value) && !empty($value)],
                'scope' => [[], fn($value) => is_array($value)],
                'display' => ['page', fn($value) => is_string($value) && in_array($value, ['page', 'popup', 'mobile'])],
                'response_type' => ['token', fn($value) => is_string($value) && in_array($value, ['code', 'token'])],
                'client_id' => [$this->client_id, fn($v) => false],
            ], $params);

            $this->code = null;
            $this->access_token = null;
            $this->auth_method = $params['response_type'];
            $this->redirect_uri = $params['redirect_uri'];
            $this->scope = $params['scope'];

            $curl = $this->curl_init($request_url, 'GET', $params);

            return curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        });

        $this->register_method('access_token', function ($params) {
            
            $access_token = $this->load_token([
                'user_id' => $params['user_id'] ?? null,
                'client_id' => $this->client_id
            ]);

            if ($access_token) {
                $result = [
                    'access_token' => $access_token,
                    'user_id' => $params['user_id']
                ];
            } else {
                switch ($this->auth_method) {
                    case 'code':                  
                        $request_url = $this->build_url('access_token', static::OAUTH_POINT)[0];

                        self::prepare_params([
                            'code' => [new \Exception("Некорректный код авторизации!"), fn($value) => is_string($value) && !empty($value)],
                        ], $params);

                        $curl = $this->curl_init($request_url, 'POST', [
                            'redirect_uri' => $this->redirect_uri,
                            'client_id' => $this->client_id,
                            'client_secret' => $this->client_secret,
                            'code' => $params['code']
                        ]);

                        $response_data = $this->execute_curl($curl);
                        if ($response_data['error'])
                            throw new \Exception("Ошибка при запросе к API: '{$response_data['error']}' в ".get_called_class());

                        $response_data['response'] = @json_decode($response_data['response'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) 
                            throw new \Exception("Ошибка при декодировании ответа: '".json_last_error_msg()."' в ".get_called_class());
                                            
                        $result = $response_data['response'];

                        break;
                    case 'token':
                    default:
                        $result = $params;

                        break;
                }

                self::prepare_params([
                    'access_token' => [new \Exception("Некорректный параметр access_token"), fn($value) => is_string($value) && !empty($value)],
                    'user_id' => [new \Exception("Некорректный параметр user_id"), fn($value) => is_int($value) && $value > 0],
                    'expires_in' => [new \Exception("Некорректный параметр expires_in"), fn($value) => is_int($value)],
                ], $result);
                
                $this->save_token($result['access_token'], [
                    'expires_in' => $result['expires_in'],
                    'user_id' => $result['user_id'],
                    'client_id' => $this->client_id
                ]);                
            }

            $this->access_token = $result['access_token'];
            $this->user_id = $result['user_id'];
        });

        $this->register_methods_map([
            'users.getFollowers' => [
                'meta' => [
                    'scope' => ['friends']
                ],
                'params' => [
                    'user_id' => [$this->user_id, fn($value) => is_null($value) || (is_int($value) && $value > 0)],
                    'count'   => [100, fn($value) => is_int($value) && $value > 0],
                    'offset'  => [0, fn($value) => is_int($value) && $value >= 0],
                    'fields'  => [[], fn($value) => is_array($value)],
                ],
            ],
            'users.get' => [
                'meta' => [
                    'scope' => []
                ],
                'params' => [
                    'user_ids' => [[], fn($value) => is_array($value)],
                    'fields'   => [[], fn($value) => is_array($value)],
                ],
            ],
            'account.ban' => [
                'meta' => [
                    'scope' => ['account']
                ],
                'params' => [
                    'owner_id' => [new \Exception("Некорректный owner_id"), fn($value) => is_scalar($value) && !empty($value)],
                ],
            ],
            'friends.delete' => [
                'meta' => [
                    'scope' => ['friends']
                ],
                'params' => [
                    'user_id' => [new \Exception("Некорректный user_id"), fn($value) => is_scalar($value) && !empty($value)],
                ],
            ],
            'friends.getSuggestions' => [
                'meta' => [
                    'scope' => ['friends']
                ],
                'params' => [
                    'filter' => [null, fn($value) => is_string($value) && in_array($value, ['mutual'])],
                    'count'   => [100, fn($value) => is_int($value) && $value > 0],
                    'offset'  => [0, fn($value) => is_int($value) && $value >= 0],
                    'fields' => [[], fn($value) => is_array($value)],
                ],
            ],
            'friends.add' => [
                'meta' => [
                    'scope' => ['friends']
                ],
                'params' => [
                    'user_id' => [new \Exception("Некорректный user_id"), fn($value) => is_scalar($value) && !empty($value)],
                    'text'    => [null, fn($value) => is_string($value)],
                    'follow'  => [false, fn($value) => is_bool($value)],
                ],
            ],
        ]);
        
    }

    
}