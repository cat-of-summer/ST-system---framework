<?php

namespace ST_system\API\Drivers\CRM;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class RentalCRM extends IntegrationDriver {
    private array $SETTINGS = [];

    protected function __init(): void {

        $this->on('__construct', function(array $PARAMS = []) {
            $errors = Rule::object([
                'subdomain' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => '��адан некорректный с�?бдомен')->skip(true),
                'api_key'   => 'nullable|string',
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $PARAMS['endpoint'] = "https://{$PARAMS['subdomain']}.retailcrm.ru/api/v5";
            if (!filter_var($PARAMS['endpoint'], FILTER_VALIDATE_URL))
                throw new \Exception("��адана некорректна�? то�?ка API");
            unset($PARAMS['subdomain']);
            $this->SETTINGS = $PARAMS;
        });

        $this->on('build_url', function(string &$request_url) {
            $request_url = ($this->SETTINGS['endpoint'] ?? '') . $request_url;
        });

        $this->on('before_curl_init', function(string $request_url, string $request_method, array &$params) {
            $params['apiKey'] = $this->SETTINGS['api_key'];
        });

        $this->registerMethodsMap([
            'orders' => [
                'method' => 'GET',
                'params' => [
                    'filter' => Rule::create(function(&$v) {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'ids' => Rule::create(fn(&$v) => $v === null || (is_array($v) && count($v) === count(array_filter($v, 'is_int'))))->handleError(fn($v) => 'ids должен быть массивом целы�? �?исел'),
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'filter должен быть массивом'),
                ],
            ],
            'orders/create' => [
                'method' => 'POST',
                'params' => [
                    'site'  => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'Некорректный site')->skip(true),
                    'order' => Rule::create(function(&$v) {
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'customer'        => Rule::create(fn(&$v) => $v === null || (is_array($v) && !empty(array_intersect(['externalId','id','browserId'], array_keys($v)))))->handleError(fn($v) => 'Некорректный customer'),
                            'customerComment' => 'nullable|string',
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Не передан order')->skip(true),
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
                    })->handleError(fn($v) => 'filter должен быть массивом'),
                ],
            ],
            'customers/create' => [
                'method' => 'POST',
                'params' => [
                    'site'     => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'Некорректный site')->skip(true),
                    'customer' => Rule::create(function(&$v) {
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'firstName'  => 'nullable|string',
                            'lastName'   => 'nullable|string',
                            'patronymic' => 'nullable|string',
                            'email'      => Rule::create(fn(&$v) => $v === null || filter_var($v, FILTER_VALIDATE_EMAIL) !== false)->handleError(fn($v) => 'Некорректный email'),
                            'phones'     => Rule::create(function(&$v) {
                                if ($v === null) return true;
                                if (!is_array($v)) return false;
                                foreach ($v as &$phone) {
                                    $errors = Rule::object([
                                        'number' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'Не передан номер телефона')->skip(true),
                                    ])->apply($phone);
                                    if (!empty($errors)) throw new \Exception($errors[0]);
                                }
                                unset($phone);
                                return true;
                            })->handleError(fn($v) => 'phones должен быть массивом'),
                            'tags'       => Rule::create(fn(&$v) => $v === null || (is_array($v) && count($v) === count(array_filter($v, 'is_string'))))->handleError(fn($v) => 'tags должен быть массивом строк'),
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Не передан customer')->skip(true),
                ],
                'on_prepare' => function(&$params) {
                    $params['customer'] = json_encode($params['customer']);
                },
            ],
            'files/upload' => [
                'method' => 'POST',
                'params' => [
                    'file' => Rule::create(fn(&$v) => is_string($v) && file_exists($v) && is_readable($v))
                        ->handleError(fn($v) => 'Не передан file или файл недоступен')
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
                    'site' => Rule::create(fn(&$v) => is_string($v) && $v !== '')->handleError(fn($v) => 'Некорректный site')->skip(true),
                    'task' => Rule::create(function(&$v) {
                        if (!is_array($v)) return false;
                        $errors = Rule::object([
                            'customer'    => Rule::create(fn(&$v) => $v === null || (is_array($v) && !empty(array_intersect(['externalId','id'], array_keys($v)))))->handleError(fn($v) => 'Некорректный customer'),
                            'order'       => Rule::create(fn(&$v) => $v === null || (is_array($v) && !empty(array_intersect(['externalId','id','number'], array_keys($v)))))->handleError(fn($v) => 'Некорректный order'),
                            'performerId' => Rule::create(fn(&$v) => is_int($v))->handleError(fn($v) => 'Не передан performerId')->skip(true),
                            'text'        => 'nullable|string',
                            'commentary'  => 'nullable|string',
                        ])->apply($v);
                        if (!empty($errors)) throw new \Exception($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Не передан task')->skip(true),
                ],
                'on_prepare' => function(&$params) {
                    $params['task'] = json_encode($params['task']);
                },
            ],
        ]);

    }
}
