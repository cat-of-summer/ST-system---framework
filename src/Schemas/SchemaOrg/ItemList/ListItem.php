<?php

namespace ST_system\Schemas\SchemaOrg\ItemList;

use ST_system\Schemas\DefaultSchema;

final class ListItem extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'position'  => 'required|int',
            'name'      => 'sometimes|string',
            'url'       => 'sometimes|url',
            'item_type' => 'sometimes|string',
            'item_name' => 'sometimes|string',
            'item_url'  => 'sometimes|url',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $result = [
                '@type'    => 'ListItem',
                'position' => $s->field('position'),
            ];

            if ($s->field('name') !== null) {
                $result['name'] = $s->field('name');
            }

            if ($s->field('url') !== null) {
                $result['url'] = $s->field('url');
            }

            if ($s->field('item_name') !== null || $s->field('item_url') !== null) {
                $item = ['@type' => $s->field('item_type') ?? 'Thing'];

                if ($s->field('item_name') !== null) {
                    $item['name'] = $s->field('item_name');
                }

                if ($s->field('item_url') !== null) {
                    $item['url'] = $s->field('item_url');
                }

                $result['item'] = $item;
            }

            return $result;
        };
    }
}
