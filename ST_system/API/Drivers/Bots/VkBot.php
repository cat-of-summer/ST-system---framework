<?php

namespace ST_system\API\Drivers\Bots;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class VkBot extends IntegrationDriver {

    protected const DEFAULT_ENDPOINT = 'https://api.vk.com/method';
    protected const OAUTH_POINT      = 'https://oauth.vk.com';
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
                'client_id'     => Rule::create(fn(&$v) => is_int($v))->handleError(fn($v) => 'РџРµСЂРµРґР°РЅ РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ client_id')->skip(true),
                'client_secret' => Rule::create(fn(&$v) => is_string($v))->handleError(fn($v) => 'РџРµСЂРµРґР°РЅ РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ client_secret')->skip(true),
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
                throw new \Exception("Р”Р»СЏ РґРѕСЃС‚СѓРїР° РјРµС‚РѕРґСѓ '{$method}' РЅРµРѕР±С…РѕРґРёРј Р°РІС‚РѕСЂРёР·Р°С†РёРѕРЅРЅС‹Р№ С‚РѕРєРµРЅ!");

            $params['access_token'] = $this->access_token;
        });

        $this->on('call', function($method) {
            $meta       = $this->methods_map[$method]['meta'] ?? [];
            $array_diff = array_diff($meta['scope'] ?? [], $this->scope ?? []);

            if (!empty($array_diff))
                throw new \Exception("РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РїСЂР°РІ РґР»СЏ РјРµС‚РѕРґР° {$method}. РќРµРѕР±С…РѕРґРёРј РґРѕСЃС‚СѓРї Рє ".implode(', ', $array_diff).".");
        });

        $this->register_method('authorize', function(array $params) {

            [$request_url] = $this->build_url('authorize', static::OAUTH_POINT);

            $errors = Rule::object([
                'redirect_uri'  => Rule::create(fn(&$v) => $v === null || (is_string($v) && $v !== ''))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ redirect_uri'),
                'scope'         => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'scope РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                'display'       => Rule::create(fn(&$v) => $v === null || in_array($v, ['page', 'popup', 'mobile'], true))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ display'),
                'response_type' => Rule::create(fn(&$v) => $v === null || in_array($v, ['code', 'token'], true))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ response_type'),
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

        $this->register_method('access_token', function(array $params) {

            $access_token = $this->load_token([
                'user_id'   => $params['user_id'] ?? null,
                'client_id' => $this->client_id,
            ]);

            if ($access_token) {
                $result = ['access_token' => $access_token, 'user_id' => $params['user_id'] ?? null];
            } else {
                switch ($this->auth_method) {
                    case 'code':
                        [$request_url] = $this->build_url('access_token', static::OAUTH_POINT);

                        $errors = Rule::object([
                            'code' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ РєРѕРґ Р°РІС‚РѕСЂРёР·Р°С†РёРё!')->skip(true),
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
                            throw new \Exception("РћС€РёР±РєР° РїСЂРё Р·Р°РїСЂРѕСЃРµ Рє API: '{$response_data['error']}' РІ ".get_called_class());

                        $result = @json_decode($response_data['response'], true);
                        if (json_last_error() !== JSON_ERROR_NONE)
                            throw new \Exception("РћС€РёР±РєР° РїСЂРё РґРµРєРѕРґРёСЂРѕРІР°РЅРёРё РѕС‚РІРµС‚Р°: '".json_last_error_msg()."' РІ ".get_called_class());
                        break;
                    case 'token':
                    default:
                        $result = $params;
                        break;
                }

                $errors = Rule::object([
                    'access_token' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ РїР°СЂР°РјРµС‚СЂ access_token')->skip(true),
                    'user_id'      => Rule::create(fn(&$v) => is_int($v) && $v > 0)->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ РїР°СЂР°РјРµС‚СЂ user_id')->skip(true),
                    'expires_in'   => Rule::create(fn(&$v) => is_int($v))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ РїР°СЂР°РјРµС‚СЂ expires_in')->skip(true),
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

        $this->register_methods_map([
            'users.getFollowers' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'user_id' => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ user_id'),
                    'count'   => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))->handleError(fn($v) => 'count РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ > 0'),
                    'offset'  => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v >= 0))->handleError(fn($v) => 'offset РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ >= 0'),
                    'fields'  => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'fields РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
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
                    'user_ids' => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'user_ids РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                    'fields'   => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'fields РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                ],
                'on_prepare' => function(&$params) {
                    $params['user_ids'] ??= [];
                    $params['fields']   ??= [];
                },
            ],
            'account.ban' => [
                'meta'   => ['scope' => ['account']],
                'params' => [
                    'owner_id' => Rule::create(fn(&$v) => is_scalar($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ owner_id')->skip(true),
                ],
            ],
            'friends.delete' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'user_id' => Rule::create(fn(&$v) => is_scalar($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ user_id')->skip(true),
                ],
            ],
            'friends.getSuggestions' => [
                'meta'   => ['scope' => ['friends']],
                'params' => [
                    'filter' => Rule::create(fn(&$v) => $v === null || ($v === 'mutual'))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ filter'),
                    'count'  => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v > 0))->handleError(fn($v) => 'count РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ > 0'),
                    'offset' => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v >= 0))->handleError(fn($v) => 'offset РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ >= 0'),
                    'fields' => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'fields РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
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
                    'user_id' => Rule::create(fn(&$v) => is_scalar($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ user_id')->skip(true),
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
