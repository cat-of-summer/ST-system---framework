<?php

namespace ST_system\Schemas\SchemaOrg\Product;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\Reference;

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
            'seller'         => [Reference::class, 'sometimes'],
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = ['@type' => 'Offer'];

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            $data['priceCurrency'] = $s->field('price_currency');
            $data['price']         = $s->field('price');

            if ($s->field('availability') !== null) {
                $data['availability'] = $s->field('availability');
            }

            if ($s->field('valid_through') !== null) {
                $data['priceValidUntil'] = $s->field('valid_through');
            }

            if ($s->field('seller') !== null) {
                $data['seller'] = $s->field('seller')->toArray();
            }

            return $data;
        };
    }
}
