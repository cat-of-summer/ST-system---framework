<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

final class ContactPoint extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'contact_type'       => 'required|string',
            'telephone'          => 'sometimes|string',
            'email'              => 'sometimes|email',
            'url'                => 'sometimes|url',
            'area_served'        => 'sometimes|string',
            'available_language' => 'sometimes',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type'       => 'ContactPoint',
                'contactType' => $s->field('contact_type'),
            ];

            if ($s->field('telephone') !== null) {
                $data['telephone'] = $s->field('telephone');
            }

            if ($s->field('email') !== null) {
                $data['email'] = $s->field('email');
            }

            if ($s->field('url') !== null) {
                $data['url'] = $s->field('url');
            }

            if ($s->field('area_served') !== null) {
                $data['areaServed'] = $s->field('area_served');
            }

            if ($s->field('available_language') !== null) {
                $data['availableLanguage'] = (array) $s->field('available_language');
            }

            return $data;
        };
    }
}
