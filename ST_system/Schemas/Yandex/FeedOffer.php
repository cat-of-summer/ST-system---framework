<?php

namespace ST_system\Schemas\Yandex;

use ST_system\Schemas\DefaultSchema;

final class FeedOffer extends DefaultSchema
{
    protected static function defineScope(): string
    {
        return 'yandex-medical-feed';
    }

    protected static function define(): self
    {
        return self::entity('offer', ['fields' => [
            'id'                   => 'required|string',
            'url'                  => 'required|url',
            'oms'                  => 'sometimes|bool',
            'online_schedule'      => 'sometimes|bool',
            'appointment'          => 'sometimes|bool',
            'price'                => 'sometimes|@price',
            'service_id'           => 'required|string',
            'clinic_id'            => 'required|string',
            'doctor_id'            => 'required|string',
            'speciality'           => 'required|speciality',
            'children_appointment' => 'sometimes|bool',
            'adult_appointment'    => 'sometimes|bool',
            'house_call'           => 'sometimes|bool',
            'telemed'              => 'sometimes|bool',
            'is_base_service'      => 'sometimes|bool',
        ], 'print' => function (DefaultSchema $s): string {
            $xml  = '<offer id="' . $s->field('id') . '">';
            $xml .= '<url>' . $s->field('url') . '</url>';

            if ($s->field('oms') !== null) {
                $xml .= '<oms>' . $s->field('oms') . '</oms>';
            }
            if ($s->field('online_schedule') !== null) {
                $xml .= '<online_schedule>' . $s->field('online_schedule') . '</online_schedule>';
            }
            if ($s->field('appointment') !== null) {
                $xml .= '<appointment>' . $s->field('appointment') . '</appointment>';
            }
            if ($s->field('price') !== null) {
                $xml .= $s->field('price')->print();
            }

            $xml .= '<service id="' . $s->field('service_id') . '"/>';
            $xml .= '<clinic id="' . $s->field('clinic_id') . '">';
            $xml .= '<doctor id="' . $s->field('doctor_id') . '">';

            if ($s->field('speciality') !== null) {
                $xml .= '<speciality>' . $s->field('speciality') . '</speciality>';
            }
            if ($s->field('children_appointment') !== null) {
                $xml .= '<children_appointment>' . $s->field('children_appointment') . '</children_appointment>';
            }
            if ($s->field('adult_appointment') !== null) {
                $xml .= '<adult_appointment>' . $s->field('adult_appointment') . '</adult_appointment>';
            }
            if ($s->field('house_call') !== null) {
                $xml .= '<house_call>' . $s->field('house_call') . '</house_call>';
            }
            if ($s->field('telemed') !== null) {
                $xml .= '<telemed>' . $s->field('telemed') . '</telemed>';
            }
            if ($s->field('is_base_service') !== null) {
                $xml .= '<is_base_service>' . $s->field('is_base_service') . '</is_base_service>';
            }

            $xml .= '</doctor>';
            $xml .= '</clinic>';
            $xml .= '</offer>';
            return $xml;
        }]);
    }
}
