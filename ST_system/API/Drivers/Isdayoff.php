<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class Isdayoff extends IntegrationDriver {

    protected const DEFAULT_ENDPOINT = 'https://isdayoff.ru/api/';

    private array $SETTINGS = [];

    protected function __init(): void {

        // Date rule: converts to 'Ymd' format (isdayoff.ru specific)
        $r_date = Rule::create(fn(&$v) => $v === null || $v instanceof \DateTimeInterface || is_string($v))
            ->after(function(&$v) {
                if ($v === null) return;
                if ($v instanceof \DateTimeInterface) { $v = $v->format('Ymd'); return; }
                try {
                    $date = new \DateTime($v);
                } catch (\Throwable $th) {
                    throw new \Exception("РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° $v");
                }
                if (!$date || !empty(\DateTime::getLastErrors()['error_count']))
                    throw new \Exception("РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° $v");
                $v = $date->format('Ymd');
            });

        $this->on('__construct', function(array $PARAMS = []) {
            $errors = Rule::object([
                'pre'       => 'nullable|bool',
                'covid'     => 'nullable|bool',
                'sd'        => 'nullable|bool',
                'delimeter' => Rule::create(fn(&$v) => $v === null || (is_string($v) && $v !== '' && strlen($v) <= 7))
                    ->handleError(fn($v) => 'delimeter РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ СЃС‚СЂРѕРєРѕР№ РґР»РёРЅРѕР№ РґРѕ 7 СЃРёРјРІРѕР»РѕРІ'),
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $PARAMS['pre']       = (bool)($PARAMS['pre']       ?? false);
            $PARAMS['covid']     = (bool)($PARAMS['covid']     ?? false);
            $PARAMS['sd']        = (bool)($PARAMS['sd']        ?? false);
            $this->SETTINGS = $PARAMS;
        });

        $this->register_methods_map([
            'getdata' => [
                'params' => [
                    'year'      => Rule::create(fn(&$v) => $v === null || is_int($v))->after(fn(&$v) => $v = $v !== null ? sprintf('%04d', $v) : null),
                    'month'     => Rule::create(fn(&$v) => $v === null || is_int($v))->after(fn(&$v) => $v = $v !== null ? sprintf('%02d', $v) : null),
                    'day'       => Rule::create(fn(&$v) => $v === null || is_int($v))->after(fn(&$v) => $v = $v !== null ? sprintf('%02d', $v) : null),
                    'date1'     => $r_date,
                    'date2'     => $r_date,
                    'pre'       => 'nullable|bool',
                    'covid'     => 'nullable|bool',
                    'sd'        => 'nullable|bool',
                    'delimeter' => Rule::create(fn(&$v) => $v === null || (is_string($v) && $v !== '' && strlen($v) <= 7))
                        ->handleError(fn($v) => 'delimeter РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ СЃС‚СЂРѕРєРѕР№ РґР»РёРЅРѕР№ РґРѕ 7 СЃРёРјРІРѕР»РѕРІ'),
                ],
                'on_prepare' => function(&$params) {
                    $params['pre']       ??= $this->SETTINGS['pre'];
                    $params['covid']     ??= $this->SETTINGS['covid'];
                    $params['sd']        ??= $this->SETTINGS['sd'];
                    $params['delimeter'] = $params['delimeter'] ?? $this->SETTINGS['delimeter'] ?? null;
                },
            ],
        ]);

        // Split the raw text response into an array of day codes
        $this->on('prepare_response', function($method, $params, &$raw_data) {
            $delimeter = $params['delimeter'] ?? $this->SETTINGS['delimeter'] ?? null;
            $raw_data['response'] = !$delimeter
                ? str_split($raw_data['response'])
                : explode($delimeter, $raw_data['response']);
        });

        // Prevent call() from json_decoding the already-processed array
        $this->on('decode_response', function($method, $params, &$raw_data) {
            // no-op: response already split in prepare_response
        });
    }

}
