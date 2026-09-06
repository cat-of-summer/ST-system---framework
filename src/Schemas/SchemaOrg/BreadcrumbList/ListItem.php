<?php

namespace ST_system\Schemas\SchemaOrg\BreadcrumbList;

use ST_system\Schemas\DefaultSchema;

final class ListItem extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'position' => 'required|int',
            'name'     => 'required|string',
            'item'     => 'sometimes|url',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type'    => 'ListItem',
                'position' => $s->field('position'),
                'name'     => $s->field('name'),
            ];

            if ($s->field('item') !== null) {
                $data['item'] = $s->field('item');
            }

            return $data;
        };
    }
}
