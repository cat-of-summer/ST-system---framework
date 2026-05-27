<?php

namespace ST_system\Schemas\SchemaMarkup\SchemaOrg;

use ST_system\Schema;
use ST_system\Schemas\DefaultSchema;

final class ServiceProvider extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'schema.service';
    }

    protected static function define(): Schema
    {
        return Schema::entity('provider', ['fields' => [
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
    }
}
