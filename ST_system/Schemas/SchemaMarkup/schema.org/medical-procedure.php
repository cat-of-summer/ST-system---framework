<?php

/**
 * Схема микроразметки Schema.org — MedicalProcedure.
 *
 * Использование:
 *   require_once __DIR__ . '/medical-procedure.php';
 *
 *   $markup = Schema::create('schema.medical-procedure')->fill([
 *       'name'               => 'Липофилинг лица',
 *       'description'        => 'Трансплантация собственной жировой ткани пациента...',
 *       'procedure_type'     => 'SurgicalProcedure',
 *       'body_location'      => 'Лицо',
 *       'preparation'        => 'Общая или местная анестезия',
 *       'status'             => 'Active',
 *       'indication'         => [
 *           'наличие излишне глубоких носогубных складок',
 *           'едостаточное содержание жировых тканей в средней и нижней трети лица',
 *           'симметрия губ или скул',
 *           'возрастные изменения костных тканей',
 *       ],
 *       'contraindication'   => [
 *           'Сахарный диабет',
 *           'заболевания сердечно-сосудистой системы',
 *           'нарушение свертываемости крови',
 *       ],
 *       'expected_prognosis' => 'конечный результат оценивается через 1 месяц. эффект длительный при соблюдении рекомендаций.',
 *       'followup'           => 'ограничения на бассейн и холод 2-3 недели, отказ от алкоголя и тяжестей.',
 *   ]);
 *   echo $markup->print();
 */

use ST_system\Schema;

// ─────────────────────────────────────────────────────────────────────────────

Schema::scope('schema', function (): void {

    Schema::entity('medical-procedure', ['fields' => [
        'name'               => 'required|string',
        'description'        => 'sometimes|string',
        'procedure_type'     => 'sometimes|string',
        'body_location'      => 'sometimes|string',
        'preparation'        => 'sometimes|string',
        'status'             => 'sometimes|string',
        'indication'         => 'sometimes',
        'contraindication'   => 'sometimes',
        'expected_prognosis' => 'sometimes|string',
        'followup'           => 'sometimes|string',
        'how_performed'      => 'sometimes|string',
        'url'                => 'sometimes|url',
        'image'              => 'sometimes|url',
    ], 'print' => function (Schema $s): string {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'MedicalProcedure',
            'name'     => $s->field('name'),
        ];

        if ($s->field('description') !== null) {
            $data['description'] = $s->field('description');
        }

        if ($s->field('procedure_type') !== null) {
            $data['procedureType'] = $s->field('procedure_type');
        }

        if ($s->field('body_location') !== null) {
            $data['bodyLocation'] = $s->field('body_location');
        }

        if ($s->field('preparation') !== null) {
            $data['preparation'] = $s->field('preparation');
        }

        if ($s->field('status') !== null) {
            $data['status'] = $s->field('status');
        }

        if ($s->field('indication') !== null) {
            $data['indication'] = (array) $s->field('indication');
        }

        if ($s->field('contraindication') !== null) {
            $data['contraindication'] = (array) $s->field('contraindication');
        }

        if ($s->field('expected_prognosis') !== null) {
            $data['expectedPrognosis'] = $s->field('expected_prognosis');
        }

        if ($s->field('followup') !== null) {
            $data['followup'] = $s->field('followup');
        }

        if ($s->field('how_performed') !== null) {
            $data['howPerformed'] = $s->field('how_performed');
        }

        if ($s->field('url') !== null) {
            $data['url'] = $s->field('url');
        }

        if ($s->field('image') !== null) {
            $data['image'] = $s->field('image');
        }

        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }]);

});