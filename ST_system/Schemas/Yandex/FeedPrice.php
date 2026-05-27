<?php

namespace ST_system\Schemas\Yandex;

use ST_system\Schemas\DefaultSchema;
use ST_system\Rule;

final class FeedPrice extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'yandex-medical-feed';
    }

    protected static function define(): self
    {
        return self::entity('price', ['fields' => [
            'base_price'       => 'required|float',
            'currency'         => 'required|string',
            'discounts'        => ['sometimes', Rule::object(['name' => 'required|string', 'amount' => 'required|float'])],
            'free_appointment' => ['sometimes', Rule::forEach('string')],
        ], 'print' => function (DefaultSchema $s): string {
            $xml  = '<price>';
            $xml .= '<base_price>' . $s->field('base_price') . '</base_price>';
            $xml .= '<currency>' . $s->field('currency') . '</currency>';
            foreach ((array) ($s->field('discounts') ?? []) as $d) {
                $xml .= '<discount name="' . ($d['name'] ?? '') . '">' . ($d['amount'] ?? 0) . '</discount>';
            }
            foreach ((array) ($s->field('free_appointment') ?? []) as $fa) {
                $xml .= '<free_appointment>' . $fa . '</free_appointment>';
            }
            $xml .= '</price>';
            return $xml;
        }]);
    }
}
