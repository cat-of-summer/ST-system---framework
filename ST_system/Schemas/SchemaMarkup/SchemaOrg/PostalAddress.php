<?php

namespace ST_system\Schemas\SchemaMarkup\SchemaOrg;

use ST_system\Schema;
use ST_system\Schemas\DefaultSchema;

final class PostalAddress extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'schema.service';
    }

    protected static function define(): Schema
    {
        return Schema::entity('postal-address', ['fields' => [
            'street_address'   => 'sometimes|string',
            'address_locality' => 'sometimes|string',
            'postal_code'      => 'sometimes|string',
            'address_country'  => 'sometimes|string',
        ], 'toArray' => function (Schema $s): array {
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
        }]);
    }
}
