<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;

final class Job extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'organization' => 'required|string',
            'period_years' => 'sometimes|string',
            'position'     => 'sometimes|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $xml  = '<job>';
            $xml .= '<organization>' . $s->field('organization') . '</organization>';
            if ($s->field('period_years') !== null) {
                $xml .= '<period_years>' . $s->field('period_years') . '</period_years>';
            }
            if ($s->field('position') !== null) {
                $xml .= '<position>' . $s->field('position') . '</position>';
            }
            $xml .= '</job>';
            return $xml;
        };
    }
}
