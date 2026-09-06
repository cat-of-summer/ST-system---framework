<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

final class Reference extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'id' => 'required|string',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            return ['@id' => $s->field('id')];
        };
    }
}
