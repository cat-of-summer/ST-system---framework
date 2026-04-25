<?php

namespace ST_system\API\Drivers\CRM;

use \ST_system\API\IntegrationDriver;
use \ST_system\Rule;

final class Bitrix24 extends IntegrationDriver {

    private array $SETTINGS    = [];
    private array $extraSchemas = []; // [method => ['FIELDS' => Rule[], 'PARAMS' => Rule[]]]

    // ── Field / Param extension ───────────────────────────────────────────────

    /**
     * Добавить дополнительные поля в схему FIELDS указанного метода.
     * Вызывается до первого использования метода.
     *
     * Пример:
     *   $b24->extendFields('crm.contact.add', ['UF_CRM_MY_FIELD' => 'nullable|string']);
     */
    public function extendFields(string $method, array $fields): self {
        $this->extraSchemas[$method]['FIELDS'] = array_merge(
            $this->extraSchemas[$method]['FIELDS'] ?? [],
            $fields
        );
        return $this;
    }

    /**
     * Добавить дополнительные поля в схему PARAMS указанного метода.
     */
    public function extendParams(string $method, array $params): self {
        $this->extraSchemas[$method]['PARAMS'] = array_merge(
            $this->extraSchemas[$method]['PARAMS'] ?? [],
            $params
        );
        return $this;
    }

    protected function __init(): void {

        // ── Rule aliases (guarded) ────────────────────────────────────────────

        // b24_date: string / DateTimeInterface → 'Y-m-d'
        if (!Rule::get('b24_date'))
            Rule::create(fn(&$v) => $v === null || $v instanceof \DateTimeInterface || is_string($v))
                ->after(function(&$v) {
                    if ($v === null) return;
                    if ($v instanceof \DateTimeInterface) { $v = $v->format('Y-m-d'); return; }
                    try {
                        $date = new \DateTime($v);
                    } catch (\Throwable $th) {
                        throw new \Exception("Некорректная дата {$v}");
                    }
                    if (!$date || !empty(\DateTime::getLastErrors()['error_count']))
                        throw new \Exception("Некорректная дата {$v}");
                    $v = $date->format('Y-m-d');
                })
                ->alias('b24_date');

        // b24_bool: bool / Y / N → Y / N
        if (!Rule::get('b24_bool'))
            Rule::create(fn(&$v) => $v === null || is_bool($v) || in_array($v, ['Y', 'N'], true))
                ->handleError(fn($v) => 'Должно быть boolean или Y/N')
                ->after(fn(&$v) => $v = ($v === null) ? null : (is_bool($v) ? ($v ? 'Y' : 'N') : $v))
                ->alias('b24_bool');

        // b24_multifield: CRM-массив элементов {ID, TYPE_ID, VALUE, VALUE_TYPE}
        if (!Rule::get('b24_multifield')) {
            $crm_item_rule = Rule::object([
                'ID'         => Rule::create(fn(&$v) => $v === null || is_int($v)),
                'TYPE_ID'    => Rule::create(fn(&$v) => $v === null || in_array($v, ['PHONE','EMAIL','WEB','IM','LINK'], true))
                    ->handleError(fn($v) => 'Некорректный TYPE_ID'),
                'VALUE'      => Rule::create(fn(&$v) => is_string($v) && $v !== '')
                    ->handleError(fn($v) => 'Не передан VALUE')->skip(true),
                'VALUE_TYPE' => Rule::create(fn(&$v) => is_string($v) && in_array($v, [
                        'WORK','MOBILE','FAX','HOME','PAGER','MAILING','OTHER','FACEBOOK','VK',
                        'LIVEJOURNAL','TWITTER','TELEGRAM','SKYPE','VIBER','INSTAGRAM','BITRIX24',
                        'OPENLINE','IMOL','ICQ','MSN','JABBER',
                    ], true))->handleError(fn($v) => 'Некорректный VALUE_TYPE')->skip(true),
            ]);
            Rule::create(function(&$v) use ($crm_item_rule): bool {
                if ($v === null) return true;
                if (!is_array($v)) return false;
                $errors = Rule::forEach($crm_item_rule)->apply($v);
                if (!empty($errors)) throw new \Exception(reset($errors));
                return true;
            })
            ->handleError(fn($v) => 'Некорректный b24_multifield')
            ->alias('b24_multifield');
        }

        // ── Constructor / events ──────────────────────────────────────────────

        $this->on('__construct', function(array $PARAMS = []) {
            $errors = Rule::object([
                'endpoint' => Rule::create(fn(&$v) => filter_var($v, FILTER_VALIDATE_URL) !== false)
                    ->handleError(fn($v) => 'Задана некорректная точка API')
                    ->after(fn(&$v) => $v = rtrim($v, '/'))
                    ->skip(true),
            ])->apply($PARAMS);
            if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
            $this->SETTINGS = $PARAMS;
        });

        $this->on('build_url', function(string &$request_url) {
            $request_url = ($this->SETTINGS['endpoint'] ?? '') . $request_url;
        });

        // ── Methods ───────────────────────────────────────────────────────────

        $this->registerMethodsMap([
            'calendar.event.get' => [
                'params' => [
                    'type'    => Rule::create(fn(&$v) => in_array($v, ['user', 'group', 'company_calendar'], true))
                        ->handleError(fn($v) => 'Не передан тип календаря')->skip(true),
                    'ownerId' => Rule::create(fn(&$v) => is_int($v))
                        ->handleError(fn($v) => 'Не передан идентификатор владельца календаря')->skip(true),
                    'section' => Rule::create(fn(&$v) => $v === null || (is_array($v) && count($v) === count(array_filter($v, 'is_string'))))
                        ->before(fn(&$v) => $v = is_string($v) ? [$v] : $v)
                        ->handleError(fn($v) => 'section должен быть строкой или массивом строк'),
                    'from' => 'nullable|b24_date',
                    'to'   => 'nullable|b24_date',
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['section'])) {
                        $params['section[]'] = implode(',', $params['section']);
                        unset($params['section']);
                    }
                },
            ],
            'crm.contact.list' => [
                'method' => 'POST',
                'params' => [
                    'SELECT' => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'Некорректный SELECT'),
                    'FILTER' => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'Некорректный FILTER'),
                    'ORDER'  => Rule::create(fn(&$v) => $v === null || is_array($v))->handleError(fn($v) => 'Некорректный ORDER'),
                    'START'  => Rule::create(fn(&$v) => $v === null || is_int($v))->handleError(fn($v) => 'START должен быть целым числом'),
                    'PAGE'   => Rule::create(fn(&$v) => $v === null || is_int($v))->handleError(fn($v) => 'PAGE должен быть целым числом'),
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['PAGE'])) {
                        $params['START'] = ($params['PAGE'] ?? 0) * 50;
                        unset($params['PAGE']);
                    }
                    if (isset($params['FILTER']['PHONE']))
                        $params['FILTER']['PHONE'] = preg_replace('/\D/', '', $params['FILTER']['PHONE']);
                },
            ],
            'crm.contact.add' => [
                'method' => 'POST',
                'params' => [
                    'FIELDS' => Rule::create(function(&$v): bool {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $schema = array_merge([
                            'NAME'  => 'nullable|string',
                            'PHONE' => 'nullable|b24_multifield',
                            'EMAIL' => 'nullable|b24_multifield',
                        ], $this->extraSchemas['crm.contact.add']['FIELDS'] ?? []);
                        $errors = Rule::object($schema)->apply($v);
                        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Некорректный FIELDS'),
                    'PARAMS' => Rule::create(function(&$v): bool {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $schema = array_merge([
                            'REGISTER_SONET_EVENT' => 'nullable|b24_bool',
                        ], $this->extraSchemas['crm.contact.add']['PARAMS'] ?? []);
                        $errors = Rule::object($schema)->apply($v);
                        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Некорректный PARAMS'),
                ],
                'on_prepare' => function(&$params) {
                    if (isset($params['FIELDS']['PHONE']['VALUE']))
                        $params['FIELDS']['PHONE']['VALUE'] = preg_replace('/\D/', '', $params['FIELDS']['PHONE']['VALUE']);
                },
            ],
            'crm.deal.add' => [
                'method' => 'POST',
                'params' => [
                    'FIELDS' => Rule::create(function(&$v): bool {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $schema = array_merge([
                            'TITLE'                 => 'nullable|string',
                            'TYPE_ID'               => Rule::create(fn(&$v) => true),
                            'CATEGORY_ID'           => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v >= 0)),
                            'STAGE_ID'              => Rule::create(fn(&$v) => true),
                            'IS_RECURRING'          => 'nullable|b24_bool',
                            'IS_RETURN_CUSTOMER'    => 'nullable|b24_bool',
                            'IS_REPEATED_APPROACH'  => 'nullable|b24_bool',
                            'PROBABILITY'           => Rule::create(fn(&$v) => $v === null || (is_int($v) && $v >= 0 && $v <= 100)),
                            'CURRENCY_ID'           => Rule::create(fn(&$v) => true),
                            'OPPORTUNITY'           => Rule::create(fn(&$v) => $v === null || is_float($v)),
                            'IS_MANUAL_OPPORTUNITY' => 'nullable|b24_bool',
                            'TAX_VALUE'             => Rule::create(fn(&$v) => $v === null || is_float($v)),
                            'COMPANY_ID'            => Rule::create(fn(&$v) => true),
                            'CONTACT_ID'            => Rule::create(fn(&$v) => true),
                            'CONTACT_IDS'           => Rule::create(fn(&$v) => $v === null || is_array($v)),
                            'BEGINDATE'             => 'nullable|b24_date',
                            'CLOSEDATE'             => 'nullable|b24_date',
                            'OPENED'                => 'nullable|b24_bool',
                            'CLOSED'                => 'nullable|b24_bool',
                            'COMMENTS'              => 'nullable|string',
                            'ASSIGNED_BY_ID'        => Rule::create(fn(&$v) => true),
                            'SOURCE_ID'             => Rule::create(fn(&$v) => true),
                            'SOURCE_DESCRIPTION'    => 'nullable|string',
                            'ADDITIONAL_INFO'       => 'nullable|string',
                            'LOCATION_ID'           => Rule::create(fn(&$v) => true),
                            'ORIGINATOR_ID'         => 'nullable|string',
                            'ORIGIN_ID'             => 'nullable|string',
                            'UTM_SOURCE'            => 'nullable|string',
                            'UTM_MEDIUM'            => Rule::create(fn(&$v) => $v === null || in_array($v, ['CPC', 'CPM'], true)),
                            'UTM_CAMPAIGN'          => 'nullable|string',
                            'UTM_CONTENT'           => 'nullable|string',
                            'UTM_TERM'              => 'nullable|string',
                            'TRACE'                 => 'nullable|string',
                        ], $this->extraSchemas['crm.deal.add']['FIELDS'] ?? []);
                        $errors = Rule::object($schema)->apply($v);
                        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Некорректный FIELDS'),
                    'PARAMS' => Rule::create(function(&$v): bool {
                        if ($v === null) return true;
                        if (!is_array($v)) return false;
                        $schema = array_merge([
                            'REGISTER_SONET_EVENT' => 'nullable|b24_bool',
                        ], $this->extraSchemas['crm.deal.add']['PARAMS'] ?? []);
                        $errors = Rule::object($schema)->apply($v);
                        if (!empty($errors)) throw new \InvalidArgumentException($errors[0]);
                        return true;
                    })->handleError(fn($v) => 'Некорректный PARAMS'),
                ],
            ],
        ]);

    }
}