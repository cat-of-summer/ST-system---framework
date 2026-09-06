<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

final class PropertyValue extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'name'        => 'required|string',
            'value'       => 'required|string',
            'property_id' => 'sometimes|string',
            'unit_text'   => 'sometimes|string',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = ['@type' => 'PropertyValue'];

            if ($s->field('property_id') !== null) {
                $data['propertyID'] = $s->field('property_id');
            }

            $data['name']  = $s->field('name');
            $data['value'] = $s->field('value');

            if ($s->field('unit_text') !== null) {
                $data['unitText'] = $s->field('unit_text');
            }

            return $data;
        };
    }
}
