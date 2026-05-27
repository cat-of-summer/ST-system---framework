<?php

namespace ST_system\Schemas\Yandex;

use ST_system\Schemas\DefaultSchema;

final class MedicalFeed extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
            'date'     => 'date_format:Y-m-d H:i|default:' . date('Y-m-d H:i'),
            'name'     => 'required|string',
            'company'  => 'sometimes|string',
            'url'      => 'required|url',
            'picture'  => 'sometimes|url',
            'email'    => 'sometimes|string',
            'doctors'  => [self::arrayOf('doctor'),  'sometimes'],
            'clinics'  => [self::arrayOf('clinic'),  'sometimes'],
            'services' => [self::arrayOf('service'), 'sometimes'],
            'offers'   => [self::arrayOf('offer'),   'sometimes'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<shop version="2.0" date="' . $s->field('date') . '">';
            $xml .= '<name>' . $s->field('name') . '</name>';

            if ($s->field('company') !== null) {
                $xml .= '<company>' . $s->field('company') . '</company>';
            }

            $xml .= '<url>' . $s->field('url') . '</url>';

            if ($s->field('picture') !== null) {
                $xml .= '<picture>' . $s->field('picture') . '</picture>';
            }

            if ($s->field('email') !== null) {
                $xml .= '<email>' . $s->field('email') . '</email>';
            }

            if ($s->field('doctors')) {
                $xml .= '<doctors>';
                foreach ($s->field('doctors') as $d) {
                    $xml .= $d->print();
                }
                $xml .= '</doctors>';
            }

            if ($s->field('clinics')) {
                $xml .= '<clinics>';
                foreach ($s->field('clinics') as $c) {
                    $xml .= $c->print();
                }
                $xml .= '</clinics>';
            }

            if ($s->field('services')) {
                $xml .= '<services>';
                foreach ($s->field('services') as $svc) {
                    $xml .= $svc->print();
                }
                $xml .= '</services>';
            }

            if ($s->field('offers')) {
                $xml .= '<offers>';
                foreach ($s->field('offers') as $o) {
                    $xml .= $o->print();
                }
                $xml .= '</offers>';
            }

            $xml .= '</shop>';
            return $xml;
        };
    }
}
