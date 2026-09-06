<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

final class GeoCoordinates extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'latitude'  => 'required|string',
            'longitude' => 'required|string',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            return [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $s->field('latitude'),
                'longitude' => $s->field('longitude'),
            ];
        };
    }
}
