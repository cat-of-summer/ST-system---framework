<?php

/**
 * Схема фида специалистов Яндекс.Врачи.
 *
 * Использование:
 *   require_once __DIR__ . '/yandex-medical-feed.php';
 *
 *   $feed = Schema::create('yandex-medical-feed')->fill([
 *       'name'    => 'АмберМед',
 *       'company' => 'ООО "АмберМед"',
 *       'url'     => 'https://example.com',
 *       'email'   => 'office@example.com',
 *       'doctors' => [
 *           [
 *               'id'               => '123',
 *               'name'             => 'Иванов Иван Иванович',
 *               'url'              => 'https://example.com/doctors/ivanov',
 *               'description'      => 'Опытный терапевт.',
 *               'surname'          => 'Иванов',
 *               'first_name'       => 'Иван',
 *               'patronymic'       => 'Иванович',
 *               'experience_years' => 10,
 *               'picture'          => 'https://example.com/images/ivanov.jpg',
 *           ],
 *       ],
 *       'clinics' => [
 *           [
 *               'id'      => '1',
 *               'name'    => 'Клиника Здоровья',
 *               'url'     => 'https://example.com',
 *               'city'    => 'г. Москва',
 *               'address' => 'ул. Примерная, д. 1',
 *               'phone'   => '+7 (495) 000-00-00',
 *           ],
 *       ],
 *       'services' => [
 *           [
 *               'id'   => '1',
 *               'name' => 'Первичный приём (терапевт)',
 *           ],
 *       ],
 *       'offers' => [
 *           [
 *               'id'                   => 'offer_1',
 *               'url'                  => 'https://example.com/appointment/?doctor=ivanov',
 *               'oms'                  => false,
 *               'online_schedule'      => false,
 *               'appointment'          => true,
 *               'price'                => [
 *                   'base_price'       => 2000,
 *                   'currency'         => 'RUB',
 *                   'discounts'        => [['name' => 'Клубная карта', 'amount' => 1500]],
 *                   'free_appointment' => 'При условии дальнейшего лечения',
 *               ],
 *               'doctor_id'            => '123',
 *               'clinic_id'            => '1',
 *               'service_id'           => '1',
 *               'speciality'           => 'терапевт',
 *               'children_appointment' => false,
 *               'adult_appointment'    => true,
 *               'is_base_service'      => true,
 *           ],
 *       ],
 *   ]);
 *   echo $feed->print();
 */

use ST_system\Schema;
use ST_system\Rule;

// ─────────────────────────────────────────────────────────────────────────────

Rule::create('bool')->after(function (&$v): void { $v = $v ? 'true' : 'false'; })->alias('boolToStr');

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

Schema::entity('yandex-medical-feed', ['fields' => [
    'date'     => 'date_format:Y-m-d H:i|default:' . date('Y-m-d H:i'),
    'name'     => 'required|string',
    'company'  => 'sometimes|string',
    'url'      => 'required|url',
    'picture'  => 'sometimes|url',
    'email'    => 'sometimes|string',
    'doctors'  => [Schema::arrayOf('doctor'),  'sometimes'],
    'clinics'  => [Schema::arrayOf('clinic'),  'sometimes'],
    'services' => [Schema::arrayOf('service'), 'sometimes'],
    'offers'   => [Schema::arrayOf('offer'),   'sometimes'],
], 'print' => function (Schema $s): string {
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
    },
])->namespace(function () {
    // ── Образование ───────────────────────────────────────────────────────
    Schema::entity('education', ['fields' => [
        'organization'   => 'required|string',
        'finish_year'    => 'sometimes|int',
        'type'           => 'sometimes|string',
        'specialization' => 'sometimes|string',
    ], 'print' => function (Schema $s): string {
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
        },
    ]);

    // ── Место работы ──────────────────────────────────────────────────────
    Schema::entity('job', ['fields' => [
        'organization' => 'required|string',
        'period_years' => 'sometimes|string',
        'position'     => 'sometimes|string',
    ], 'print' => function (Schema $s): string {
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
        },
    ]);

    // ── Сертификат ────────────────────────────────────────────────────────
    Schema::entity('certificate', ['fields' => [
        'organization' => 'required|string',
        'finish_year'  => 'sometimes|int',
        'name'         => 'required|string',
    ], 'print' => function (Schema $s): string {
            $xml  = '<certificate>';
            $xml .= '<organization>' . $s->field('organization') . '</organization>';
            if ($s->field('finish_year') !== null) {
                $xml .= '<finish_year>' . $s->field('finish_year') . '</finish_year>';
            }
            $xml .= '<name>' . $s->field('name') . '</name>';
            $xml .= '</certificate>';
            return $xml;
        },
    ]);

    // ── Отзыв ─────────────────────────────────────────────────────────────
    Schema::entity('review', ['fields' => [
        'date'           => 'required|string',
        'checked'        => 'sometimes|boolToStr',
        'used_in_rating' => 'sometimes|boolToStr',
        'author'         => 'required|string',
        'author_id'      => 'sometimes|string',
        'author_picture' => 'sometimes|url',
        'url'            => 'sometimes|url',
        'comment'        => 'required|string',
        'grade'          => 'sometimes|float',
        'positive'       => 'sometimes|string',
        'negative'       => 'sometimes|string',
        'response'       => 'sometimes|string',
    ], 'print' => function (Schema $s): string {
            $xml  = '<review>';
            $xml .= '<date>' . $s->field('date') . '</date>';
            if ($s->field('checked') !== null) {
                $xml .= '<checked>' . $s->field('checked') . '</checked>';
            }
            if ($s->field('used_in_rating') !== null) {
                $xml .= '<used_in_rating>' . $s->field('used_in_rating') . '</used_in_rating>';
            }
            $xml .= '<author>' . $s->field('author') . '</author>';
            if ($s->field('author_id') !== null) {
                $xml .= '<author_id>' . $s->field('author_id') . '</author_id>';
            }
            if ($s->field('author_picture') !== null) {
                $xml .= '<author_picture>' . $s->field('author_picture') . '</author_picture>';
            }
            if ($s->field('url') !== null) {
                $xml .= '<url>' . $s->field('url') . '</url>';
            }
            $xml .= '<comment>' . $s->field('comment') . '</comment>';
            if ($s->field('grade') !== null) {
                $xml .= '<grade>' . $s->field('grade') . '</grade>';
            }
            if ($s->field('positive') !== null) {
                $xml .= '<positive>' . $s->field('positive') . '</positive>';
            }
            if ($s->field('negative') !== null) {
                $xml .= '<negative>' . $s->field('negative') . '</negative>';
            }
            if ($s->field('response') !== null) {
                $xml .= '<response>' . $s->field('response') . '</response>';
            }
            $xml .= '</review>';
            return $xml;
        },
    ]);

    // ── Врач ──────────────────────────────────────────────────────────────
    Schema::entity('doctor', ['fields' => [
        'id'                  => 'required|string',
        'internal_id'         => 'sometimes|string',
        'name'                => 'required|string',
        'url'                 => 'required|url',
        'description'         => 'sometimes|string',
        'surname'             => 'sometimes|string',
        'first_name'          => 'sometimes|string',
        'patronymic'          => 'sometimes|string',
        'experience_years'    => 'sometimes|int',
        'career_start_date'   => 'sometimes|string',
        'picture'             => 'sometimes|url',
        'degree'              => 'sometimes|string',
        'rank'                => 'sometimes|string',
        'category'            => 'sometimes|string',
        'education'           => [Schema::arrayOf('education'),   'sometimes'],
        'job'                 => [Schema::arrayOf('job'),         'sometimes'],
        'certificate'         => [Schema::arrayOf('certificate'), 'sometimes'],
        'reviews_total_count' => 'sometimes|int',
        'review'              => [Schema::arrayOf('review'),      'sometimes'],
    ], 'print' => function (Schema $s): string {
            $internalId = $s->field('internal_id') ?? $s->field('id');
            $xml  = '<doctor id="' . $s->field('id') . '">';
            $xml .= '<name>' . $s->field('name') . '</name>';
            $xml .= '<url>' . $s->field('url') . '</url>';

            if ($s->field('description') !== null) {
                $xml .= '<description>' . trim($s->field('description')) . '</description>';
            }
            $xml .= '<internal_id>' . $internalId . '</internal_id>';
            if ($s->field('first_name') !== null) {
                $xml .= '<first_name>' . $s->field('first_name') . '</first_name>';
            }
            if ($s->field('surname') !== null) {
                $xml .= '<surname>' . $s->field('surname') . '</surname>';
            }
            if ($s->field('patronymic') !== null) {
                $xml .= '<patronymic>' . $s->field('patronymic') . '</patronymic>';
            }
            if ($s->field('experience_years') !== null) {
                $xml .= '<experience_years>' . $s->field('experience_years') . '</experience_years>';
            }
            if ($s->field('career_start_date') !== null) {
                $xml .= '<career_start_date>' . $s->field('career_start_date') . '</career_start_date>';
            }
            if ($s->field('picture') !== null) {
                $xml .= '<picture>' . $s->field('picture') . '</picture>';
            }
            if ($s->field('degree') !== null) {
                $xml .= '<degree>' . $s->field('degree') . '</degree>';
            }
            if ($s->field('rank') !== null) {
                $xml .= '<rank>' . $s->field('rank') . '</rank>';
            }
            if ($s->field('category') !== null) {
                $xml .= '<category>' . $s->field('category') . '</category>';
            }
            foreach ($s->field('education') ?? [] as $e) {
                $xml .= $e->print();
            }
            foreach ($s->field('job') ?? [] as $j) {
                $xml .= $j->print();
            }
            foreach ($s->field('certificate') ?? [] as $c) {
                $xml .= $c->print();
            }
            if ($s->field('reviews_total_count') !== null) {
                $xml .= '<reviews_total_count>' . $s->field('reviews_total_count') . '</reviews_total_count>';
            }
            foreach ($s->field('review') ?? [] as $r) {
                $xml .= $r->print();
            }

            $xml .= '</doctor>';
            return $xml;
        },
    ]);

    // ── Клиника ───────────────────────────────────────────────────────────
    Schema::entity('clinic', ['fields' => [
        'id'         => 'required|string',
        'internal_id'=> 'sometimes|string',
        'name'       => 'required|string',
        'url'        => 'required|url',
        'city'       => 'sometimes|string',
        'address'    => 'sometimes|string',
        'phone'      => 'sometimes|string',
        'email'      => 'sometimes|string',
        'picture'    => 'sometimes|url',
        'company_id' => 'sometimes|string',
    ], 'print' => function (Schema $s): string {
            $internalId = $s->field('internal_id') ?? $s->field('id');
            $xml  = '<clinic id="' . $s->field('id') . '">';
            $xml .= '<url>' . $s->field('url') . '</url>';

            if ($s->field('picture') !== null) {
                $xml .= '<picture>' . $s->field('picture') . '</picture>';
            }
            $xml .= '<name>' . $s->field('name') . '</name>';
            if ($s->field('city') !== null) {
                $xml .= '<city>' . $s->field('city') . '</city>';
            }
            if ($s->field('address') !== null) {
                $xml .= '<address>' . $s->field('address') . '</address>';
            }
            if ($s->field('email') !== null) {
                $xml .= '<email>' . $s->field('email') . '</email>';
            }
            if ($s->field('phone') !== null) {
                $xml .= '<phone>' . $s->field('phone') . '</phone>';
            }
            $xml .= '<internal_id>' . $internalId . '</internal_id>';
            if ($s->field('company_id') !== null) {
                $xml .= '<company_id>' . $s->field('company_id') . '</company_id>';
            }

            $xml .= '</clinic>';
            return $xml;
        },
    ]);

    // ── Услуга ────────────────────────────────────────────────────────────
    Schema::entity('service', ['fields' => [
        'id'          => 'required|string',
        'internal_id' => 'sometimes|string',
        'name'        => 'required|string',
        'gov_id'      => 'sometimes|string',
        'description' => 'sometimes|string',
    ], 'print' => function (Schema $s): string {
            $internalId = $s->field('internal_id') ?? $s->field('id');
            $xml  = '<service id="' . $s->field('id') . '">';
            $xml .= '<name>' . $s->field('name') . '</name>';

            if ($s->field('gov_id') !== null) {
                $xml .= '<gov_id>' . $s->field('gov_id') . '</gov_id>';
            }
            if ($s->field('description') !== null) {
                $xml .= '<description>' . trim($s->field('description')) . '</description>';
            }
            $xml .= '<internal_id>' . $internalId . '</internal_id>';

            $xml .= '</service>';
            return $xml;
        },
    ]);

    // ── Цена ──────────────────────────────────────────────────────────────
    Schema::entity('price', ['fields' => [
        'base_price'       => 'required|float',
        'currency'         => 'required|string',
        'discounts'        => ['sometimes', Rule::object(['name' => 'required|string', 'amount' => 'required|float'])],
        'free_appointment' => ['sometimes', Rule::forEach('string')],
    ], 'print' => function (Schema $s): string {
            $xml  = '<price>';
            $xml .= '<base_price>' . $s->field('base_price') . '</base_price>';
            $xml .= '<currency>' . $s->field('currency') . '</currency>';
            foreach ((array) ($s->field('discounts') ?? []) as $d) {
                $xml .= '<discount name="' . ($d['name'] ?? '') . '">' . ($d['amount'] ?? 0) . '</discount>';
            }
            foreach ((array) ($s->field('free_appointment') ?? []) as $fa) {
                $xml .= '<free_appointment>' . $fa . '</free_appointment>';
            }
            $xml .= '</price>';
            return $xml;
        },
    ]);

    // ── Оффер ─────────────────────────────────────────────────────────────
    Schema::entity('offer', ['fields' => [
        'id'                   => 'required|string',
        'url'                  => 'required|url',
        'oms'                  => 'sometimes|boolToStr',
        'online_schedule'      => 'sometimes|boolToStr',
        'appointment'          => 'sometimes|boolToStr',
        'price'                => 'sometimes|@price',
        'service_id'           => 'required|string',
        'clinic_id'            => 'required|string',
        'doctor_id'            => 'required|string',
        'speciality'           => 'required|speciality',
        'children_appointment' => 'sometimes|boolToStr',
        'adult_appointment'    => 'sometimes|boolToStr',
        'house_call'           => 'sometimes|boolToStr',
        'telemed'              => 'sometimes|boolToStr',
        'is_base_service'      => 'sometimes|boolToStr',
    ], 'print' => function (Schema $s): string {
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
        },
    ]);
});
