<?php

namespace ST_system\Schemas\Yandex;

use ST_system\Schemas\DefaultSchema;

final class FeedCertificate extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'yandex-medical-feed';
    }

    protected static function define(): self
    {
        return self::entity('certificate', ['fields' => [
            'organization' => 'required|string',
            'finish_year'  => 'sometimes|int',
            'name'         => 'required|string',
        ], 'print' => function (DefaultSchema $s): string {
            $xml  = '<certificate>';
            $xml .= '<organization>' . $s->field('organization') . '</organization>';
            if ($s->field('finish_year') !== null) {
                $xml .= '<finish_year>' . $s->field('finish_year') . '</finish_year>';
            }
            $xml .= '<name>' . $s->field('name') . '</name>';
            $xml .= '</certificate>';
            return $xml;
        }]);
    }
}
