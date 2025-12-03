<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;

final class Isdayoff extends IntegrationDriver {

    protected const DEFAULT_POINT = 'https://isdayoff.ru/api/';

    private $SETTINGS = [];
    
    protected function __init() {
        $this->on('__construct', function(array $PARAMS = []) {

            $this->SETTINGS = $this->prepare_params([
                'pre' => [false, fn($v) => is_bool($v)],
                'covid' => [false, fn($v) => is_bool($v)],
                'sd' => [false, fn($v) => is_bool($v)],
                'delimeter' => [null, fn($v) => is_string($v) && $v != '' && strlen($v) <= 7]
            ], $PARAMS);

        });

        $this->register_rules_map([
            'date' => [null, 'after' => function($v) {
                if ($v instanceof \DateTimeInterface) return $v->format('Ymd');

                try {
                    $date = new \DateTime($v);
                } catch (\Throwable $th) {
                    throw new \Exception("Некорректная дата $v");
                }

                if (!$date || !empty(\DateTime::getLastErrors()['error_count']))
                    throw new \Exception("Некорректная дата $v");
                
                return $date->format('Ymd');
            }],
        ]);

        $this->register_methods_map([
            'getdata' => [
                'params' => [
                    'year' => [null, fn($v) => is_int($v), fn($v) => sprintf("%04d", $v)],
                    'month' => [null, fn($v) => is_int($v), fn($v) => sprintf("%02d", $v)],
                    'day' => [null, fn($v) => is_int($v), fn($v) => sprintf("%02d", $v)],
                    'date1' => 'date',
                    'date2' => 'date',
                    'pre' => [$this->SETTINGS['pre'], fn($v) => is_bool($v)],
                    'covid' => [$this->SETTINGS['covid'], fn($v) => is_bool($v)],
                    'sd' => [$this->SETTINGS['sd'], fn($v) => is_bool($v)],
                    'delimeter' => [$this->SETTINGS['delimeter'], fn($v) => is_string($v) && $v != '' && strlen($v) <= 7]
                ]
            ],
        ]);
        
        $this->on('prepare_response', function ($method, $params, &$response_data) {
            $delimeter = $params['delimeter'] ?? $this->SETTINGS['delimeter'] ?? null;

            $response_data['response'] = !$delimeter
                ? str_split($response_data['response'])
                : explode($delimeter, $response_data['response']);
        });
    }

}