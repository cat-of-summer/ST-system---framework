<?php

namespace ST_system\Schemas\SchemaMarkup\SchemaOrg;

use ST_system\Schema;
use ST_system\Schemas\DefaultSchema;

final class ServiceOffer extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'schema.service';
    }

    protected static function define(): Schema
    {
        return Schema::entity('offer', ['fields' => [
            'price'          => 'required|string',
            'price_currency' => 'required|string',
            'url'            => 'sometimes|url',
            'availability'   => 'sometimes|string',
            'valid_through'  => 'sometimes|string',
        ], 'toArray' => function (Schema $s): array {
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
        }]);
    }
}
