<?php

namespace ST_system\API\Drivers\CRM;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class RentalCRM extends IntegrationDriver {
    private array $SETTINGS = [];

    protected function __init(): void {

        $this->on('__construct', function(array $PARAMS = []) {
            $errors = Rule::object([
                'subdomain' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РЗР°РґР°РЅ РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ СЃСƒР±РґРѕРјРµРЅ')->skip(true),
                'api_key'   => 'nullable|string',
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $PARAMS['endpoint'] = "https://{$PARAMS['subdomain']}.retailcrm.ru/api/v5";
            if (!filter_var($PARAMS['endpoint'], FILTER_VALIDATE_URL))
                throw new \Exception("РЗР°РґР°РЅР° РЅРµРєРѕСЂСЂРµРєС‚РЅР°С С‚РѕСȇРєР° API");
            unset($PARAMS['subdomain']);
            $this->SETTINGS = $PARAMS;
        });

        $this->on('build_url', function(string &$request_url) {
            $request_url = ($this->SETTINGS['endpoint'] ?? '') . $request_url;
        });

        $this->on('before_curl_init', function(string $request_url, string $request_method, array &$params) {
            $params['apiKey'] = $this->SETTINGS['api_key'];
        });

        $this->register_methods_map([
            'orders' => [
                'method' => 'GET',
                'params' => [
                    'filter' => Rule::create(function(&$v) {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'ids' => Rule::create(fn(&$v) => $v === null || (is_array($v) && count($v) === count(array_filter($v, 'is_int'))))->handleError(fn($v) => 'ids РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј С†РµР»С‹С СȇРёСЃРµР»'),
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'filter РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                ],
            ],
            'orders/create' => [
                'method' => 'POST',
                'params' => [
                    'site'  => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ site')->skip(true),
                    'order' => Rule::create(function(&$v) {
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'customer'        => Rule::create(fn(&$v) => $v === null || (is_array($v) && !empty(array_intersect(['externalId','id','browserId'], array_keys($v)))))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ customer'),
                            'customerComment' => 'nullable|string',
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'РќРµ РїРµСЂРµРґР°РЅ order')->skip(true),
                ],
                'on_prepare' => function(&$params) {
                    $params['order'] = json_encode($params['order']);
                },
            ],
            'customers' => [
                'method' => 'GET',
                'params' => [
                    'filter' => Rule::create(function(&$v) {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $errors = Rule::object(['name' => 'nullable|string'])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'filter РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                ],
            ],
            'customers/create' => [
                'method' => 'POST',
                'params' => [
                    'site'     => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ site')->skip(true),
                    'customer' => Rule::create(function(&$v) {
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'firstName'  => 'nullable|string',
                            'lastName'   => 'nullable|string',
                            'patronymic' => 'nullable|string',
                            'email'      => Rule::create(fn(&$v) => $v === null || filter_var($v, FILTER_VALIDATE_EMAIL) !== false)->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ email'),
                            'phones'     => Rule::create(function(&$v) {
                                if ($v === null) return true;
                                if (!is_array($v)) return false;
                                foreach ($v as &$phone) {
                                    $errors = Rule::object([
                                        'number' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РќРµ РїРµСЂРµРґР°РЅ РЅРѕРјРµСЂ С‚РµР»РµС„РѕРЅР°')->skip(true),
                                    ])->apply($phone);
                                    if (!empty($errors)) throw new \Exception($errors[0]);
                                }
                                unset($phone);
                                return true;
                            })->handleError(fn($v) => 'phones РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј'),
                            'tags'       => Rule::create(fn(&$v) => $v === null || (is_array($v) && count($v) === count(array_filter($v, 'is_string'))))->handleError(fn($v) => 'tags РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РјР°СЃСЃРёРІРѕРј СЃС‚СЂРѕРє'),
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'РќРµ РїРµСЂРµРґР°РЅ customer')->skip(true),
                ],
                'on_prepare' => function(&$params) {
                    $params['customer'] = json_encode($params['customer']);
                },
            ],
            'files/upload' => [
                'method' => 'POST',
                'params' => [
                    'file' => Rule::create(fn(&$v) => is_string($v) && file_exists($v) && is_readable($v))
                        ->handleError(fn($v) => 'РќРµ РїРµСЂРµРґР°РЅ file РёР»Рё С„Р°Р№Р» РЅРµРґРѕСЃС‚СѓРїРµРЅ')
                        ->after(fn(&$v) => $v = new \CURLFile($v))
                        ->skip(true),
                ],
            ],
            'tasks' => [
                'params' => [],
            ],
            'tasks/create' => [
                'method' => 'POST',
                'params' => [
                    'site' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ site')->skip(true),
                    'task' => Rule::create(function(&$v) {
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'customer'    => Rule::create(fn(&$v) => $v === null || (is_array($v) && !empty(array_intersect(['externalId','id'], array_keys($v)))))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ customer'),
                            'order'       => Rule::create(fn(&$v) => $v === null || (is_array($v) && !empty(array_intersect(['externalId','id','number'], array_keys($v)))))->handleError(fn($v) => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ order'),
                            'performerId' => Rule::create(fn(&$v) => is_int($v))->handleError(fn($v) => 'РќРµ РїРµСЂРµРґР°РЅ performerId')->skip(true),
                            'text'        => 'nullable|string',
                            'commentary'  => 'nullable|string',
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'РќРµ РїРµСЂРµРґР°РЅ task')->skip(true),
                ],
                'on_prepare' => function(&$params) {
                    $params['task'] = json_encode($params['task']);
                },
            ],
        ]);

    }
}
