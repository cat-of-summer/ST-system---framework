<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

final class Place extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'name'    => 'sometimes|string',
            'url'     => 'sometimes|url',
            'address' => [PostalAddress::class, 'sometimes'],
            'geo'     => [GeoCoordinates::class, 'sometimes'],
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = ['@type' => 'Place'];

            if ($s->field('name') !== null) {
                $data['name'] = $s->field('name');
            }

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('address') !== null) {
                $data['address'] = $s->field('address')->toArray();
            }

            if ($s->field('geo') !== null) {
                $data['geo'] = $s->field('geo')->toArray();
            }

            return $data;
        };
    }
}
