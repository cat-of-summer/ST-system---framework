<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\Reference;

final class Article extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'type'           => 'sometimes|string',
            'url'            => 'required|url',
            'headline'       => 'required|string',
            'description'    => 'sometimes|string',
            'image'          => 'sometimes|array',
            'in_language'    => 'sometimes|string',
            'date_published' => 'sometimes|string',
            'date_modified'  => 'sometimes|string',
            'author'         => [Reference::class, 'sometimes'],
            'publisher'      => [Reference::class, 'sometimes'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context'         => 'https://schema.org',
                '@type'            => $s->field('type') ?? 'Article',
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id'   => $s->field('url'),
                ],
                'url'              => $s->field('url'),
                'headline'         => $s->field('headline'),
            ];

            if ($s->field('description') !== null) {
                $data['description'] = $s->field('description');
            }

            if (!empty($s->field('image'))) {
                $data['image'] = array_values($s->field('image'));
            }

            if ($s->field('in_language') !== null) {
                $data['inLanguage'] = $s->field('in_language');
            }

            if ($s->field('date_published') !== null) {
                $data['datePublished'] = $s->field('date_published');
            }

            if ($s->field('date_modified') !== null) {
                $data['dateModified'] = $s->field('date_modified');
            }

            if ($s->field('author') !== null) {
                $data['author'] = $s->field('author')->toArray();
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
