<?php

namespace ST_system\Schemas\SchemaOrg\Common;

use ST_system\Schemas\DefaultSchema;

final class ImageObject extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'url'     => 'required|url',
            'width'   => 'sometimes|int',
            'height'  => 'sometimes|int',
            'caption' => 'sometimes|string',
        ];
    }

    protected static function getToArray(): \Closure
    {
        return function (DefaultSchema $s): array {
            $data = [
                '@type' => 'ImageObject',
                'url'   => $s->field('url'),
            ];

            if ($s->field('width') !== null) {
                $data['width'] = $s->field('width');
            }

            if ($s->field('height') !== null) {
                $data['height'] = $s->field('height');
            }

            if ($s->field('caption') !== null) {
                $data['caption'] = $s->field('caption');
            }

            return $data;
        };
    }
}
