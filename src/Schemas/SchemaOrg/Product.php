<?php

namespace ST_system\Schemas\SchemaOrg;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\Common\PropertyValue;
use ST_system\Schemas\SchemaOrg\Common\Reference;

final class Product extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'name'                => 'required|string',
            'url'                 => 'sometimes|url',
            'description'         => 'sometimes|string',
            'image'               => 'sometimes|array',
            'sku'                 => 'sometimes|string',
            'mpn'                 => 'sometimes|string',
            'brand'               => 'sometimes|string',
            'manufacturer'        => [Reference::class, 'sometimes'],
            'category'            => 'sometimes|string',
            'additional_property' => [self::arrayOf(PropertyValue::class), 'sometimes'],
            'offers'              => 'sometimes|@offer',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $data = [
                '@context' => 'https://schema.org',
                '@type'    => 'Product',
                'name'     => $s->field('name'),
            ];

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if (!empty($s->field('image'))) {
                $data['image'] = array_values($s->field('image'));
            }

            if ($s->field('description') !== null) {
                $data['description'] = $s->field('description');
            }

            if ($s->field('sku') !== null) {
                $data['sku'] = $s->field('sku');
            }

            if ($s->field('mpn') !== null) {
                $data['mpn'] = $s->field('mpn');
            }

            if ($s->field('category') !== null) {
                $data['category'] = $s->field('category');
            }

            if ($s->field('brand') !== null) {
                $data['brand'] = [
                    '@type' => 'Brand',
                    'name'  => $s->field('brand'),
                ];
            }

            if ($s->field('manufacturer') !== null) {
                $data['manufacturer'] = $s->field('manufacturer')->toArray();
            }

            if (!empty($s->field('additional_property'))) {
                $data['additionalProperty'] = array_map(
                    static fn ($property) => $property->toArray(),
                    $s->field('additional_property')
                );
            }

            if ($s->field('offers') !== null) {
                $data['offers'] = $s->field('offers')->toArray();
            }

            return '<script type="application/ld+json">'
                . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                . '</script>';
        };
    }
}
