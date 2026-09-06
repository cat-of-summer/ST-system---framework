<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\Reference;

final class WebPage extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'type'        => 'sometimes|string',
            'url'         => 'required|url',
            'name'        => 'required|string',
            'description' => 'sometimes|string',
            'in_language' => 'sometimes|string',
            'is_part_of'  => [Reference::class, 'sometimes'],
            'about'       => [Reference::class, 'sometimes'],
            'main_entity' => [Reference::class, 'sometimes'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context' => 'https://schema.org',
                '@type'    => $s->field('type') ?? 'WebPage',
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

            if ($s->field('about') !== null) {
                $data['about'] = $s->field('about')->toArray();
            }

            if ($s->field('main_entity') !== null) {
                $data['mainEntity'] = $s->field('main_entity')->toArray();
            }

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                . '</script>';
        };
    }
}
