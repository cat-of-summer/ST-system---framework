<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;

final class Service extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'schema';
    }

    protected static function define(): self
    {
        $schema = self::entity('service', ['fields' => [
            'service_type'      => 'required|string',
            'name'              => 'sometimes|string',
            'description'       => 'sometimes|string',
            'url'               => 'sometimes|url',
            'image'             => 'sometimes|url',
            'area_served'       => 'sometimes|string',
            'provider'          => 'sometimes|@provider',
            'offers'            => 'sometimes|@offer',
            'has_offer_catalog' => 'sometimes|@offer-catalog',
        ], 'print' => function (DefaultSchema $s): string {
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
        }]);

        PostalAddress::boot();
        ServiceProvider::boot();
        ServiceOffer::boot();
        ServiceOfferCatalog::boot();

        return $schema;
    }
}
