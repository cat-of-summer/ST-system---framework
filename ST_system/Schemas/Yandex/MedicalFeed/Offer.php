<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;
use ST_system\Rule;

final class Offer extends DefaultSchema
{
    protected static function _init(): void
    {
        if (!Rule::get('boolToString')) {
            Rule::create(fn (&$v): bool => (bool)($v = $v ? 'true' : 'false'))
                ->alias('boolToString');
        }

        if (!Rule::get('speciality')) {
            Rule::create(['string', Rule::in([
                'абдоминальный хирург',
                'акушер',
                'акушер-гинеколог',
                'аллерголог',
                'аллерголог-иммунолог',
                'андролог',
                'анестезиолог',
                'анестезиолог-реаниматолог',
                'аритмолог',
                'артролог',
                'бариатрический хирург',
                'вегетолог',
                'венеролог',
                'вертебролог',
                'вирусолог',
                'врач лабораторной диагностики',
                'врач лфк',
                'врач общей практики',
                'врач по медико-социальной экспертизе',
                'врач по паллиативной медицинской помощи',
                'врач по спортивной медицине',
                'врач по рентгенэндоваскулярным диагностике и лечению',
                'врач скорой помощи',
                'врач УЗИ',
                'врач функциональной диагностики',
                'врач эфферентной терапии',
                'гастроэнтеролог',
                'гематолог',
                'гемостазиолог',
                'генетик',
                'гепатолог',
                'гериатр (геронтолог)',
                'гинеколог',
                'гинеколог-эндокринолог',
                'гипнолог',
                'гирудотерапевт',
                'гнатолог',
                'гнойный хирург',
                'дезинфектолог',
                'дерматолог',
                'дерматовенеролог',
                'дефектолог',
                'диабетолог',
                'диетолог',
                'иммунолог',
                'инструктор лфк',
                'инфекционист',
                'кардиолог',
                'кардиохирург',
                'кинезиолог',
                'кистевой хирург',
                'клинический фармаколог',
                'колопроктолог (проктолог)',
                'косметолог',
                'лазерный хирург',
                'лимфолог',
                'логопед',
                'лор (отоларинголог)',
                'малоинвазивный хирург',
                'маммолог',
                'мануальный терапевт',
                'массажист',
                'миколог',
                'нарколог',
                'невролог',
                'нейропсихолог',
                'нейрофизиолог',
                'нейрохирург',
                'неонатолог',
                'нефролог',
                'нутрициолог',
                'ожоговый хирург (комбустиолог)',
                'онкогинеколог',
                'онкодерматолог',
                'онколог',
                'онколог-гематолог',
                'онкопроктолог',
                'онкоуролог',
                'оптометрист',
                'ортопед',
                'остеопат',
                'отоневролог',
                'офтальмолог (окулист)',
                'офтальмолог-протезист',
                'офтальмохирург',
                'паразитолог',
                'патологоанатом',
                'педиатр',
                'перинатолог',
                'пластический хирург',
                'подиатр',
                'подолог',
                'профпатолог',
                'психиатр',
                'психолог',
                'психотерапевт',
                'пульмонолог',
                'радиолог',
                'радиотерапевт',
                'реабилитолог',
                'реаниматолог',
                'ревматолог',
                'рентгенолог',
                'репродуктолог',
                'рефлексотерапевт',
                'сексолог',
                'семейный врач',
                'сердечно-сосудистый хирург',
                'сомнолог',
                'сосудистый хирург',
                'специалист по грудному вскармливанию',
                'спортивный врач',
                'стоматолог',
                'стоматолог-гигиенист',
                'стоматолог-имплантолог',
                'стоматолог-ортодонт',
                'стоматолог-ортопед',
                'стоматолог-пародонтолог',
                'стоматолог-терапевт',
                'стоматолог-хирург',
                'стоматолог-эндодонт',
                'судебно-медицинский эксперт',
                'сурдолог',
                'сурдолог-протезист',
                'терапевт',
                'токсиколог',
                'торакальный онколог',
                'торакальный хирург',
                'травматолог',
                'трансплантолог',
                'трансфузиолог',
                'трихолог',
                'уролог',
                'физиотерапевт',
                'фитотерапевт',
                'флеболог',
                'фониатр',
                'фтизиатр',
                'химиотерапевт',
                'хирург',
                'хирург-эндокринолог',
                'цитолог',
                'челюстно-лицевой хирург',
                'эмбриолог',
                'эндоваскулярный хирург',
                'эндокринолог',
                'эндоскопист',
                'эпидемиолог',
                'эпилептолог',
            ])])->alias('speciality');
        }
    }

    protected static function getFields(): array
    {
        return [
            'id'                   => 'required|string',
            'url'                  => 'required|url',
            'oms'                  => 'sometimes|bool|boolToString',
            'online_schedule'      => 'sometimes|bool|boolToString',
            'appointment'          => 'sometimes|bool|boolToString',
            'price'                => 'sometimes|@price',
            'service_id'           => 'required|string',
            'clinic_id'            => 'required|string',
            'doctor_id'            => 'required|string',
            'speciality'           => 'required|speciality',
            'children_appointment' => 'sometimes|bool|boolToString',
            'adult_appointment'    => 'sometimes|bool|boolToString',
            'house_call'           => 'sometimes|bool|boolToString',
            'telemed'              => 'sometimes|bool|boolToString',
            'is_base_service'      => 'sometimes|bool|boolToString',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
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
        };
    }
}
