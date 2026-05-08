<?php


use ST_system\Schema;


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
