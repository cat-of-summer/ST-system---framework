<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;

final class Doctor extends DefaultSchema
{
    protected static function getFields(): array
    {
        return [
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
            'education'           => [self::arrayOf('education'),   'sometimes'],
            'job'                 => [self::arrayOf('job'),         'sometimes'],
            'certificate'         => [self::arrayOf('certificate'), 'sometimes'],
            'reviews_total_count' => 'sometimes|int',
            'review'              => [self::arrayOf('review'),      'sometimes'],
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
            $internalId = $s->field('internal_id') ?? $s->field('id');
            $xml  = '<doctor id="' . self::xml($s->field('id')) . '">';
            $xml .= '<name>' . self::xml($s->field('name')) . '</name>';
            $xml .= '<url>' . self::xml($s->field('url')) . '</url>';

            if ($s->field('description') !== null) {
                $xml .= '<description>' . self::xml(trim($s->field('description'))) . '</description>';
            }
            $xml .= '<internal_id>' . self::xml($internalId) . '</internal_id>';
            if ($s->field('first_name') !== null) {
                $xml .= '<first_name>' . self::xml($s->field('first_name')) . '</first_name>';
            }
            if ($s->field('surname') !== null) {
                $xml .= '<surname>' . self::xml($s->field('surname')) . '</surname>';
            }
            if ($s->field('patronymic') !== null) {
                $xml .= '<patronymic>' . self::xml($s->field('patronymic')) . '</patronymic>';
            }
            if ($s->field('experience_years') !== null) {
                $xml .= '<experience_years>' . self::xml($s->field('experience_years')) . '</experience_years>';
            }
            if ($s->field('career_start_date') !== null) {
                $xml .= '<career_start_date>' . self::xml($s->field('career_start_date')) . '</career_start_date>';
            }
            if ($s->field('picture') !== null) {
                $xml .= '<picture>' . self::xml($s->field('picture')) . '</picture>';
            }
            if ($s->field('degree') !== null) {
                $xml .= '<degree>' . self::xml($s->field('degree')) . '</degree>';
            }
            if ($s->field('rank') !== null) {
                $xml .= '<rank>' . self::xml($s->field('rank')) . '</rank>';
            }
            if ($s->field('category') !== null) {
                $xml .= '<category>' . self::xml($s->field('category')) . '</category>';
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
                $xml .= '<reviews_total_count>' . self::xml($s->field('reviews_total_count')) . '</reviews_total_count>';
            }
            foreach ($s->field('review') ?? [] as $r) {
                $xml .= $r->print();
            }

            $xml .= '</doctor>';
            return $xml;
        };
    }
}
