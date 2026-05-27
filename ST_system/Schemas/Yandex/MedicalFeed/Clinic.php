<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;

final class Clinic extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'id'          => 'required|string',
            'internal_id' => 'sometimes|string',
            'name'        => 'required|string',
            'url'         => 'required|url',
            'city'        => 'sometimes|string',
            'address'     => 'sometimes|string',
            'phone'       => 'sometimes|string',
            'email'       => 'sometimes|string',
            'picture'     => 'sometimes|url',
            'company_id'  => 'sometimes|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $internalId = $s->field('internal_id') ?? $s->field('id');
            $xml  = '<clinic id="' . $s->field('id') . '">';
            $xml .= '<url>' . $s->field('url') . '</url>';

            if ($s->field('picture') !== null) {
                $xml .= '<picture>' . $s->field('picture') . '</picture>';
            }
            $xml .= '<name>' . $s->field('name') . '</name>';
            if ($s->field('city') !== null) {
                $xml .= '<city>' . $s->field('city') . '</city>';
            }
            if ($s->field('address') !== null) {
                $xml .= '<address>' . $s->field('address') . '</address>';
            }
            if ($s->field('email') !== null) {
                $xml .= '<email>' . $s->field('email') . '</email>';
            }
            if ($s->field('phone') !== null) {
                $xml .= '<phone>' . $s->field('phone') . '</phone>';
            }
            $xml .= '<internal_id>' . $internalId . '</internal_id>';
            if ($s->field('company_id') !== null) {
                $xml .= '<company_id>' . $s->field('company_id') . '</company_id>';
            }

            $xml .= '</clinic>';
            return $xml;
        };
    }
}
