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
            $xml  = '<clinic id="' . self::xml($s->field('id')) . '">';
            $xml .= '<url>' . self::xml($s->field('url')) . '</url>';

            if ($s->field('picture') !== null) {
                $xml .= '<picture>' . self::xml($s->field('picture')) . '</picture>';
            }
            $xml .= '<name>' . self::xml($s->field('name')) . '</name>';
            if ($s->field('city') !== null) {
                $xml .= '<city>' . self::xml($s->field('city')) . '</city>';
            }
            if ($s->field('address') !== null) {
                $xml .= '<address>' . self::xml($s->field('address')) . '</address>';
            }
            if ($s->field('email') !== null) {
                $xml .= '<email>' . self::xml($s->field('email')) . '</email>';
            }
            if ($s->field('phone') !== null) {
                $xml .= '<phone>' . self::xml($s->field('phone')) . '</phone>';
            }
            $xml .= '<internal_id>' . self::xml($internalId) . '</internal_id>';
            if ($s->field('company_id') !== null) {
                $xml .= '<company_id>' . self::xml($s->field('company_id')) . '</company_id>';
            }

            $xml .= '</clinic>';
            return $xml;
        };
    }
}
