<?php

namespace ST_system\Schemas\SchemaMarkup\SchemaOrg;

use ST_system\Schema;
use ST_system\Schemas\DefaultSchema;

final class ItemList extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'schema';
    }

    protected static function define(): Schema
    {
        $schema = Schema::entity('item-list', ['fields' => [
            'name'            => 'required|string',
            'description'     => 'sometimes|string',
            'url'             => 'sometimes|url',
            'number_of_items' => 'sometimes|int',
            'items'           => [Schema::arrayOf('@list-item'), 'sometimes'],
        ], 'print' => function (Schema $s): string {
            $items = $s->field('items') ?? [];

            $data = [
                '@context'      => 'https://schema.org',
                '@type'         => 'ItemList',
                'name'          => $s->field('name'),
                'numberOfItems' => $s->field('number_of_items') ?? count($items),
            ];

            if ($s->field('description') !== null) {
                $data['description'] = $s->field('description');
            }

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if (!empty($items)) {
                $data['itemListElement'] = array_map(
                    static fn ($item) => $item->toArray(),
                    $items
                );
            }

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>';
        }]);

        ListItem::boot();

        return $schema;
    }
}
