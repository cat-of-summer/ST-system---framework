<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\Reference;

final class WebSite extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'id'          => 'required|string',
            'url'         => 'required|url',
            'name'        => 'required|string',
            'description' => 'sometimes|string',
            'in_language' => 'sometimes|string',
            'publisher'   => [Reference::class, 'sometimes'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context' => 'https://schema.org',
                '@type'    => 'WebSite',
                '@id'      => $s->field('id'),
                'url'      => $s->field('url'),
                'name'     => $s->field('name'),
            ];

            if ($s->field('description') !== null) {
                $data['description'] = $s->field('description');
            }

            if ($s->field('in_language') !== null) {
                $data['inLanguage'] = $s->field('in_language');
            }

            if ($s->field('publisher') !== null) {
                $data['publisher'] = $s->field('publisher')->toArray();
            }

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                . '</script>';
        };
    }
}
