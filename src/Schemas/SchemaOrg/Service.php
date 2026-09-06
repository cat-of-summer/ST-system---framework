<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;

final class Service extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'service_type'      => 'required|string',
            'name'              => 'sometimes|string',
            'description'       => 'sometimes|string',
            'url'               => 'sometimes|url',
            'image'             => 'sometimes|url',
            'area_served'       => 'sometimes|string',
            'provider'          => 'sometimes|@provider',
            'provider_id'       => 'sometimes|string',
            'offers'            => 'sometimes|@offer',
            'has_offer_catalog' => 'sometimes|@offer-catalog',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context'    => 'https://schema.org',
                '@type'       => 'Service',
                'serviceType' => $s->field('service_type'),
            ];

            if ($s->field('name') !== null) {
                $data['name'] = $s->field('name');
            }

            if ($s->field('description') !== null) {
                $data['description'] = $s->field('description');
            }

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('image') !== null) {
                $data['image'] = $s->field('image');
            }

            if ($s->field('area_served') !== null) {
                $data['areaServed'] = $s->field('area_served');
            }

            if ($s->field('provider') !== null) {
                $data['provider'] = $s->field('provider')->toArray();
            } elseif ($s->field('provider_id') !== null) {
                //поставщик уже объявлен отдельным блоком Organization — ссылаемся на него,
                //а не повторяем его поля вторым описанием той же компании
                $data['provider'] = ['@id' => $s->field('provider_id')];
            }

            if ($s->field('offers') !== null) {
                $data['offers'] = $s->field('offers')->toArray();
            }

            if ($s->field('has_offer_catalog') !== null) {
                $data['hasOfferCatalog'] = $s->field('has_offer_catalog')->toArray();
            }

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>';
        };
    }
}
