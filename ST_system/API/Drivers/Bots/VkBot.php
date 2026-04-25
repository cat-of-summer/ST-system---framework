<?php

namespace ST_system\API\Drivers\Bots;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class VkBot extends IntegrationDriver {

    protected static array $CONFIG = [
        'endpoint'    => 'https://api.vk.com/method',
        'oauth_point' => 'https://oauth.vk.com'
    ];
    protected const API_VERSION      = '5.258';

    private $client_id;
    private $client_secret;

    private $auth_method;
    private $redirect_uri;
    private $scope;
    private $user_id;
    private $code;
    private $access_token;

    protected function __init(): void {

        $this->on('__construct', function(array $params) {
            $errors = Rule::object([
                'client_id'     => Rule::create(fn(&$v) => is_int($v))->handleError(fn($v) => 'Передан некорректный client_id')->skip(true),
                'client_secret' => Rule::create(fn(&$v) => is_string($v))->handleError(fn($v) => 'Передан некорректный client_secret')->skip(true),
            ])->apply($params);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);

            $this->client_id     = $params['client_id'];
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
            $meta       = $this->methods_map[$method]['meta'] ?? [];
            $array_diff = array_diff($meta['scope'] ?? [], $this->scope ?? []);

            if (!empty($array_diff))
                throw new \Exception("Недостаточно прав для метода {$method}. Необходим доступ к ".implode(', ', $array_diff).".");
        });

        $this->registerMethod('authorize', function(array $params) {

            [$request_url] = $this->build_url('authorize', (string)static::config('oauth_point'));

            $errors = Rule::object([
                'redirect_uri'  => Rule::create(fn(&$v) => $v === null || (is_string($v) && $v !== ''))->handleError(fn($v) => 'Некорректный redirect_uri'),
                'scope'         => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'scope должен быть массивом'),
                'display'       => Rule::create(fn(&$v) => $v === null || in_array($v, ['page', 'popup', 'mobile'], true))->handleError(fn($v) => 'Некорректный display'),
                'response_type' => Rule::create(fn(&$v) => $v === null || in_array($v, ['code', 'token'], true))->handleError(fn($v) => 'Некорректный response_type'),
            ])->apply($params);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $params['scope']         = $params['scope']         ?? [];
            $params['display']       = $params['display']       ?? 'page';
            $params['response_type'] = $params['response_type'] ?? 'token';
            $params['client_id']     = $this->client_id;

            $this->code          = null;
            $this->access_token  = null;
            $this->auth_method   = $params['response_type'];
            $this->redirect_uri  = $params['redirect_uri'] ?? null;
            $this->scope         = $params['scope'];

            $params = array_filter($params, fn($v) => $v !== null);
            return $request_url . '?' . http_build_query($params);
        });

        $this->registerMethod('access_token', function(array $params) {

            $access_token = $this->load_token([
                'user_id'   => $params['user_id'] ?? null,
                'client_id' => $this->client_id,
            ]);

            if ($access_token) {
                $result = ['access_token' => $access_token, 'user_id' => $params['user_id'] ?? null];
            } else {
                switch ($this->auth_method) {
                    case 'code':
                        [$request_url] = $this->build_url('access_token', (string)static::config('oauth_point'));

                        $errors = Rule::object([
                            'code' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'Некорректный код авторизации!')->skip(true),
                        ])->apply($params);
                        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);

                        $curl = $this->curl_init($request_url, 'POST', [
                            'redirect_uri'  => $this->redirect_uri,
                            'client_id'     => $this->client_id,
                            'client_secret' => $this->client_secret,
                            'code'          => $params['code'],
                        ], ['method' => 'POST', 'content_type' => 'application/x-www-form-urlencoded', 'headers' => []]);

                        $response_data = $this->execute_curl($curl);
                        if ($response_data['error'])
                            throw new \Exception("Ошибка при запросе к API: '{$response_data['error']}' в ".get_called_class());

                        $result = @json_decode($response_data['response'], true);
                        if (json_last_error() !== JSON_ERROR_NONE)
                            throw new \Exception("Ошибка при декодировании ответа: '".json_last_error_msg()."'в ".get_called_class());
                        break;
                    case 'token':
                    default:
                        $result = $params;
                        break;
                }

                $errors = Rule::object([
                    'access_token' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'Некорректный параметр access_token')->skip(true),
                    'user_id'      => Rule::create(fn(&$v) => is_int($v) && $v > 0)->handleError(fn($v) => 'Некорректный параметр user_id')->skip(true),
                    'expires_in'   => Rule::create(fn(&$v) => is_int($v))->handleError(fn($v) => 'Некорректный параметр expires_in')->skip(true),
                ])->apply($result);
                if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);

                $this->save_token($result['access_token'], [
                    'expires_in' => $result['expires_in'],
                    'user_id'    => $result['user_id'],
                    'client_id'  => $this->client_id,
                ]);
            }

            $this->access_token = $result['access_token'];
            $this->user_id      = $result['user_id'];
        });

        $this->registerMethodsMap([
            'users.getFollowers' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'user_id' => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))->handleError(fn($v) => 'Некорректный user_id'),
                    'count'   => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))->handleError(fn($v) => 'count должен быть > 0'),
                    'offset'  => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v >= 0))->handleError(fn($v) => 'offset должен быть >= 0'),
                    'fields'  => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'fields должен быть массивом'),
                ],
                'on_prepare' => function(&$params) {
                    $params['user_id'] = $params['user_id'] ?? $this->user_id;
                    $params['count']   ??= 100;
                    $params['offset']  ??= 0;
                    $params['fields']  ??= [];
                },
            ],
            'users.get' => [
                'meta'   => ['scope' => []],
                'params' => [
                    'user_ids' => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'user_ids должен быть массивом'),
                    'fields'   => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'fields должен быть массивом'),
                ],
                'on_prepare' => function(&$params) {
                    $params['user_ids'] ??= [];
                    $params['fields']   ??= [];
                },
            ],
            'account.ban' => [
                'meta'   => ['scope' => ['account']],
                'params' => [
                    'owner_id' => Rule::create(fn(&$v) => is_scalar($v) && $v !== '')->handleError(fn($v) => 'Некорректный owner_id')->skip(true),
                ],
            ],
            'friends.delete' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'user_id' => Rule::create(fn(&$v) => is_scalar($v) && $v !== '')->handleError(fn($v) => 'Некорректный user_id')->skip(true),
                ],
            ],
            'friends.getSuggestions' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'filter' => Rule::create(fn(&$v) => $v === null || ($v === 'mutual'))->handleError(fn($v) => 'Некорректный filter'),
                    'count'  => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))->handleError(fn($v) => 'count должен быть > 0'),
                    'offset' => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v >= 0))->handleError(fn($v) => 'offset должен быть >= 0'),
                    'fields' => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'fields должен быть массивом'),
                ],
                'on_prepare' => function(&$params) {
                    $params['count']  ??= 100;
                    $params['offset'] ??= 0;
                    $params['fields'] ??= [];
                },
            ],
            'friends.add' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'user_id' => Rule::create(fn(&$v) => is_scalar($v) && $v !== '')->handleError(fn($v) => 'Некорректный user_id')->skip(true),
                    'text'    => 'nullable|string',
                    'follow'  => 'nullable|bool',
                ],
                'on_prepare' => function(&$params) {
                    $params['follow'] ??= false;
                },
            ],
        ]);

    }

}
