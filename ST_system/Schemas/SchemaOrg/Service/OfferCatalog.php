<?php

namespace ST_system\Schemas\SchemaOrg\Service;

use ST_system\Schemas\DefaultSchema;

final class OfferCatalog extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'name'  => 'required|string',
            'items' => 'sometimes',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type' => 'OfferCatalog',
                'name'  => $s->field('name'),
            ];

            $items = (array) ($s->field('items') ?? []);

            if (!empty($items)) {
                $data['itemListElement'] = array_map(static function (array $item): array {
                    $itemOffered = ['@type' => $item['type'] ?? 'Service'];

                    if (isset($item['name'])) {
                        $itemOffered['name'] = $item['name'];
                    }

                    if (isset($item['url'])) {
                        $itemOffered['url'] = $item['url'];
                    }

                    return [
                        '@type'       => 'Offer',
                        'itemOffered' => $itemOffered,
                    ];
                }, $items);
            }

            return $data;
        };
    }
}
