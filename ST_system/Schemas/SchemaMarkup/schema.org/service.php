<?php


use ST_system\Schema;


Schema::scope('schema', function (): void {

    Schema::entity('service', ['fields' => [
        'service_type'      => 'required|string',
        'name'              => 'sometimes|string',
        'description'       => 'sometimes|string',
        'url'               => 'sometimes|url',
        'image'             => 'sometimes|url',
        'area_served'       => 'sometimes|string',
        'provider'          => 'sometimes|@provider',
        'offers'            => 'sometimes|@offer',
        'has_offer_catalog' => 'sometimes|@offer-catalog',
    ], 'print' => function (Schema $s): string {
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

    }])->scope(function (): void {

        
        Schema::entity('postal-address', ['fields' => [
            'street_address'   => 'sometimes|string',
            'address_locality' => 'sometimes|string',
            'postal_code'      => 'sometimes|string',
            'address_country'  => 'sometimes|string',
        ], 'toArray' => function (Schema $s): array {
            $data = ['@type' => 'PostalAddress'];

            if ($s->field('street_address') !== null) {
                $data['streetAddress'] = $s->field('street_address');
            }

            if ($s->field('address_locality') !== null) {
                $data['addressLocality'] = $s->field('address_locality');
            }

            if ($s->field('postal_code') !== null) {
                $data['postalCode'] = $s->field('postal_code');
            }

            if ($s->field('address_country') !== null) {
                $data['addressCountry'] = $s->field('address_country');
            }

            return $data;
        }]);

        
        Schema::entity('provider', ['fields' => [
            'type'      => 'sometimes|string',
            'name'      => 'required|string',
            'url'       => 'sometimes|url',
            'telephone' => 'sometimes|string',
            'address'   => 'sometimes|@postal-address',
        ], 'toArray' => function (Schema $s): array {
            $data = [
                '@type' => $s->field('type') ?? 'Organization',
                'name'  => $s->field('name'),
            ];

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('telephone') !== null) {
                $data['telephone'] = $s->field('telephone');
            }

            if ($s->field('address') !== null) {
                $data['address'] = $s->field('address')->toArray();
            }

            return $data;
        }]);

        
        Schema::entity('offer', ['fields' => [
            'price'          => 'required|string',
            'price_currency' => 'required|string',
            'url'            => 'sometimes|url',
            'availability'   => 'sometimes|string',
            'valid_through'  => 'sometimes|string',
        ], 'toArray' => function (Schema $s): array {
            $data = [
                '@type'         => 'Offer',
                'price'         => $s->field('price'),
                'priceCurrency' => $s->field('price_currency'),
            ];

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('availability') !== null) {
                $data['availability'] = $s->field('availability');
            }

            if ($s->field('valid_through') !== null) {
                $data['validThrough'] = $s->field('valid_through');
            }

            return $data;
        }]);

        
        Schema::entity('offer-catalog', ['fields' => [
            'name'  => 'required|string',
            'items' => 'sometimes',
        ], 'toArray' => function (Schema $s): array {
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
        }]);

    });

});
