<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;

final class Education extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'organization'   => 'required|string',
            'finish_year'    => 'sometimes|int',
            'type'           => 'sometimes|string',
            'specialization' => 'sometimes|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $xml  = '<education>';
            $xml .= '<organization>' . self::xml($s->field('organization')) . '</organization>';
            if ($s->field('finish_year') !== null) {
                $xml .= '<finish_year>' . self::xml($s->field('finish_year')) . '</finish_year>';
            }
            if ($s->field('type') !== null) {
                $xml .= '<type>' . self::xml($s->field('type')) . '</type>';
            }
            if ($s->field('specialization') !== null) {
                $xml .= '<specialization>' . self::xml($s->field('specialization')) . '</specialization>';
            }
            $xml .= '</education>';
            return $xml;
        };
    }
}
