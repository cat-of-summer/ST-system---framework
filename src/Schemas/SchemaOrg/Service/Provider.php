<?php

namespace ST_system\Schemas\SchemaOrg\Service;

use ST_system\Schemas\DefaultSchema;

final class Provider extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'type'      => 'sometimes|string',
            'name'      => 'required|string',
            'url'       => 'sometimes|url',
            'telephone' => 'sometimes|string',
            'address'   => 'sometimes|@postal-address',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
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
        };
    }
}
