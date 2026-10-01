<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;

final class Certificate extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'organization' => 'required|string',
            'finish_year'  => 'sometimes|int',
            'name'         => 'required|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $xml  = '<certificate>';
            $xml .= '<organization>' . self::xml($s->field('organization')) . '</organization>';
            if ($s->field('finish_year') !== null) {
                $xml .= '<finish_year>' . self::xml($s->field('finish_year')) . '</finish_year>';
            }
            $xml .= '<name>' . self::xml($s->field('name')) . '</name>';
            $xml .= '</certificate>';
            return $xml;
        };
    }
}
