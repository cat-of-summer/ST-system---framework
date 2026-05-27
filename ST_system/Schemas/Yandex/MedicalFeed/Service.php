<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;

final class Service extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'id'          => 'required|string',
            'internal_id' => 'sometimes|string',
            'name'        => 'required|string',
            'gov_id'      => 'sometimes|string',
            'description' => 'sometimes|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $internalId = $s->field('internal_id') ?? $s->field('id');
            $xml  = '<service id="' . $s->field('id') . '">';
            $xml .= '<name>' . $s->field('name') . '</name>';

            if ($s->field('gov_id') !== null) {
                $xml .= '<gov_id>' . $s->field('gov_id') . '</gov_id>';
            }
            if ($s->field('description') !== null) {
                $xml .= '<description>' . trim($s->field('description')) . '</description>';
            }
            $xml .= '<internal_id>' . $internalId . '</internal_id>';

            $xml .= '</service>';
            return $xml;
        };
    }
}
