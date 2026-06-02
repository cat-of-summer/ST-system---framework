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
            $xml .= '<organization>' . $s->field('organization') . '</organization>';
            if ($s->field('finish_year') !== null) {
                $xml .= '<finish_year>' . $s->field('finish_year') . '</finish_year>';
            }
            if ($s->field('type') !== null) {
                $xml .= '<type>' . $s->field('type') . '</type>';
            }
            if ($s->field('specialization') !== null) {
                $xml .= '<specialization>' . $s->field('specialization') . '</specialization>';
            }
            $xml .= '</education>';
            return $xml;
        };
    }
}
