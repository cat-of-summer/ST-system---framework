<?php

namespace ST_system\Schemas\SchemaOrg\Service;

use ST_system\Schemas\DefaultSchema;

final class PostalAddress extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'street_address'   => 'sometimes|string',
            'address_locality' => 'sometimes|string',
            'postal_code'      => 'sometimes|string',
            'address_country'  => 'sometimes|string',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = ['@type' => 'PostalAddress'];

            if ($s->field('street_address') !== null) {
                $data['streetAddress'] = $s->field('street_address');
            }

            if ($s->field('address_locality') !== null) {
                $data['addressLocality'] = $s->field('address_locality');
            }

            if ($s->field('postal_code') !== null) {
                $data['postalCode'] = $s->field('postal_code');
            }

            if ($s->field('address_country') !== null) {
                $data['addressCountry'] = $s->field('address_country');
            }

            return $data;
        };
    }
}
