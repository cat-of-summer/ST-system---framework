<?php

namespace ST_system\Schemas\SchemaOrg\Service;

use ST_system\Schemas\DefaultSchema;

final class Offer extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'price'          => 'required|string',
            'price_currency' => 'required|string',
            'url'            => 'sometimes|url',
            'availability'   => 'sometimes|string',
            'valid_through'  => 'sometimes|string',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type'         => 'Offer',
                'price'         => $s->field('price'),
                'priceCurrency' => $s->field('price_currency'),
            ];

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('availability') !== null) {
                $data['availability'] = $s->field('availability');
            }

            if ($s->field('valid_through') !== null) {
                $data['validThrough'] = $s->field('valid_through');
            }

            return $data;
        };
    }
}
