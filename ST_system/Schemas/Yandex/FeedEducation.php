<?php

namespace ST_system\Schemas\Yandex;

use ST_system\Schemas\DefaultSchema;

final class FeedEducation extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'yandex-medical-feed';
    }

    protected static function define(): self
    {
        return self::entity('education', ['fields' => [
            'organization'   => 'required|string',
            'finish_year'    => 'sometimes|int',
            'type'           => 'sometimes|string',
            'specialization' => 'sometimes|string',
        ], 'print' => function (DefaultSchema $s): string {
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
        }]);
    }
}
