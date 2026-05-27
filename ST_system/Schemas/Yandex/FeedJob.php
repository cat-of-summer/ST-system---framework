<?php

namespace ST_system\Schemas\Yandex;

use ST_system\Schemas\DefaultSchema;

final class FeedJob extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'yandex-medical-feed';
    }

    protected static function define(): self
    {
        return self::entity('job', ['fields' => [
            'organization' => 'required|string',
            'period_years' => 'sometimes|string',
            'position'     => 'sometimes|string',
        ], 'print' => function (DefaultSchema $s): string {
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
        }]);
    }
}
