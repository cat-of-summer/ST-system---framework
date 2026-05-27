<?php

namespace ST_system\Schemas\Yandex\MedicalFeed;

use ST_system\Schemas\DefaultSchema;
use ST_system\Rule;

final class Review extends DefaultSchema
{
    protected static function _init(): void
    {
        if (!Rule::get('boolToString')) {
            Rule::create(fn (&$v): bool => (bool)($v = $v ? 'true' : 'false'))
                ->alias('boolToString');
        }
    }

    protected static function getFields(): array
    {
        return [
            'date'           => 'required|string',
            'checked'        => 'sometimes|bool|boolToString',
            'used_in_rating' => 'sometimes|bool|boolToString',
            'author'         => 'required|string',
            'author_id'      => 'sometimes|string',
            'author_picture' => 'sometimes|url',
            'url'            => 'sometimes|url',
            'comment'        => 'required|string',
            'grade'          => 'sometimes|float',
            'positive'       => 'sometimes|string',
            'negative'       => 'sometimes|string',
            'response'       => 'sometimes|string',
        ];
    }

    protected static function getPrint(): \Closure
    {
        return function (DefaultSchema $s): string {
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
        };
    }
}
