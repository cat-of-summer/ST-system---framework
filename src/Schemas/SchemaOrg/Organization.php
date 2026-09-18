<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\ContactPoint;
use ST_system\Schemas\SchemaOrg\Common\GeoCoordinates;
use ST_system\Schemas\SchemaOrg\Common\ImageObject;
use ST_system\Schemas\SchemaOrg\Common\Place;
use ST_system\Schemas\SchemaOrg\Common\PostalAddress;
use ST_system\Schemas\SchemaOrg\Common\PropertyValue;

final class Organization extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'id'             => 'required|string',
            'type'           => 'sometimes|string',
            'name'           => 'required|string',
            'legal_name'     => 'sometimes|string',
            'tax_id'         => 'sometimes|string',
            'identifiers'    => [self::arrayOf(PropertyValue::class), 'sometimes'],
            'url'            => 'sometimes|url',
            'logo'           => [ImageObject::class, 'sometimes'],
            'telephone'      => 'sometimes|string',
            'email'          => 'sometimes|email',
            'address'        => [PostalAddress::class, 'sometimes'],
            'geo'            => [GeoCoordinates::class, 'sometimes'],
            'location'       => [Place::class, 'sometimes'],
            'area_served'    => 'sometimes|string',
            'contact_points' => [self::arrayOf(ContactPoint::class), 'sometimes'],
            'same_as'        => 'sometimes|array',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type' => $s->field('type') ?? 'Organization',
                '@id'   => $s->field('id'),
                'name'  => $s->field('name'),
            ];

            if ($s->field('legal_name') !== null) {
                $data['legalName'] = $s->field('legal_name');
            }

            if ($s->field('tax_id') !== null) {
                $data['taxID'] = $s->field('tax_id');
            }

            if (!empty($s->field('identifiers'))) {
                $data['identifier'] = array_map(
                    static fn ($identifier) => $identifier->toArray(),
                    $s->field('identifiers')
                );
            }

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('logo') !== null) {
                $data['logo'] = $s->field('logo')->toArray();
            }

            if ($s->field('telephone') !== null) {
                $data['telephone'] = $s->field('telephone');
            }

            if ($s->field('email') !== null) {
                $data['email'] = $s->field('email');
            }

            if ($s->field('address') !== null) {
                $data['address'] = $s->field('address')->toArray();
            }

            $location = $s->field('location');

            if ($location === null && $s->field('geo') !== null) {
                $location = Place::create()->fill(array_filter([
                    'address' => $s->field('address'),
                    'geo'     => $s->field('geo'),
                ], static fn ($value) => $value !== null));
            }

            if ($location !== null) {
                $data['location'] = $location->toArray();
            }

            if ($s->field('area_served') !== null) {
                $data['areaServed'] = [
                    '@type' => 'Country',
                    'name'  => $s->field('area_served'),
                ];
            }

            if (!empty($s->field('contact_points'))) {
                $data['contactPoint'] = array_map(
                    static fn ($point) => $point->toArray(),
                    $s->field('contact_points')
                );
            }

            if (!empty($s->field('same_as'))) {
                $data['sameAs'] = array_values($s->field('same_as'));
            }

            return $data;
        };
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = ['@context' => 'https://schema.org'] + $s->toArray();

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                . '</script>';
        };
    }
}
