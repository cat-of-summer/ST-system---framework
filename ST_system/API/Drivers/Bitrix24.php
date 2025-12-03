<?php

namespace ST_system\API\Drivers;

use \ST_system\API\IntegrationDriver;
use \ST_system\API\Traits\Overridable;

final class Bitrix24 extends IntegrationDriver {
    use Overridable;

    private $SETTINGS = [];

    protected function __init() {

        $this->on('__construct', function(array $PARAMS = []) {
            $this->SETTINGS = $this->prepare_params([
                'point' => [new \Exception("Задана некорректная точка API"), fn($v) => filter_var($v, FILTER_VALIDATE_URL), fn($v) => rtrim($v, '/')],
            ], $PARAMS);
        });

        $this->on('build_url', function(string &$request_url) {
            $request_url = $this->SETTINGS['point'].$request_url;
        });

        $this->register_rules_map([
            'date' => [null, 'after' => function($v) {
                if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');

                try {
                    $date = new \DateTime($v);
                } catch (\Throwable $th) {
                    throw new \Exception("Некорректная дата $v");
                }

                if (!$date || !empty(\DateTime::getLastErrors()['error_count']))
                    throw new \Exception("Некорректная дата $v");
                
                return $date->format('Y-m-d');
            }],
            'string' => [null, fn($v) => is_string($v)],
            'bool' => [null, fn($v) => is_bool($v) || in_array($v, ['Y', 'N']), fn($v) => is_bool($v) ? ($v ? 'Y' : 'N') : $v],
            'crm_multifield' => [null, fn($v) => is_array($v), fn($v) => array_map(fn($i) => $this->prepare_params([
                'ID' => [null, fn($v) => is_int($v)],
                'TYPE_ID' => [null, fn($v) => is_string($v) && in_array($v, ['PHONE', 'EMAIL', 'WEB', 'IM', 'LINK'])],
                'VALUE' => [new \Exception("Не передан VALUE"), fn($v) => is_string($v)],
                'VALUE_TYPE' => [new \Exception("Не передан VALUE_TYPE"), fn($v) => is_string($v) && in_array($v, ['WORK', 'MOBILE', 'FAX', 'HOME', 'PAGER', 'MAILING', 'OTHER', 'FACEBOOK', 'VK', 'LIVEJOURNAL', 'TWITTER', 'TELEGRAM', 'SKYPE', 'VIBER', 'INSTAGRAM', 'BITRIX24', 'OPENLINE', 'IMOL', 'ICQ', 'MSN', 'JABBER'])],
            ], $i), $v)]
        ]);

        $this->register_methods_map([
            'calendar.event.get' => [
                'params' => [
                    'type' => [new \Exception("Не передан тип календаря"), fn($v) => in_array($v, ['user', 'group', 'company_calendar'])],
                    'ownerId' => [new \Exception("Не передан идентификатор владельца календаря"), fn($v) => is_integer($v)],
                    'section' => [null, fn($v) => (is_array($v) && count($v) == count(array_filter($v, 'is_string'))) || is_string($v), 'before' => fn($v) => is_string($v) ? [$v] : $v],
                    'from' => 'date',
                    'to' => 'date',
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['section'])) {
                        $params['section[]'] = implode(',', $params['section']);
                        unset($params['section']);
                    }
                }
            ],
            'crm.contact.list' => [
                'method' => 'POST',
                'params' => [
                    'SELECT' => [null, fn($v) => is_array($v)],
                    'FILTER' => [null, fn($v) => is_array($v)],
                    'ORDER' => [null, fn($v) => is_array($v)],
                    'START' => [null, fn($v) => is_int($v)],
                    'PAGE' => [null, fn($v, $k, $p) => is_null($p['START']) && is_int($v)]
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['PAGE'])) {
                        $params['START'] = $params['PAGE'] * 50;
                        unset($params['PAGE']);
                    }

                    if (isset($params['FILTER']['PHONE']))
                        $params['FILTER']['PHONE'] = preg_replace('/\D/', '', $params['FILTER']['PHONE']);
                }
            ],
            'crm.contact.add' => [
                'method' => 'POST',
                'params' => [
                    'FIELDS' => [null, fn($v) => is_array($v), fn($v) => $this->prepare_params([
                        'NAME' => 'string',
                        'PHONE' => 'crm_multifield',
                        'EMAIL' => 'crm_multifield',
                    ], $v)],
                    'PARAMS' => [null, fn($v) => is_array($v), fn($v) => $this->prepare_params([
                        'REGISTER_SONET_EVENT' => 'bool',
                    ], $v)]
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['FIELDS']['PHONE']['VALUE']))
                        $params['FIELDS']['PHONE']['VALUE'] = preg_replace('/\D/', '', $params['FIELDS']['PHONE']['VALUE']);
                }
            ],
            'crm.deal.add' => [
                'method' => 'POST',
                'params' => [
                    'FIELDS' => [null, fn($v) => is_array($v), fn($v) => $this->prepare_params([
                        'TITLE' => 'string',
                        'TYPE_ID' => [null],
                        'CATEGORY_ID' => [null, fn($v) => is_int($v) && $v >= 0],
                        'STAGE_ID' => [null],
                        'IS_RECURRING' => 'bool',
                        'IS_RETURN_CUSTOMER' => 'bool',
                        'IS_REPEATED_APPROACH' => 'bool',
                        'PROBABILITY' => [null, fn($v) => is_int($v) && $v >= 0 && $v <= 100],
                        'CURRENCY_ID' => [null],
                        'OPPORTUNITY' => [null, fn($v) => is_double($v)],
                        'IS_MANUAL_OPPORTUNITY' => 'bool',
                        'TAX_VALUE' => [null, fn($v) => is_double($v)],
                        'COMPANY_ID' => [null],
                        'CONTACT_ID' => [null],
                        'CONTACT_IDS' => [null, fn($v) => is_array($v)],
                        'BEGINDATE' => 'date',
                        'CLOSEDATE' => 'date',
                        'OPENED' => 'bool',
                        'CLOSED' => 'bool',
                        'COMMENTS' => 'string',
                        'ASSIGNED_BY_ID' => [null],
                        'SOURCE_ID' => [null],
                        'SOURCE_DESCRIPTION' => 'string',
                        'ADDITIONAL_INFO' => 'string',
                        'LOCATION_ID' => [null],
                        'ORIGINATOR_ID' => 'string',
                        'ORIGIN_ID' => 'string',
                        'UTM_SOURCE' => 'string',
                        'UTM_MEDIUM' => [null, fn($v) => is_string($v) && in_array($v, ['CPC', 'CPM'])],
                        'UTM_CAMPAIGN' => 'string',
                        'UTM_CONTENT' => 'string',
                        'UTM_TERM' => 'string',
                        'TRACE' => 'string',
                    ], $v)],
                    'PARAMS' => [null, fn($v) => is_array($v), fn($v) => $this->prepare_params([
                        'REGISTER_SONET_EVENT' => 'bool',
                    ], $v)]
                ]
            ]
        ]);

    }
}