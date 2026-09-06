<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\Reference;
use ST_system\Schemas\SchemaOrg\ItemList\ListItem;

final class CollectionPage extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'url'         => 'required|url',
            'name'        => 'required|string',
            'description' => 'sometimes|string',
            'in_language' => 'sometimes|string',
            'is_part_of'  => [Reference::class, 'sometimes'],
            'items'       => [self::arrayOf(ListItem::class), 'sometimes'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'url'      => $s->field('url'),
                'name'     => $s->field('name'),
            ];

            if ($s->field('description') !== null) {
                $data['description'] = $s->field('description');
            }

            if ($s->field('in_language') !== null) {
                $data['inLanguage'] = $s->field('in_language');
            }

            if ($s->field('is_part_of') !== null) {
                $data['isPartOf'] = $s->field('is_part_of')->toArray();
            }

            $items = $s->field('items') ?? [];

            if (!empty($items)) {
                $data['mainEntity'] = [
                    '@type'           => 'ItemList',
                    'numberOfItems'   => count($items),
                    'itemListElement' => array_map(
                        static fn ($item) => $item->toArray(),
                        $items
                    ),
                ];
            }

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                . '</script>';
        };
    }
}
