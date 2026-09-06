<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;

final class BreadcrumbList extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'items' => [self::arrayOf('list-item'), 'required'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => array_map(
                    static fn ($item) => $item->toArray(),
                    $s->field('items') ?? []
                ),
            ];

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                . '</script>';
        };
    }
}
